<?php

namespace App\Service;

use App\Entity\AiServiceRequest;
use App\Entity\AiServiceResponse;
use App\Service\AiServiceUseLogService;
use App\Entity\Spirit;
use App\Service\AiServiceRequestService;
use App\Service\AiServiceResponseService;
use App\Entity\SpiritConversation;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use App\CitadelVersion;
use App\Repository\UserRepository;
use App\Service\ProjectFileService;
use App\Service\AiToolService;
use Psr\Log\LoggerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

class SpiritConversationService
{
    private $user;
    
    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly AiGatewayService $aiGatewayService,
        private readonly AiServiceRequestService $aiServiceRequestService,
        private readonly AiServiceResponseService $aiServiceResponseService,
        private readonly AiServiceUseLogService $aiServiceUseLogService,
        private readonly SpiritService $spiritService,
        private readonly AiServiceModelService $aiServiceModelService,
        private readonly SettingsService $settingsService,
        private readonly Security $security,
        private readonly CitadelVersion $citadelVersion,
        private readonly UserRepository $userRepository,
        private readonly ProjectFileService $projectFileService,
        private readonly AiToolService $aiToolService,
        private readonly AIToolCallService $aiToolCallService,
        private readonly CQMemoryPackService $packService,
        private readonly CQMemoryLibraryService $libraryService,
        private readonly LoggerInterface $logger,
        private readonly AnnoService $annoService,
        private readonly SluggerInterface $slugger
    ) {
        $this->user = $security->getUser();
    }
    
    /**
     * Get a fresh database connection for the current user
     */
    private function getUserDb()
    {
        return $this->userDatabaseManager->getDatabaseConnection($this->user);
    }
    
    public function createConversation(string $spiritId, string $title): SpiritConversation
    {
        $db = $this->getUserDb();
        
        // Create a new conversation
        $conversation = new SpiritConversation($spiritId, $title);
        
        // Insert into database
        $db->executeStatement(
            'INSERT INTO spirit_conversation (id, spirit_id, title, messages, created_at, last_interaction) VALUES (?, ?, ?, ?, ?, ?)',
            [
                $conversation->getId(),
                $conversation->getSpiritId(),
                $conversation->getTitle(),
                $conversation->getMessages() ? json_encode($conversation->getMessages()) : '[]',
                $conversation->getCreatedAt()->format('Y-m-d H:i:s'),
                $conversation->getLastInteraction()->format('Y-m-d H:i:s')
            ]
        );
        
        return $conversation;
    }
    
    public function getConversation(string $conversationId): ?SpiritConversation
    {
        $db = $this->getUserDb();
        
        $result = $db->executeQuery('SELECT * FROM spirit_conversation WHERE id = ?', [$conversationId]);
        $data = $result->fetchAssociative();
        if (!$data) {
            return null;
        }
        
        // Check if conversation needs migration from old format
        $messageCount = $db->executeQuery(
            'SELECT COUNT(*) FROM spirit_conversation_message WHERE conversation_id = ?',
            [$conversationId]
        )->fetchOne();
        
        if ($messageCount == 0 && !empty($data['messages'])) {
            // Old format detected - migrate on-the-fly
            $this->migrateConversationToNewFormat($conversationId, $data['messages']);
        }
        
        return SpiritConversation::fromArray($data);
    }

    public function getAllConversations(): array
    {
        $db = $this->getUserDb();
        
        $result = $db->executeQuery(
            'SELECT id, spirit_id, title, created_at, last_interaction FROM spirit_conversation ORDER BY last_interaction DESC'
        );
        $results = $result->fetchAllAssociative();
        
        return array_map(fn($data) => SpiritConversation::fromArray($data), $results);
    }

    public function getConversationTokens(string $conversationId): array
    {
        $db = $this->getUserDb();
        
        // ai_service_use_log.ai_service_request_id = spirit_conversation_request.ai_service_request_id
        $result = $db->executeQuery("SELECT 
                SUM(ai_service_use_log.total_tokens) AS total_tokens,
                SUM(ai_service_use_log.input_tokens) AS input_tokens,
                SUM(ai_service_use_log.output_tokens) AS output_tokens
            FROM 
                ai_service_use_log 
            WHERE 
                ai_service_use_log.ai_service_request_id IN (SELECT ai_service_request_id FROM spirit_conversation_request WHERE spirit_conversation_id = ?)
        ", [$conversationId]);
        $data = $result->fetchAssociative();

        // if 0, then maybe it's other data format/structure
        if ($data['total_tokens'] == 0) {
            $result = $db->executeQuery("SELECT 
                    SUM(ai_service_use_log.total_tokens) AS total_tokens,
                    SUM(ai_service_use_log.input_tokens) AS input_tokens,
                    SUM(ai_service_use_log.output_tokens) AS output_tokens
                FROM 
                    ai_service_use_log 
                WHERE 
                    ai_service_use_log.ai_service_request_id IN (SELECT ai_service_request_id FROM spirit_conversation_message WHERE conversation_id = ?)
            ", [$conversationId]);
            $data = $result->fetchAssociative();
        }

        return [
            'total_tokens' => $data['total_tokens'] ?? 0,
            'input_tokens' => $data['input_tokens'] ?? 0,
            'output_tokens' => $data['output_tokens'] ?? 0,
            'total_tokens_formatted' => number_format($data['total_tokens'] ?? 0),
            'input_tokens_formatted' => number_format($data['input_tokens'] ?? 0),
            'output_tokens_formatted' => number_format($data['output_tokens'] ?? 0)
        ];
    }

    public function getConversationPrice(string $conversationId): array
    {
        $db = $this->getUserDb();
        
        // ai_service_use_log.ai_service_request_id = spirit_conversation_request.ai_service_request_id
        // ai_service_use_log.total_price
        $result = $db->executeQuery("SELECT 
                SUM(ai_service_use_log.total_price) AS total_price,
                SUM(ai_service_use_log.input_price) AS input_price,
                SUM(ai_service_use_log.output_price) AS output_price
            FROM 
                ai_service_use_log 
            WHERE 
                ai_service_use_log.ai_service_request_id IN (SELECT ai_service_request_id FROM spirit_conversation_request WHERE spirit_conversation_id = ?)
        ", [$conversationId]);
        $data = $result->fetchAssociative();

        // if 0, then maybe it's other data format/structure
        if ($data['total_price'] == 0) {
            $result = $db->executeQuery("SELECT 
                SUM(ai_service_use_log.total_price) AS total_price,
                SUM(ai_service_use_log.input_price) AS input_price,
                SUM(ai_service_use_log.output_price) AS output_price
            FROM 
                ai_service_use_log 
            WHERE 
                ai_service_use_log.ai_service_request_id IN (SELECT ai_service_request_id FROM spirit_conversation_message WHERE conversation_id = ?)
        ", [$conversationId]);
        $data = $result->fetchAssociative();
        }
        
        return [
            'total_price' => $data['total_price'] ?? 0,
            'input_price' => $data['input_price'] ?? 0,
            'output_price' => $data['output_price'] ?? 0,
            'total_price_formatted' => number_format($data['total_price'] ?? 0, 2),
            'input_price_formatted' => number_format($data['input_price'] ?? 0, 2),
            'output_price_formatted' => number_format($data['output_price'] ?? 0, 2)
        ];
    }

    public function findById(string $conversationId): ?SpiritConversation
    {
        return $this->getConversation($conversationId);
    }

    /**
     * Get human-readable size
     */
    public function getFormattedSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        } elseif ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        } else {
            return round($bytes / 1048576, 1) . ' MB';
        }
    }
    
    public function getConversationsBySpirit(string $spiritId): array
    {
        $db = $this->getUserDb();
        
        // Use LENGTH() to get byte size of messages column (works in SQLite)
        $result = $db->executeQuery(
            'SELECT id, spirit_id, title, created_at, last_interaction, LENGTH(messages) as sizeInBytes FROM spirit_conversation WHERE spirit_id = ? ORDER BY last_interaction DESC', 
            [$spiritId]
        );
        $results = $result->fetchAllAssociative();
        
        $conversations = [];
        foreach ($results as $data) {
            // Check if conversation uses new message table format
            $messageCount = $db->executeQuery(
                'SELECT COUNT(*) FROM spirit_conversation_message WHERE conversation_id = ?',
                [$data['id']]
            )->fetchOne();
            
            $isNewFormat = $messageCount > 0;
            
            if ($isNewFormat) {
                // New format: Calculate from message table
                $messagesCount = $messageCount;
            } else {
                // Old format: Calculate from JSON array
                $messagesJson = $db->executeQuery('SELECT messages FROM spirit_conversation WHERE id = ?', [$data['id']])->fetchOne();
                $messagesArray = json_decode($messagesJson, true) ?? [];
                $messagesCount = count($messagesArray);
            }
            
            // Calculate images count and size
            $imagesCount = 0;
            $toolsCount = 0;
            $sizeInBytes = 0;
            $lastMsgUsage = [];
            
            if ($isNewFormat) {
                // New format: Count from message table
                $messages = $db->executeQuery(
                    'SELECT content, LENGTH(content) as size, type as response_type, ai_service_request_id FROM spirit_conversation_message WHERE conversation_id = ? ORDER BY created_at DESC',
                    [$data['id']]
                )->fetchAllAssociative();
                
                $i = 0;
                foreach ($messages as $msg) {
                    $sizeInBytes += (int)$msg['size'];
                    $content = json_decode($msg['content'], true);
                    
                    // Count images in content
                    if (is_array($content)) {
                        foreach ($content as $item) {
                            if (isset($item['type']) && strpos($item['type'], 'image') !== false) {
                                $imagesCount++;
                            }
                            if (isset($item['text'])) {
                                $imagesCount += substr_count($item['text'], '<img ');
                            }
                        }
                    }

                    // Count tools count
                    if ($msg['response_type'] == 'tool_use') {
                        $toolsCount++;
                    }

                    // Get last message usage
                    if ($lastMsgUsage == [] && isset($msg['ai_service_request_id']) && $msg['ai_service_request_id'] != null) {
                        $lastMsgUsage = $this->aiServiceUseLogService->getUsageByRequestId($msg['ai_service_request_id']);
                    }
                    $i++;
                }
            } else {
                // Old format: Calculate from JSON array
                $messagesArray = $messagesArray ?? [];
                $sizeInBytes = (int)$data['sizeInBytes'];
                
                $imagesCount = count(array_filter($messagesArray, function($message) {
                    $content = $message['content'] ?? [];
                    if (is_array($content) && count($content) > 0) {
                        foreach ($content as $item) {
                            if (isset($item['type']) && strpos($item['type'], 'image') !== false) {
                                return true;
                            }
                        }
                    }
                    return false;
                }));
                
                foreach ($messagesArray as $message) {
                    $content = $message['content'] ?? [];
                    if (is_array($content)) {
                        if (count($content) > 0) {
                            foreach ($content as $item) {
                                if (isset($item['text'])) {
                                    $imagesCount += substr_count($item['text'], '<img ');
                                }
                            }
                        }
                    } else {
                        $imagesCount += substr_count($content, '<img ');
                    } 
                }
            }
            
            $conversation = [
                'id' => $data['id'],
                'spiritId' => $data['spirit_id'],
                'title' => $data['title'],
                'createdAt' => $data['created_at'],
                'lastInteraction' => $data['last_interaction'],
                'messagesCount' => $messagesCount,
                'imagesCount' => $imagesCount,
                'toolsCount' => $toolsCount,
                'sizeInBytes' => $sizeInBytes,
                'formattedSize' => $this->getFormattedSize($sizeInBytes),
                'tokens' => $this->getConversationTokens($data['id']),
                'price' => $this->getConversationPrice($data['id']),
                'lastMsgUsage' => $lastMsgUsage
            ];
            $conversations[] = $conversation;
        }

        // TMP, until next few releases
        // migration from single-spirit memory files to multi-spirit memory files
        $spirit = $this->spiritService->getSpirit($spiritId);
        if ($spirit) {
            $spiritNameSlug = $this->slugger->slug($spirit->getName());
            $spiritMemoryDir = '/spirit/' . $spiritNameSlug . '/memory';
            $this->migrateMemoryFiles($spirit, '/spirit/memory', $spiritMemoryDir);
        }
        //
        
        return $conversations;
    }
    
    public function updateConversation(SpiritConversation $conversation): void
    {
        $db = $this->getUserDb();
        
        $db->executeStatement(
            'UPDATE spirit_conversation SET title = ?, messages = ?, last_interaction = ? WHERE id = ?',
            [
                $conversation->getTitle(),
                json_encode($conversation->getMessages()),
                $conversation->getLastInteraction()->format('Y-m-d H:i:s'),
                $conversation->getId()
            ]
        );
    }
    
    public function deleteConversation(string $conversationId): void
    {
        $db = $this->getUserDb();

        // delete
        $db->beginTransaction();

            // delete all ai_service_request related to spirit_conversation_request
            $db->executeStatement(
                'DELETE FROM ai_service_request WHERE id IN (SELECT ai_service_request_id FROM spirit_conversation_request WHERE spirit_conversation_id = ?)',
                [$conversationId]
            );
            // delete all ai_service_response related to ai_service_request (related to spirit_conversation_request)
            $db->executeStatement(
                'DELETE FROM ai_service_response WHERE ai_service_request_id IN (SELECT ai_service_request_id FROM spirit_conversation_request WHERE spirit_conversation_id = ?)',
                [$conversationId]
            );

            // delete all spirit_conversation_request related to conversation
            $db->executeStatement(
                'DELETE FROM spirit_conversation_request WHERE spirit_conversation_id = ?',
                [$conversationId]
            );

            // delete the conversation
            $db->executeStatement(
                'DELETE FROM spirit_conversation WHERE id = ?',
                [$conversationId]
            );

        $db->commit();

        // Vacuum the database
        //$db->executeStatement('VACUUM;');
    }

    public function getConversationsCount(): int
    {
        $db = $this->getUserDb();
        
        $result = $db->executeQuery('SELECT COUNT(*) FROM spirit_conversation');
        $count = intval($result->fetchOne());
        
        return $count;
    }

    public function saveFilesFromMessage(array $message, string $projectId = 'general', string $path = '/uploads'): array
    {
        if (!is_array($message['content'])) {
            return [];
        }
        /* message data structure:

            {
                "content": [
                    {
                        "text": "fuuuh, super, spojenie funguje :) Robil som totiž zmenu na CQ AI Gateway, kvôli PDF súborom, no stále to nejak hapruje... Posielam ti teraz testovací PDF súbor. Prišiel ti? Aký má obsah?",
                        "type": "text"
                    },
                    {
                        "file": {
                            "file_data": "data:application/pdf;base64,....",
                            "filename": "test.pdf"
                        }
                    },
                    {
                        "file": {
                            "file_data": "data:application/pdf;base64,....",
                            "filename": "test-2.pdf"
                        }
                    }
                ]
            }
            
        */

        $newFiles = [];

        foreach ($message['content'] as $content) {
            if (is_array($content) && 
                isset($content['file']) && 
                is_array($content['file']) && 
                isset($content['file']['file_data']) && 
                isset($content['file']['filename'])) {

                $filePath = $path;
                try {
                    $newFile = $this->projectFileService->createFile($projectId, $filePath, $content['file']['filename'], $content['file']['file_data']);
                    $newFiles[] = $newFile;
                } catch (\Exception $e) {
                    // catch existing file 
                    if (strpos($e->getMessage(), 'File already exists') !== false) {                        
                        $newFile = $this->projectFileService->findByPathAndName($projectId, $filePath, $content['file']['filename']);
                        $newFiles[] = $newFile;
                    } else {
                        $this->logger->error('saveFilesFromMessage(): Error creating file: ' . $e->getMessage());
                    }
                }
            }
        }

        return $newFiles;
    }

    /**
     * Pre-process recall: run Reflexes (keyword extraction + FTS5 search),
     * build system prompt with recalled memories, return recalled node data.
     * The built system prompt is returned for caching in session.
     * 
     * Phase 4: Also evaluates whether the Subconsciousness sub-agent should be triggered.
     * 
     * @return array ['systemPrompt' => string, 'recalledNodes' => array, 'keywords' => array, 'packInfo' => array, 'shouldTriggerSubAgent' => bool]
     */
    public function preProcessRecall(
        string $conversationId,
        string $userMessageText,
        string $lang = 'English'
    ): array {
        // Get conversation + spirit
        $conversation = $this->getConversation($conversationId);
        if (!$conversation) {
            throw new \Exception('Conversation not found');
        }
        
        $spirit = $this->spiritService->getSpirit($conversation->getSpiritId());
        if (!$spirit) {
            throw new \Exception('Spirit not found');
        }
        
        // Build base system prompt
        $systemMessage = $this->buildSystemMessage($spirit, $lang);
        
        // Run recall
        $config = $this->getPromptConfig($spirit->getId());
        $recalledNodes = [];
        $keywords = [];
        $packInfo = [];
        
        $rootNodes = [];
        $graphData = null;
        $searchedPacks = [];
        $packsToSearch = [];
        if ($config['includeMemory'] && $config['memoryType'] >= 1 && !empty($userMessageText)) {
            // Get existing conversation messages for context enrichment
            $messageService = new SpiritConversationMessageService(
                $this->userDatabaseManager,
                $this->security,
                $this->logger
            );
            $messages = $messageService->getMessagesByConversation($conversationId);
            
            // Run recall and get both XML and node data
            $recallResult = $this->buildRecalledMemoriesSectionWithData($spirit, $userMessageText, $messages);
            
            if (!empty($recallResult['xml'])) {
                $systemMessage .= $recallResult['xml'];
            }
            $recalledNodes = $recallResult['nodes'] ?? [];
            $keywords = $recallResult['keywords'] ?? [];
            $packInfo = $recallResult['packInfo'] ?? [];
            $rootNodes = $recallResult['rootNodes'] ?? [];
            $graphData = $recallResult['graphData'] ?? null;
            $searchedPacks = $recallResult['searchedPacks'] ?? [];
            $packsToSearch = $recallResult['packsToSearch'] ?? [];
        }
        
        // Phase 4: Evaluate sub-agent trigger
        $shouldTriggerSubAgent = $this->shouldTriggerSubAgent($userMessageText, $recalledNodes, $config);
        
        return [
            'systemPrompt' => $systemMessage,
            'recalledNodes' => $recalledNodes,
            'keywords' => $keywords,
            'packInfo' => $packInfo,
            'rootNodes' => $rootNodes,
            'graphData' => $graphData,
            'searchedPacks' => $searchedPacks,
            'packsToSearch' => $packsToSearch,
            'shouldTriggerSubAgent' => $shouldTriggerSubAgent,
        ];
    }
    
    /**
     * Phase 4: Determine whether the Subconsciousness sub-agent should be triggered.
     * 
     * Aggressive strategy: trigger on every non-trivial message that has recalled nodes.
     * Cost per call is negligible (~0.7 Credit), so we maximize the "magic" experience.
     */
    private function shouldTriggerSubAgent(
        string $userMessageText,
        array $recalledNodes,
        array $config
    ): bool {
        // Master toggle: includeMemory must be on, memoryType must be Memory Agent (2)
        if (!$config['includeMemory'] || $config['memoryType'] !== 2) {
            return false;
        }
        
        // No recalled nodes = nothing for sub-agent to evaluate
        if (empty($recalledNodes)) {
            return false;
        }
        
        // TODO: Testing hack — always trigger sub-agent for Memory Agent mode. Remove after testing.
        return true;
        
        // --- Smart trigger logic (restore after testing) ---
        // Skip trivial messages
        $trimmed = trim($userMessageText);
        $wordCount = str_word_count($trimmed);
        
        // Too short (< 3 words): "hi", "ok", "thanks", emoji-only
        if ($wordCount < 3) {
            return false;
        }
        
        // Greeting/trivial patterns
        $trivialPatterns = [
            '/^(hi|hello|hey|hej|ahoj|cau|nazdar)\b/i',
            '/^(thanks?|thank you|ok|okay|sure|cool|nice)\b/i',
            '/^[\p{So}\p{Sk}\s]+$/u',  // emoji-only
        ];
        foreach ($trivialPatterns as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                return false;
            }
        }
        
        // All checks passed — trigger sub-agent
        return true;
    }

    /**
     * Send message (returns immediately without executing tools)
     * 
     * @return array Contains message entity, type, toolCalls, and requiresToolExecution flag
     */
    public function sendMessageAsync(
        string $conversationId,
        \App\Entity\SpiritConversationMessage $userMessage,
        string $lang = 'English',
        int $maxOutput = 500,
        float $temperature = 0.7,
        ?string $cachedSystemPrompt = null
    ): array {
        $db = $this->getUserDb();
        
        // Get conversation
        $conversation = $this->getConversation($conversationId);
        if (!$conversation) {
            throw new \Exception('Conversation not found');
        }
        
        // Get spirit
        $spirit = $this->spiritService->getSpirit($conversation->getSpiritId());
        if (!$spirit) {
            throw new \Exception('Spirit not found');
        }
        
        // Get AI service model - use spirit-specific model if set, otherwise fall back to primary
        $aiServiceModel = $this->spiritService->getSpiritAiModel($spirit->getId());
        
        // Prepare messages for AI request (from message table)
        $messages = $this->prepareMessagesForAiRequestFromMessageTable($conversationId, $spirit, $lang, $cachedSystemPrompt);
        
        // Add tools
        $tools = $this->aiGatewayService->getAvailableTools($aiServiceModel->getAiGatewayId());
        
        // Create AI service request
        $aiServiceRequest = $this->aiServiceRequestService->createRequest(
            $aiServiceModel->getId(),
            $messages,
            $maxOutput,
            $temperature,
            null,
            $tools
        );
        
        // Send to AI (non-blocking - handleToolCalls = false)
        $aiServiceResponse = $this->aiGatewayService->sendRequest(
            $aiServiceRequest,
            'Spirit Conversation (Async)',
            $lang,
            'general',
            false  // Don't handle tool calls
        );
        
        // Determine response type based on finish_reason
        $responseType = $this->determineResponseType($aiServiceResponse);
        
        // Create assistant message
        $messageService = new SpiritConversationMessageService(
            $this->userDatabaseManager,
            $this->security,
            $this->logger
        );
        
        // For tool_use messages, store the full message object (including tool_calls)
        // For regular messages, store content as array
        $fullResponse = $aiServiceResponse->getFullResponse();
        if ($responseType === 'tool_use' && isset($fullResponse['choices'][0]['message'])) {
            // Store the entire message object for tool_use
            $messageContent = $fullResponse['choices'][0]['message'];
            
            // Normalize empty content string to null (array vs string demon!)
            if (isset($messageContent['content']) && $messageContent['content'] === '') {
                $messageContent['content'] = null;
            }
        } else {
            // Get content from AI response and ensure it's an array
            $messageContent = $aiServiceResponse->getMessage()['content'] ?? [];
            if (!is_array($messageContent)) {
                $messageContent = [['type' => 'text', 'text' => $messageContent]];
            }
        }
        
        $assistantMessage = $messageService->createMessage(
            $conversationId,
            'assistant',
            $responseType,
            $messageContent,
            $userMessage->getId()
        );
        
        // Link to AI request/response
        $assistantMessage->setAiServiceRequestId($aiServiceRequest->getId());
        $assistantMessage->setAiServiceResponseId($aiServiceResponse->getId());
        $messageService->updateMessage($assistantMessage);
        
        // Update conversation last_interaction
        $conversation->setLastInteraction(new \DateTime());
        $this->updateConversation($conversation);

        // Update spirit experience
        $this->spiritService->logInteraction($spirit->getId(), 'conversation', 1);
        
        // Extract tool calls if present
        $toolCalls = $this->extractToolCalls($aiServiceResponse);

        // Remove message content form aiServiceRequest, aiServiceResponse from all messages in conversation
        //$this->setMessagesRemovedFromAiServiceRequestAndResponse($conversationId);
        
        return [
            'message' => $assistantMessage,
            'type' => $responseType,
            'toolCalls' => $toolCalls,
            'requiresToolExecution' => $responseType === 'tool_use'
        ];
    }
    
    /**
     * Execute tools asynchronously and get AI's next response
     * 
     * @return array Contains message entity, type, toolCalls, toolResults, and requiresToolExecution flag
     */
    public function executeToolsAsync(
        string $conversationId,
        string $assistantMessageId,
        array $toolCalls,
        string $lang = 'English',
        int $maxOutput = 500,
        float $temperature = 0.7
    ): array {
        $db = $this->getUserDb();
        
        // Get conversation
        $conversation = $this->getConversation($conversationId);
        if (!$conversation) {
            throw new \Exception('Conversation not found');
        }
        
        // Get spirit
        $spirit = $this->spiritService->getSpirit($conversation->getSpiritId());
        $spiritSlug = (string) $this->slugger->slug($spirit->getName());
        $spiritId = $spirit->getId();
        
        // Execute tools
        $toolResults = $this->executeToolCallsFromArray($toolCalls, $lang, $spiritSlug, $spiritId);
        
        // Create tool result message
        $messageService = new SpiritConversationMessageService(
            $this->userDatabaseManager,
            $this->security,
            $this->logger
        );
        
        $toolResultMessage = $messageService->createMessage(
            $conversationId,
            'tool',
            'tool_result',
            $toolResults,
            $assistantMessageId
        );
        
        // Get AI service model - use spirit-specific model if set, otherwise fall back to primary
        $aiServiceModel = $this->spiritService->getSpiritAiModel($spirit->getId());
        
        // Prepare messages including tool results
        $messages = $this->prepareMessagesForAiRequestFromMessageTable($conversationId, $spirit, $lang);
        
        // Add tools
        $tools = $this->aiGatewayService->getAvailableTools($aiServiceModel->getAiGatewayId());
        
        // Create new AI request
        $aiServiceRequest = $this->aiServiceRequestService->createRequest(
            $aiServiceModel->getId(),
            $messages,
            $maxOutput,
            $temperature,
            null,
            $tools
        );
        
        // Send to AI (non-blocking)
        $aiServiceResponse = $this->aiGatewayService->sendRequest(
            $aiServiceRequest,
            'Spirit Conversation Tool Response (Async)',
            $lang,
            'general',
            false  // Don't handle tool calls
        );
        
        // Determine response type
        $responseType = $this->determineResponseType($aiServiceResponse);
        
        // For tool_use messages, store the full message object (including tool_calls)
        // For regular messages, store content as array
        $fullResponse = $aiServiceResponse->getFullResponse();
        if ($responseType === 'tool_use' && isset($fullResponse['choices'][0]['message'])) {
            // Store the entire message object for tool_use
            $messageContent = $fullResponse['choices'][0]['message'];
            
            // Normalize empty content string to null (array vs string demon!)
            if (isset($messageContent['content']) && $messageContent['content'] === '') {
                $messageContent['content'] = null;
            }
        } else {
            // Get content from AI response and ensure it's an array
            $messageContent = $aiServiceResponse->getMessage()['content'] ?? [];
            if (!is_array($messageContent)) {
                $messageContent = [['type' => 'text', 'text' => $messageContent]];
            }
        }
        
        // Create assistant message
        $assistantMessage = $messageService->createMessage(
            $conversationId,
            'assistant',
            $responseType,
            $messageContent,
            $toolResultMessage->getId()
        );
        
        // Link to AI request/response
        $assistantMessage->setAiServiceRequestId($aiServiceRequest->getId());
        $assistantMessage->setAiServiceResponseId($aiServiceResponse->getId());
        $messageService->updateMessage($assistantMessage);
        
        // Update conversation last_interaction
        $conversation->setLastInteraction(new \DateTime());
        $this->updateConversation($conversation);
        
        // Update spirit experience
        if ($toolCalls !== null) {
            $toolCalls_function_names = array_map(function ($toolCall) {
                if (isset($toolCall['function']) && isset($toolCall['function']['name'])) {
                    return $toolCall['function']['name'];
                }
                return 'Unknown';
            }, $toolCalls);
            $this->spiritService->logInteraction($spirit->getId(), 'tool use: ' . implode(', ', $toolCalls_function_names), 2);            
        }
        
        // Extract tool calls if present
        $newToolCalls = $this->extractToolCalls($aiServiceResponse);
        
        return [
            'message' => $assistantMessage,
            'type' => $responseType,
            'toolCalls' => $newToolCalls,
            'toolResults' => $toolResults,
            'requiresToolExecution' => $responseType === 'tool_use'
        ];
    }
    
    /**
     * Determine response type from AI service response
     * Uses finish_reason: 'stop', 'tool_use', 'length', 'content_filter'
     */
    private function determineResponseType(AiServiceResponse $response): string
    {
        $finishReason = $response->getFinishReason();
        
        // Map finish_reason to message type
        if ($finishReason === 'tool_use' || $finishReason === 'tool_calls') {
            return 'tool_use';
        } elseif ($finishReason === 'length') {
            return 'length';
        } elseif ($finishReason === 'content_filter') {
            return 'content_filter';
        } elseif ($finishReason === 'stop') {
            return 'stop';
        }
        
        // Default to stop if unknown
        return 'stop';
    }

    public function setMessagesRemovedFromAiServiceRequestAndResponse(?string $conversationId = null): void
    {
        $db = $this->getUserDb();
        
        if ($conversationId) {
            // Update for specific conversation
            $db->executeStatement(
                'UPDATE ai_service_request SET messages = "removed" 
                 WHERE id IN (SELECT ai_service_request_id FROM spirit_conversation_message WHERE conversation_id = ? AND ai_service_request_id IS NOT NULL)',
                [$conversationId]
            );
            $db->executeStatement(
                'UPDATE ai_service_response SET message = "removed", full_response = "removed" 
                 WHERE id IN (SELECT ai_service_response_id FROM spirit_conversation_message WHERE conversation_id = ? AND ai_service_response_id IS NOT NULL)',
                [$conversationId]
            );
        } else {
            // Update ALL messages (for vacuum/cleanup operations)
            $db->executeStatement(
                'UPDATE ai_service_request SET messages = "removed", tools = "removed"'
            );
            $db->executeStatement(
                'UPDATE ai_service_response SET message = "removed", full_response = "removed"'
            );
        }
    }
    
    /**
     * Extract tool calls from AI service response
     */
    private function extractToolCalls(AiServiceResponse $response): ?array
    {
        $fullResponse = $response->getFullResponse();
        
        // Check for tool_calls in response (OpenAI/CQ AI Gateway format)
        if (isset($fullResponse['choices'][0]['message']['tool_calls'])) {
            return $fullResponse['choices'][0]['message']['tool_calls'];
        }
        
        // Check for tool_use in content (Anthropic format)
        if (isset($fullResponse['content']) && is_array($fullResponse['content'])) {
            $toolUses = array_filter($fullResponse['content'], fn($item) => 
                isset($item['type']) && $item['type'] === 'tool_use'
            );
            if (!empty($toolUses)) {
                return array_values($toolUses);
            }
        }
        
        return null;
    }
    
    /**
     * Execute tool calls from array format
     * 
     * @param array $toolCalls Tool calls to execute
     * @param string $lang Language for tool responses
     * @param string|null $spiritSlug Spirit's name slug for access control
     * @param string|null $spiritId Spirit's ID for memory tools context
     */
    private function executeToolCallsFromArray(array $toolCalls, string $lang, ?string $spiritSlug = null, ?string $spiritId = null): array
    {
        $results = [];
        
        foreach ($toolCalls as $toolCall) {
            // Handle different formats (OpenAI vs Anthropic)
            $toolName = $toolCall['function']['name'] ?? $toolCall['name'] ?? null;
            $toolArgs = $toolCall['function']['arguments'] ?? $toolCall['input'] ?? [];
            
            if (!$toolName) {
                continue;
            }
            
            // Parse arguments if string
            if (is_string($toolArgs)) {
                $toolArgs = json_decode($toolArgs, true) ?? [];
            }
            
            // Add Spirit slug for access control (used by file tools)
            if ($spiritSlug) {
                $toolArgs['_spiritSlug'] = $spiritSlug;
            }
            
            // Add Spirit ID for memory tools context (IMPORTANT: ensures correct spirit association)
            if ($spiritId) {
                $toolArgs['spiritId'] = $spiritId;
            }
            
            // Execute tool
            $toolResult = $this->aiToolCallService->executeTool($toolName, $toolArgs, $lang);
            
            // Extract frontendData if present (for UI display)
            $frontendData = null;
            if (isset($toolResult['_frontendData'])) {
                $frontendData = $toolResult['_frontendData'];
                // Remove from result before sending to AI
                unset($toolResult['_frontendData']);
            }
            
            // Format result based on provider
            if (isset($toolCall['function'])) {
                // OpenAI format
                $result = [
                    'tool_call_id' => $toolCall['id'],
                    'role' => 'tool',
                    'name' => $toolName,
                    'content' => json_encode($toolResult)
                ];
                
                // Add frontendData separately
                if ($frontendData) {
                    $result['frontendData'] = $frontendData;
                }
                
                $results[] = $result;
            } else {
                // Anthropic format
                $result = [
                    'type' => 'tool_result',
                    'tool_use_id' => $toolCall['id'],
                    'content' => json_encode($toolResult)
                ];
                
                // Add frontendData separately
                if ($frontendData) {
                    $result['frontendData'] = $frontendData;
                }
                
                $results[] = $result;
            }
        }
        
        return $results;
    }
    
    /**
     * Prepare messages for AI request from message table
     * Similar to prepareMessagesForAiRequest but loads from spirit_conversation_message table
     */
    private function prepareMessagesForAiRequestFromMessageTable(
        string $conversationId,
        Spirit $spirit,
        string $lang,
        ?string $cachedSystemPrompt = null
    ): array {
        // Get message service
        $messageService = new SpiritConversationMessageService(
            $this->userDatabaseManager,
            $this->security,
            $this->logger
        );
        
        // Get all messages from conversation
        $messages = $messageService->getMessagesByConversation($conversationId);
        
        // Build AI messages array
        $aiMessages = [];
        
        if ($cachedSystemPrompt !== null) {
            // Use pre-built system prompt from pre-send step (Phase 3.5)
            $systemMessage = $cachedSystemPrompt;
            $this->logger->debug('Using cached system prompt from pre-send ({length} chars)', [
                'length' => strlen($cachedSystemPrompt)
            ]);
        } else {
            // Build system prompt normally (fallback if pre-send was skipped)
            // Extract latest user message text for memory recall
            $latestUserMessageText = $this->extractLatestUserMessageText($messages);
            
            // Add system message (same as existing implementation)
            $systemMessage = $this->buildSystemMessage($spirit, $lang);
            
            // Append recalled memories based on latest user message (Tier 1: SQL Reflexes + Phase 3: Smart Triggering)
            $config = $this->getPromptConfig($spirit->getId());
            if ($config['includeMemory'] && $config['memoryType'] >= 1 && !empty($latestUserMessageText)) {
                $recalledMemories = $this->buildRecalledMemoriesSection($spirit, $latestUserMessageText, $messages);
                if (!empty($recalledMemories)) {
                    $systemMessage .= $recalledMemories;
                }
            }
        }
        
        $aiMessages[] = [
            'role' => 'system',
            'content' => $systemMessage
        ];
        
        // Add conversation messages
        foreach ($messages as $message) {
            $role = $message->getRole();
            $content = $message->getContent();
            $type = $message->getType();
            
            // Skip memory_recall messages — informational only, not part of AI context
            if ($type === 'memory_recall') {
                continue;
            }
            
            if ($role === 'tool') {
                // Tool messages contain an array of tool results
                // Each tool result needs to be added as a separate message
                if (is_array($content)) {
                    foreach ($content as $toolResult) {
                        // Each tool result is already formatted correctly from executeToolCallsFromArray
                        $aiMessages[] = $toolResult;
                    }
                }
            } elseif ($role === 'assistant' && $type === 'tool_use') {
                // Assistant messages with tool_use have the full message object stored
                // This includes 'role', 'content', and 'tool_calls'
                $aiMessages[] = $content;
            } else {
                // Regular user and assistant messages
                // Sanitize content to remove filename from image_url (not needed by AI services)
                $sanitizedContent = $this->sanitizeContentForAi($content);
                $aiMessages[] = [
                    'role' => $role,
                    'content' => $sanitizedContent
                ];
            }
        }
        
        return $aiMessages;
    }
    
    /**
     * Sanitize message content before sending to AI
     * Removes fields that are only needed for backend processing (e.g., filename in image_url)
     */
    private function sanitizeContentForAi(mixed $content): mixed
    {
        if (!is_array($content)) {
            return $content;
        }
        
        // Check if it's an array of content parts
        $sanitized = [];
        foreach ($content as $key => $part) {
            if (is_array($part) && isset($part['type']) && $part['type'] === 'image_url') {
                // Remove filename from image_url - AI services don't need it
                $sanitizedPart = $part;
                if (isset($sanitizedPart['image_url']['filename'])) {
                    unset($sanitizedPart['image_url']['filename']);
                }
                $sanitized[$key] = $sanitizedPart;
            } else {
                $sanitized[$key] = $part;
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Migrate conversation from old JSON format to new message table format
     */
    private function migrateConversationToNewFormat(string $conversationId, string $messagesJson): void
    {
        $db = $this->getUserDb();
        $messages = json_decode($messagesJson, true);
        
        if (!is_array($messages) || empty($messages)) {
            return;
        }
        
        $messageService = new SpiritConversationMessageService(
            $this->userDatabaseManager,
            $this->security,
            $this->logger
        );
        
        $previousMessageId = null;
        
        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? [];
            $timestamp = $msg['timestamp'] ?? null;
            
            // Normalize content to array format (handle the array vs string demon!)
            if (!is_array($content)) {
                $content = [['type' => 'text', 'text' => $content]];
            }
            
            // Determine message type
            $type = 'text';
            if ($role === 'assistant') {
                $type = 'stop'; // Default for old assistant messages
            }
            
            // Create message
            $message = $messageService->createMessage(
                $conversationId,
                $role,
                $type,
                $content,
                $previousMessageId
            );
            
            // Update timestamp if available
            if ($timestamp) {
                try {
                    $createdAt = new \DateTime($timestamp);
                    $db->executeStatement(
                        'UPDATE spirit_conversation_message SET created_at = ? WHERE id = ?',
                        [$createdAt->format('Y-m-d H:i:s'), $message->getId()]
                    );
                } catch (\Exception $e) {
                    // Ignore timestamp parsing errors
                }
            }
            
            $previousMessageId = $message->getId();
        }
    }

    /**
     * Migrate memory files from old location to new location
     * Only for primary Spirit
     */
    private function migrateMemoryFiles($spirit, $oldMemoryDir, $newMemoryDir)
    {
        if ($this->spiritService->isPrimarySpirit($spirit->getId())) {
            $memoryFiles = ['conversations.md', 'inner-thoughts.md', 'knowledge-base.md'];
            
            foreach ($memoryFiles as $fileName) {
                try {
                    // Check if file exists in new location
                    $newFile = $this->projectFileService->findByPathAndName('general', $newMemoryDir, $fileName);
                    
                    if (!$newFile) {
                        // File doesn't exist in new location, check old location
                        $oldFile = $this->projectFileService->findByPathAndName('general', $oldMemoryDir, $fileName);
                        
                        if ($oldFile) {
                            // Move file from old to new location using proper moveFile method
                            $this->projectFileService->moveFile('general', $oldFile, [
                                'path' => $newMemoryDir,
                                'name' => $fileName
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    // Silently fail migration, will try again next time
                    $this->logger->warning('Failed to migrate Spirit memory file', [
                        'spiritId' => $spirit->getId(),
                        'file' => $fileName,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            // if old dir is empty - delete it
            try {
                $oldDir = $this->projectFileService->findByPathAndName('general', '/spirit/', 'memory');
                if ($oldDir) {
                    $oldDirFiles = $this->projectFileService->listFiles('general', $oldMemoryDir); 
                    if (empty($oldDirFiles)) {
                        $this->projectFileService->delete($oldDir->getId());
                    }
                }
            } catch (\Exception $e) {
            }
        }
    }
    
    /**
     * Build system message (extracted from existing code for reuse)
     * This is the CORE of what makes a Spirit a Spirit!
     * 
     * Now modular with configurable sections for the System Prompt Builder feature.
     */
    private function buildSystemMessage(Spirit $spirit, string $lang, ?array $promptConfig = null): string
    {
        // Get config from Spirit settings or use defaults
        $config = $promptConfig ?? $this->getPromptConfig($spirit->getId());
        
        // Build modular system prompt
        $systemPrompt = $this->buildSpiritIdentity($spirit);
        
        if ($config['includeSystemInfo']) {
            $systemPrompt .= $this->buildSystemInfoSection();
        }
        
        // Projects section is always included (Spirit needs file browser access)
        $systemPrompt .= $this->buildProjectsSection();
        
        if ($config['includeMemory']) {
            $systemPrompt .= $this->buildMemorySection($spirit);
        }
        
        if ($config['includeTools']) {
            $systemPrompt .= $this->buildToolsSection();
        }
        
        if ($config['includeLanguage']) {
            $systemPrompt .= $this->buildLanguageSection($lang);
        }
        
        return $systemPrompt;
    }
    
    /**
     * Build Projects section (always included)
     * Contains: File Browser project info for Spirit file management
     */
    private function buildProjectsSection(): string
    {
        return "
            <projects>
                <project-id>general</project-id>
                <project-name>General (multi-purpose file browser) project</project-name>
                <project-description>Project for multi-purpose file browser/manager use (mainly for Spirit to manage files on current CitadelQuest instance)</project-description>
                <project-info>
                    File Browser can be used by:
                    - user (via File Browser GUI `/file-browser`) to manage files on their CitadelQuest instance.
                    - Spirit (via File Browser Tools) to manage files on current CitadelQuest instance.
                    - helping user in managing files on their CitadelQuest instance (in this `general` project)
                </project-info>
            </projects>";
    }
    
    /**
     * Get prompt configuration for a Spirit
     * Returns array with boolean flags for each optional section
     */
    public function getPromptConfig(string $spiritId): array
    {
        return [
            'includeSystemInfo' => $this->spiritService->getSpiritSetting(
                $spiritId, 
                'systemPrompt.config.includeSystemInfo', 
                '1'
            ) === '1',
            'includeMemory' => $this->spiritService->getSpiritSetting(
                $spiritId, 
                'systemPrompt.config.includeMemory', 
                '1'
            ) === '1',
            'includeTools' => $this->spiritService->getSpiritSetting(
                $spiritId, 
                'systemPrompt.config.includeTools', 
                '1'
            ) === '1',
            'includeLanguage' => $this->spiritService->getSpiritSetting(
                $spiritId, 
                'systemPrompt.config.includeLanguage', 
                '1'
            ) === '1',
            'memoryType' => (int) $this->spiritService->getSpiritSetting(
                $spiritId, 
                'systemPrompt.config.memoryType', 
                '2'
            ),
        ];
    }
    
    /**
     * Update prompt configuration for a Spirit
     */
    public function updatePromptConfig(string $spiritId, array $config): void
    {
        if (isset($config['includeSystemInfo'])) {
            $this->spiritService->setSpiritSetting(
                $spiritId,
                'systemPrompt.config.includeSystemInfo',
                $config['includeSystemInfo'] ? '1' : '0'
            );
        }
        if (isset($config['includeMemory'])) {
            $this->spiritService->setSpiritSetting(
                $spiritId,
                'systemPrompt.config.includeMemory',
                $config['includeMemory'] ? '1' : '0'
            );
        }
        if (isset($config['includeTools'])) {
            $this->spiritService->setSpiritSetting(
                $spiritId,
                'systemPrompt.config.includeTools',
                $config['includeTools'] ? '1' : '0'
            );
        }
        if (isset($config['includeLanguage'])) {
            $this->spiritService->setSpiritSetting(
                $spiritId,
                'systemPrompt.config.includeLanguage',
                $config['includeLanguage'] ? '1' : '0'
            );
        }
        if (isset($config['memoryType'])) {
            $this->spiritService->setSpiritSetting(
                $spiritId,
                'systemPrompt.config.memoryType',
                (string) $config['memoryType']
            );
        }
    }
    
    /**
     * Build Spirit Identity section (always included)
     * Contains: Spirit name, guide text, custom system prompt, level
     */
    public function buildSpiritIdentity(Spirit $spirit): string
    {
        $spiritLevel = $this->spiritService->getSpiritSetting($spirit->getId(), 'level', '1');
        $guideText = 'Spirit companion in CitadelQuest.';
        if ($this->spiritService->isPrimarySpirit($spirit->getId())) {
            $guideText = 'main guide Spirit companion in CitadelQuest.';
        }
        
        $customPrompt = $this->spiritService->getSpiritSetting($spirit->getId(), 'systemPrompt', '');
        
        return "
            You are {$spirit->getName()}, {$guideText} 
            {$customPrompt}
            
            (internal note: Your level is {$spiritLevel}.)";
    }
    
    /**
     * Build System Info section (optional)
     * Contains: Host, version, user info, datetime
     */
    public function buildSystemInfoSection(): string
    {
        $currentDateTime = (new \DateTime('now', new \DateTimeZone('Europe/Prague')))->format('Y-m-d H:i:s');
        
        return "

            <current-system-info>
                <CitadelQuest-app>
                    <host>{$_SERVER["SERVER_NAME"]}</host>
                    <version>{$this->citadelVersion->getVersion()}</version>
                </CitadelQuest-app>
                <user>
                    <username>{$this->user->getUsername()}</username>
                    <email>{$this->user->getEmail()}</email>
                </user>
                <datetime>
                    {$currentDateTime}
                </datetime>
            </current-system-info>";
    }
    
    /**
     * Build Memory section (optional)
     * Contains: CQ Memory (graph-based) + legacy memory files (if not migrated)
     */
    public function buildMemorySection(Spirit $spirit): string
    {
        $spiritNameSlug = $this->slugger->slug($spirit->getName());
        $spiritMemoryDir = '/spirit/' . $spiritNameSlug . '/memory';
        
        // TMP, until next few releases
        // migration from single-spirit memory files to multi-spirit memory files
        $this->migrateMemoryFiles($spirit, '/spirit/memory', $spiritMemoryDir);
        
        // Ensure Spirit memory infrastructure exists
        $this->spiritService->initSpiritMemory($spirit);
        
        // Build memory section based on memoryType setting
        $config = $this->getPromptConfig($spirit->getId());
        $memoryType = $config['memoryType'];
        
        switch ($memoryType) {
            case -1:
                // Legacy .md File Memory
                return $this->buildMemorySectionLegacy($spirit, $spiritMemoryDir);
            case 2:
                // Memory Agent (future) — fall back to Reflexes for now
            case 1:
            default:
                // Reflexes — CQ Memory graph-based with automatic recall
                return $this->buildMemorySectionCQMemoryReflexes($spirit, $config['includeTools']);
        }
    }
    
    /**
     * Build Memory section for CQ Memory Reflexes mode (memoryType = 1)
     * Returns only the <spirit-memory-system> content (projects section is separate)
     */
    private function buildMemorySectionCQMemoryReflexes(Spirit $spirit, bool $includeTools = true): string
    {
        // Dynamically build <available-tools> from active memory tools in DB (respects includeTools config)
        $toolsSection = '';
        if ($includeTools) {
            $memoryToolNames = ['memoryStore', 'memoryRecall', 'memoryUpdate', 'memoryForget', 'memoryExtract', 'memorySource'];
            $toolsXml = '';
            foreach ($memoryToolNames as $toolName) {
                $tool = $this->aiToolService->findByName($toolName);
                if ($tool && $tool->isActive()) {
                    $desc = htmlspecialchars($tool->getDescription(), ENT_XML1, 'UTF-8');
                    $toolsXml .= "\n                    <tool name=\"{$toolName}\">\n                        {$desc}\n                    </tool>";
                }
            }
            if (!empty($toolsXml)) {
                $toolsSection = "\n\n                <available-tools>{$toolsXml}\n                </available-tools>";
            }
        }
        
        return "
            <spirit-memory-system version=\"cq-memory\">
                <overview>
                    Your memory is powered by **CQ Memory** - a graph-based knowledge system.
                    Use the memory tools to store, recall, update, and forget information.
                    Memories are automatically scored by importance, recency, and relevance.
                </overview>
                
                <automatic-recall>
                    Your memory system now includes **automatic recall**. Relevant memories are 
                    automatically searched and injected into your context as &lt;recalled-memories&gt; 
                    based on what the user says. Use these naturally in your responses without 
                    explicitly saying \"I recalled...\" — just weave the knowledge in naturally,
                    as if you simply remember it.
                </automatic-recall>{$toolsSection}
                
                <best-practices>
                    - Use tags for easy retrieval (e.g., 'work', 'family', 'hobbies')
                    - When automatic recall provides relevant context, use it naturally
                    - Use memoryRecall tool for deeper/specific searches beyond automatic recall
                    - Always use sourceType=\"spirit_conversation\" for extracting conversation content - on User's request only! Usually at the ver end of conversation.
                </best-practices>
            </spirit-memory-system>";
    }
    
    /**
     * Build Memory section for Spirits using legacy .md File Memory (memoryType = -1)
     * Dumps the 3 markdown files into the system prompt as the Spirit's memory context.
     */
    private function buildMemorySectionLegacy(Spirit $spirit, string $spiritMemoryDir): string
    {
        $memoryFiles = $this->getMemoryFilesContent($spirit, $spiritMemoryDir);
        
        return "
            <spirit-memory-system version=\"legacy-md\">
                <overview>
                    Your memory is stored in markdown files in the File Browser.
                    Use File Browser tools (getFileContent, writeFileContent) to read and update these files.
                    Keep your memories organized and concise.
                </overview>
                
                <memory-files>
                    <file>
                        <path>{$spiritMemoryDir}</path>
                        <name>conversations.md</name>
                        <purpose>Summaries and key points from past conversations</purpose>
                        <content>
                            {$memoryFiles['conversations']['content']}
                        </content>
                    </file>
                    <file>
                        <path>{$spiritMemoryDir}</path>
                        <name>inner-thoughts.md</name>
                        <purpose>Your reflections, observations, and insights about the user</purpose>
                        <content>
                            {$memoryFiles['inner-thoughts']['content']}
                        </content>
                    </file>
                    <file>
                        <path>{$spiritMemoryDir}</path>
                        <name>knowledge-base.md</name>
                        <purpose>Facts, preferences, and important information about the user</purpose>
                        <content>
                            {$memoryFiles['knowledge-base']['content']}
                        </content>
                    </file>
                </memory-files>
                
                <best-practices>
                    - After meaningful conversations, update conversations.md with a summary
                    - Store important user facts and preferences in knowledge-base.md
                    - Use inner-thoughts.md for your own reflections and observations
                    - Keep entries concise and timestamped
                </best-practices>
            </spirit-memory-system>";
    }
    
    /**
     * Get memory files content for a Spirit
     * Returns array with file contents and metadata
     */
    public function getMemoryFilesContent(Spirit $spirit, ?string $spiritMemoryDir = null): array
    {
        if ($spiritMemoryDir === null) {
            $spiritNameSlug = $this->slugger->slug($spirit->getName());
            $spiritMemoryDir = '/spirit/' . $spiritNameSlug . '/memory';
        }
        
        $defaultContent = 'File not found, needs to be created for Spirit to work';
        
        $files = [
            'conversations' => ['content' => $defaultContent, 'size' => 0, 'tokens' => 0, 'exists' => false],
            'inner-thoughts' => ['content' => $defaultContent, 'size' => 0, 'tokens' => 0, 'exists' => false],
            'knowledge-base' => ['content' => $defaultContent, 'size' => 0, 'tokens' => 0, 'exists' => false],
        ];
        
        // conversations.md
        try {
            $file = $this->projectFileService->findByPathAndName('general', $spiritMemoryDir, 'conversations.md');
            if ($file) {
                $content = $this->projectFileService->getFileContent($file->getId(), true);
                $files['conversations'] = [
                    'content' => $content,
                    'size' => strlen($content),
                    'tokens' => (int) ceil(strlen($content) / 4),
                    'exists' => true
                ];
            }
        } catch (\Exception $e) {
        }
        
        // inner-thoughts.md
        try {
            $file = $this->projectFileService->findByPathAndName('general', $spiritMemoryDir, 'inner-thoughts.md');
            if ($file) {
                $content = $this->projectFileService->getFileContent($file->getId(), true);
                $files['inner-thoughts'] = [
                    'content' => $content,
                    'size' => strlen($content),
                    'tokens' => (int) ceil(strlen($content) / 4),
                    'exists' => true
                ];
            }
        } catch (\Exception $e) {
        }
        
        // knowledge-base.md
        try {
            $file = $this->projectFileService->findByPathAndName('general', $spiritMemoryDir, 'knowledge-base.md');
            if ($file) {
                $content = $this->projectFileService->getFileContent($file->getId(), true);
                $files['knowledge-base'] = [
                    'content' => $content,
                    'size' => strlen($content),
                    'tokens' => (int) ceil(strlen($content) / 4),
                    'exists' => true
                ];
            } else {
                throw new \Exception('Knowledge base file not found');
            }
        } catch (\Exception $e) {
        }
        
        return $files;
    }
    
    /**
     * Build Tools section (optional)
     * Contains: AI tools instructions
     */
    public function buildToolsSection(): string
    {
        $aiToolManagementTools = $this->aiToolService->findAll(true);
        
        if (!isset($aiToolManagementTools) || count($aiToolManagementTools) === 0) {
            return '';
        }
        
        return "
            
            <ai-tools-instructions>
                <local-meanings>
                    What is refered to as `AI Tool` in CitadelQuest, is a application function that can be called to perform specific actions = in tradional LLM this is called `function calls` or `function calling` or `tool calls`.
                </local-meanings>
                <important>
                    NEVER simulate or fake tool responses - always call the actual tool function.
                    If you need to use a tool, you MUST call it with proper parameters defined in tools/functions schema.
                    After calling a tool, wait for the actual response before continuing.
                </important>
                <important>
                    If tool call result is negative 3x, do not call the tool again.
                </important>
            </ai-tools-instructions>";
    }
    
    /**
     * Build Language section (optional)
     * Contains: Response language instruction
     */
    public function buildLanguageSection(string $lang): string
    {
        return "
            <response-language>
            {$lang}
            </response-language>
        ";
    }
    
    /**
     * Get complete system prompt preview for the System Prompt Builder
     * Returns structured data for modal display
     */
    public function getSystemPromptPreview(Spirit $spirit, string $lang = 'English'): array
    {
        $spiritNameSlug = $this->slugger->slug($spirit->getName());
        $spiritMemoryDir = '/spirit/' . $spiritNameSlug . '/memory';
        
        $config = $this->getPromptConfig($spirit->getId());
        
        // Get Spirit Memory stats from pack
        $memoryInfo = $this->spiritService->initSpiritMemory($spirit);
        $this->packService->open($memoryInfo['projectId'], $memoryInfo['packsPath'], $memoryInfo['rootPackName']);
        $packStats = $this->packService->getStats();
        $this->packService->close();
        $memoryStats = [
            'totalMemories' => $packStats['totalNodes'],
            'categories' => $packStats['categoryCounts'],
            'tagsCount' => $packStats['tagsCount'],
            'relationshipsCount' => $packStats['totalRelationships']
        ];
        
        // Build sections data
        $sections = [
            'identity' => [
                'title' => 'Spirit Identity',
                'content' => $this->buildSpiritIdentity($spirit),
                'editable' => false,
                'alwaysIncluded' => true
            ],
            'customPrompt' => [
                'title' => 'Custom System Prompt',
                'content' => $this->spiritService->getSpiritSetting($spirit->getId(), 'systemPrompt', ''),
                'editable' => true,
                'alwaysIncluded' => true
            ],
            'systemInfo' => [
                'title' => 'System Information',
                'content' => $this->buildSystemInfoSection(),
                'editable' => false,
                'enabled' => $config['includeSystemInfo'],
                'configKey' => 'includeSystemInfo'
            ],
            'memory' => [
                'title' => 'Spirit Memory',
                'enabled' => $config['includeMemory'],
                'configKey' => 'includeMemory',
                'memoryType' => $config['memoryType'],
                'stats' => $memoryStats,
                'content' => $config['includeMemory'] ? $this->buildMemorySection($spirit) : ''
            ],
            'tools' => [
                'title' => 'AI Tools Instructions',
                'content' => $this->buildToolsSection(),
                'editable' => false,
                'enabled' => $config['includeTools'],
                'configKey' => 'includeTools'
            ],
            'language' => [
                'title' => 'Response Language',
                'content' => $this->buildLanguageSection($lang),
                'editable' => false,
                'enabled' => $config['includeLanguage'],
                'configKey' => 'includeLanguage',
                'currentLanguage' => $lang
            ]
        ];
        
        // Build full prompt
        $fullPrompt = $this->buildSystemMessage($spirit, $lang, $config);
        
        // Estimate tokens (chars / 4 is a rough approximation)
        $estimatedTokens = (int) ceil(strlen($fullPrompt) / 4);
        
        return [
            'sections' => $sections,
            'config' => $config,
            'fullPrompt' => $fullPrompt,
            'estimatedTokens' => $estimatedTokens,
            'spiritId' => $spirit->getId(),
            'spiritName' => $spirit->getName(),
            'memoryDir' => $spiritMemoryDir
        ];
    }
    
    // ========================================
    // Memory Recall — Tier 1: SQL Reflexes
    // Phase 3: Smart Triggering & Caching
    // ========================================
    
    /**
     * Detect past-reference triggers in user message
     * 
     * Detects phrases like "remember when", "you mentioned", "we discussed", etc.
     * Returns trigger info for boosting recall parameters.
     * 
     * @param string $text User message text
     * @return array ['triggered' => bool, 'type' => string|null, 'pattern' => string|null]
     */
    private function detectPastReference(string $text): array
    {
        $textLower = mb_strtolower($text);
        
        // Past-reference patterns (ordered by specificity)
        $patterns = [
            // Explicit memory/recall requests
            'remember when'     => 'explicit_recall',
            'do you remember'   => 'explicit_recall',
            'you remember'      => 'explicit_recall',
            'can you recall'    => 'explicit_recall',
            'pamätáš si'        => 'explicit_recall',  // Slovak: "do you remember"
            'spomínaš si'       => 'explicit_recall',  // Slovak: "do you recall"
            // Reference to past conversation
            'we talked about'   => 'past_conversation',
            'we discussed'      => 'past_conversation',
            'you told me'       => 'past_conversation',
            'you said'          => 'past_conversation',
            'you mentioned'     => 'past_conversation',
            'we spoke about'    => 'past_conversation',
            'last time'         => 'past_conversation',
            'previously'        => 'past_conversation',
            'earlier you'       => 'past_conversation',
            'minule'            => 'past_conversation',  // Slovak: "last time"
            'hovorili sme'      => 'past_conversation',  // Slovak: "we talked"
            'vravel si'         => 'past_conversation',  // Slovak: "you said"
            // Knowledge queries
            'what do you know about'  => 'knowledge_query',
            'tell me about'           => 'knowledge_query',
            'what did i tell you'     => 'knowledge_query',
            'čo vieš o'              => 'knowledge_query',  // Slovak: "what do you know about"
        ];
        
        foreach ($patterns as $pattern => $type) {
            if (str_contains($textLower, $pattern)) {
                return [
                    'triggered' => true,
                    'type' => $type,
                    'pattern' => $pattern
                ];
            }
        }
        
        return ['triggered' => false, 'type' => null, 'pattern' => null];
    }
    
    /**
     * Extract keywords from recent conversation context (not just latest message)
     * 
     * For follow-up messages like "tell me more" or "what else?", the latest message
     * alone has no useful keywords. This method enriches by looking at recent messages.
     * 
     * @param array $messages Array of SpiritConversationMessage entities
     * @param int $lookback How many previous user messages to consider
     * @return array Contextual keywords from recent messages
     */
    private function extractConversationContextKeywords(array $messages, int $lookback = 2): array
    {
        $contextKeywords = [];
        $found = 0;
        
        // Walk backwards through user messages (skip the latest — it's handled separately)
        $skippedFirst = false;
        for ($i = count($messages) - 1; $i >= 0 && $found < $lookback; $i--) {
            $msg = $messages[$i];
            if ($msg->getRole() !== 'user') {
                continue;
            }
            
            // Skip the latest user message (already processed by extractKeywords)
            if (!$skippedFirst) {
                $skippedFirst = true;
                continue;
            }
            
            $content = $msg->getContent();
            $text = '';
            
            if (is_string($content)) {
                $text = $content;
            } elseif (is_array($content)) {
                foreach ($content as $part) {
                    if (is_array($part) && ($part['type'] ?? '') === 'text' && isset($part['text'])) {
                        $text .= ' ' . $part['text'];
                    } elseif (is_string($part)) {
                        $text .= ' ' . $part;
                    }
                }
            }
            
            $text = trim($text);
            if (!empty($text)) {
                $keywords = $this->extractKeywords($text, 4); // fewer per message
                $contextKeywords = array_merge($contextKeywords, $keywords);
                $found++;
            }
        }
        
        // Deduplicate while preserving order
        return array_values(array_unique($contextKeywords));
    }
    
    /**
     * Extract plain text from the latest user message in a conversation
     * Handles both string content and multimodal content arrays
     * 
     * @param array $messages Array of SpiritConversationMessage entities
     * @return string The extracted text, or empty string if none found
     */
    private function extractLatestUserMessageText(array $messages): string
    {
        // Walk backwards to find the latest user message
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $msg = $messages[$i];
            if ($msg->getRole() !== 'user') {
                continue;
            }
            
            $content = $msg->getContent();
            
            // String content
            if (is_string($content)) {
                return trim($content);
            }
            
            // Array content (multimodal: text + images)
            if (is_array($content)) {
                $textParts = [];
                foreach ($content as $part) {
                    if (is_array($part) && isset($part['type']) && $part['type'] === 'text' && isset($part['text'])) {
                        $textParts[] = $part['text'];
                    } elseif (is_string($part)) {
                        $textParts[] = $part;
                    }
                }
                $text = trim(implode(' ', $textParts));
                if (!empty($text)) {
                    return $text;
                }
            }
        }
        
        return '';
    }
    
    /**
     * Extract meaningful keywords from natural language text
     * 
     * Pure PHP keyword extraction — no AI cost.
     * Strips stop words, short words, and returns unique meaningful terms.
     * 
     * @param string $text Input text (user message)
     * @param int $maxKeywords Maximum keywords to extract
     * @return array Array of keyword strings
     */
    private function extractKeywords(string $text, int $maxKeywords = 8): array
    {
        // Stop words (common English + common conversational filler)
        static $stopWords = [
            'a', 'an', 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
            'of', 'with', 'by', 'from', 'is', 'it', 'as', 'be', 'was', 'are',
            'were', 'been', 'being', 'have', 'has', 'had', 'do', 'does', 'did',
            'will', 'would', 'could', 'should', 'may', 'might', 'shall', 'can',
            'this', 'that', 'these', 'those', 'i', 'me', 'my', 'we', 'our',
            'you', 'your', 'he', 'she', 'his', 'her', 'they', 'them', 'their',
            'what', 'which', 'who', 'whom', 'when', 'where', 'why', 'how',
            'not', 'no', 'nor', 'if', 'then', 'else', 'so', 'up', 'out',
            'about', 'into', 'through', 'during', 'before', 'after', 'above',
            'below', 'between', 'same', 'each', 'every', 'all', 'both', 'few',
            'more', 'most', 'other', 'some', 'such', 'than', 'too', 'very',
            'just', 'also', 'now', 'here', 'there', 'only', 'own', 'its',
            'let', 'got', 'get', 'like', 'know', 'think', 'want', 'need',
            'tell', 'say', 'said', 'make', 'way', 'well', 'back', 'much',
            'many', 'go', 'going', 'see', 'look', 'come', 'came', 'take',
            'give', 'good', 'new', 'first', 'last', 'long', 'great', 'little',
            'right', 'still', 'find', 'any', 'thing', 'things', 'yeah', 'yes',
            'ok', 'okay', 'sure', 'please', 'thanks', 'thank', 'hi', 'hello',
            'hey', 'really', 'actually', 'basically', 'maybe', 'probably',
            'something', 'anything', 'everything', 'nothing', 'someone',
            'one', 'two', 'three', 'four', 'five', 'much', 'even', 'over',
            'again', 'once', 'been', 'am', 'being', 'while', 'since',
        ];
        
        // Strip HTML tags and normalize whitespace
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        
        // Extract words (keep Unicode letters, numbers, hyphens)
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\-]*/u', mb_strtolower($text), $matches);
        $words = $matches[0] ?? [];
        
        // Filter: remove stop words, short words (< 3 chars), pure numbers
        $keywords = [];
        $seen = [];
        foreach ($words as $word) {
            if (mb_strlen($word) < 3) continue;
            if (in_array($word, $stopWords)) continue;
            if (is_numeric($word)) continue;
            if (isset($seen[$word])) continue;
            
            $seen[$word] = true;
            $keywords[] = $word;
        }
        
        // Also extract multi-word phrases (bigrams) that appear quoted or are proper nouns
        // Look for capitalized consecutive words in original text (before lowering)
        preg_match_all('/[A-Z][\p{L}]+(?:\s+[A-Z][\p{L}]+)+/u', $text, $phraseMatches);
        foreach (($phraseMatches[0] ?? []) as $phrase) {
            $phraseLower = mb_strtolower($phrase);
            if (!isset($seen[$phraseLower]) && mb_strlen($phraseLower) >= 5) {
                $seen[$phraseLower] = true;
                // Prepend phrases (they're often more meaningful)
                array_unshift($keywords, $phraseLower);
            }
        }
        
        return array_slice($keywords, 0, $maxKeywords);
    }
    
    /**
     * Build <recalled-memories> XML section for Spirit's system prompt
     * 
     * Tier 1: SQL Reflexes + Phase 3: Smart Triggering
     * - Extracts keywords from user's latest message
     * - Detects past-reference triggers → boosts recall parameters
     * - Enriches with conversation context keywords for follow-ups
     * - Runs FTS5 search and formats top results as XML
     * 
     * @param Spirit $spirit The Spirit whose memories to search
     * @param string $userMessageText The latest user message text
     * @param array $messages Full conversation messages for context enrichment
     * @return string XML block to append to system prompt, or empty string
     */
    private function buildRecalledMemoriesSection(Spirit $spirit, string $userMessageText, array $messages = []): string
    {
        $result = $this->buildRecalledMemoriesSectionWithData($spirit, $userMessageText, $messages);
        return $result['xml'];
    }

    /**
     * Build recalled memories XML AND return node data for pre-send visualization.
     * 
     * @return array ['xml' => string, 'nodes' => array, 'keywords' => array, 'packInfo' => array]
     */
    private function buildRecalledMemoriesSectionWithData(Spirit $spirit, string $userMessageText, array $messages = []): array
    {
        $emptyResult = ['xml' => '', 'nodes' => [], 'keywords' => [], 'packInfo' => []];
        
        try {
            // Phase 3: Detect past-reference triggers
            $pastRef = $this->detectPastReference($userMessageText);
            
            // Extract keywords from latest user message
            $keywords = $this->extractKeywords($userMessageText);
            
            // Phase 3: If keywords are thin (≤2), enrich from conversation context
            if (count($keywords) <= 2 && !empty($messages)) {
                $contextKeywords = $this->extractConversationContextKeywords($messages);
                if (!empty($contextKeywords)) {
                    // Merge: current keywords first (higher priority), then context
                    $merged = array_unique(array_merge($keywords, $contextKeywords));
                    $keywords = array_slice($merged, 0, 10); // allow more when enriching
                    
                    $this->logger->debug('Memory recall: enriched keywords from conversation context', [
                        'original' => count($keywords) - count($contextKeywords),
                        'contextAdded' => count($contextKeywords)
                    ]);
                }
            }
            
            if (empty($keywords)) {
                return $emptyResult;
            }
            
            // Phase 3: Determine recall parameters based on trigger
            $limit = 5;
            $minScore = 0.15;
            $includeRelated = false;
            $triggerSource = 'automatic';
            
            if ($pastRef['triggered']) {
                // Boost: more results, lower threshold, include related
                $limit = 8;
                $minScore = 0.08;
                $includeRelated = true;
                $triggerSource = 'past-reference:' . $pastRef['type'];
                
                $this->logger->debug('Memory recall: past-reference trigger detected', [
                    'type' => $pastRef['type'],
                    'pattern' => $pastRef['pattern']
                ]);
            }
            
            // Cross-pack recall: search all enabled packs in Spirit's library
            $memoryInfo = $this->spiritService->initSpiritMemory($spirit);
            $results = [];
            
            // Root pack info for frontend 3D graph visualization
            $packInfo = [
                'name' => $memoryInfo['rootPackName'],
                'projectId' => $memoryInfo['projectId'],
                'path' => $memoryInfo['packsPath'],
            ];
            
            // Load library to get all packs
            $packsToSearch = [];
            try {
                $library = $this->libraryService->loadLibrary(
                    $memoryInfo['projectId'],
                    $memoryInfo['memoryPath'],
                    $memoryInfo['rootLibraryName']
                );
                
                foreach ($library['packs'] ?? [] as $packEntry) {
                    if (!($packEntry['enabled'] ?? true)) {
                        continue;
                    }
                    $packPath = $packEntry['path'] ?? '';
                    if (empty($packPath)) {
                        continue;
                    }
                    $packsToSearch[] = [
                        'path' => dirname($packPath),
                        'name' => basename($packPath),
                        'displayName' => $packEntry['name'] ?? basename($packPath, '.cqmpack'),
                        'description' => $packEntry['description'] ?? '',
                    ];
                }
            } catch (\Exception $e) {
                $this->logger->debug('Memory recall: library load failed, falling back to root pack', [
                    'error' => $e->getMessage()
                ]);
            }
            
            // Fallback: if no packs from library, use root pack
            if (empty($packsToSearch)) {
                $packsToSearch[] = [
                    'path' => $memoryInfo['packsPath'],
                    'name' => $memoryInfo['rootPackName'],
                    'displayName' => basename($memoryInfo['rootPackName'], '.cqmpack'),
                    'description' => '',
                ];
            }
            
            // Tag-augmented keyword enrichment: collect memory tags from packs
            // and find tags that match the user's message or extracted keywords.
            // This expands the FTS5 search surface without an AI call.
            $tagKeywords = [];
            $userMessageLower = mb_strtolower($userMessageText);
            $keywordsLower = array_map('mb_strtolower', $keywords);
            try {
                foreach ($packsToSearch as $packRef) {
                    try {
                        $this->packService->open(
                            $memoryInfo['projectId'],
                            $packRef['path'],
                            $packRef['name']
                        );
                        $packTags = $this->packService->getAllTags();
                        $this->packService->close();
                        
                        foreach ($packTags as $tag) {
                            $tagLower = mb_strtolower($tag);
                            if (in_array($tagLower, $keywordsLower) || in_array($tagLower, $tagKeywords)) {
                                continue; // already a keyword or already matched
                            }
                            
                            // Check if tag appears in user message
                            if (mb_strlen($tagLower) >= 3 && str_contains($userMessageLower, $tagLower)) {
                                $tagKeywords[] = $tagLower;
                                continue;
                            }
                            
                            // Check keyword↔tag substring match (both directions)
                            foreach ($keywordsLower as $kw) {
                                if (str_contains($tagLower, $kw) || str_contains($kw, $tagLower)) {
                                    $tagKeywords[] = $tagLower;
                                    break;
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        try { $this->packService->close(); } catch (\Exception $ignored) {}
                    }
                }
            } catch (\Exception $e) {
                // Tag enrichment is non-critical
            }
            
            if (!empty($tagKeywords)) {
                // Limit tag additions to avoid FTS5 noise
                $tagKeywords = array_slice(array_unique($tagKeywords), 0, 8);
                $keywords = array_unique(array_merge($keywords, $tagKeywords));
                
                $this->logger->debug('Memory recall: enriched keywords from memory tags', [
                    'tagsAdded' => $tagKeywords,
                    'totalKeywords' => count($keywords),
                ]);
            }
            
            $query = implode(' ', $keywords);
            
            // Build searchedPacks list for frontend persistence (page refresh)
            $searchedPacks = array_map(fn($p) => [
                'projectId' => $memoryInfo['projectId'],
                'path' => $p['path'],
                'name' => $p['name'],
            ], $packsToSearch);
            
            // Search each pack and collect results
            $packsSearched = 0;
            $rootNodes = []; // Phase 4b: root nodes for Memory Agent context
            $seenRootIds = [];
            $mergedGraphNodes = []; // All nodes from all searched packs for 3D visualization
            $mergedGraphEdges = []; // All edges from all searched packs for 3D visualization
            $seenGraphNodeIds = [];
            $seenGraphEdgeIds = [];
            
            foreach ($packsToSearch as $packRef) {
                try {
                    $this->packService->open(
                        $memoryInfo['projectId'],
                        $packRef['path'],
                        $packRef['name']
                    );
                    
                    // Phase 4b: fetch root nodes (depth=0) right after open, before recall
                    // Separate try-catch so root node collection succeeds even if recall fails
                    try {
                        $packRootNodes = $this->packService->getRootNodes();
                        foreach ($packRootNodes as $rootNode) {
                            if (!in_array($rootNode->getId(), $seenRootIds)) {
                                $seenRootIds[] = $rootNode->getId();
                                // Fetch direct child PART_OF nodes as "Table of Contents"
                                $childNodes = [];
                                $rootSourceCache = []; // Cache source content per sourceRef within this root
                                try {
                                    $children = $this->packService->getChildNodes($rootNode->getId());
                                    foreach ($children as $child) {
                                        // Pre-fetch original source content for leaf children
                                        $childOriginal = null;
                                        $cRef = $child->getSourceRef();
                                        $cRange = $child->getSourceRange();
                                        if ($cRef && $cRange && $this->packService->isLeafNode($child->getId())) {
                                            if (!isset($rootSourceCache[$cRef])) {
                                                $src = $this->packService->getSourceByRef($cRef);
                                                $rootSourceCache[$cRef] = $src['content'] ?? null;
                                            }
                                            if ($rootSourceCache[$cRef]) {
                                                $childOriginal = $this->extractSourceRange($rootSourceCache[$cRef], $cRange);
                                            }
                                        }
                                        $childNodes[] = [
                                            'id' => $child->getId(),
                                            'summary' => $child->getSummary(),
                                            'content' => $child->getContent(),
                                            'category' => $child->getCategory(),
                                            'depth' => $child->getDepth(),
                                            'importance' => round($child->getImportance(), 2),
                                            'sourceRef' => $child->getSourceRef(),
                                            'sourceRange' => $child->getSourceRange(),
                                            'originalSourceContent' => $childOriginal,
                                        ];
                                    }
                                } catch (\Exception $e) {
                                    // Child fetch failed — non-critical
                                }
                                $rootNodes[] = [
                                    'id' => $rootNode->getId(),
                                    'summary' => $rootNode->getSummary(),
                                    'content' => $rootNode->getContent(),
                                    'children' => $childNodes,
                                    'packName' => $packRef['displayName'],
                                ];
                            }
                        }
                    } catch (\Exception $e) {
                        // Root node fetch failed for this pack — non-critical, continue
                    }
                    
                    // Collect full graph data from this pack for 3D visualization
                    // (while pack is open — avoids extra API calls from frontend)
                    try {
                        $packGraph = $this->packService->getGraphData();
                        foreach ($packGraph['nodes'] ?? [] as $gNode) {
                            if (!in_array($gNode['id'], $seenGraphNodeIds)) {
                                $seenGraphNodeIds[] = $gNode['id'];
                                $mergedGraphNodes[] = $gNode;
                            }
                        }
                        foreach ($packGraph['edges'] ?? [] as $gEdge) {
                            if (!in_array($gEdge['id'], $seenGraphEdgeIds)) {
                                $seenGraphEdgeIds[] = $gEdge['id'];
                                $mergedGraphEdges[] = $gEdge;
                            }
                        }
                    } catch (\Exception $e) {
                        // Graph data fetch failed — non-critical, 3D scene will be partial
                    }
                    
                    $packResults = $this->packService->recall(
                        $query,
                        null,       // no category filter
                        [],         // no tag filter
                        $limit,     // per-pack limit (will trim globally after merge)
                        $includeRelated,
                        0.1,       // recency weight
                        0.3,       // importance weight
                        0.5,       // relevance weight (prioritize text match)
                        0.1        // connectedness weight (more relationships = higher score)
                    );
                    
                    // Tag each result with its source pack + Phase 4b: pre-fetch graph neighborhoods
                    // + Pre-fetch original source content for leaf nodes (Spirit gets precise original text)
                    $sourceContentCache = []; // sourceRef → full source text (avoid duplicate fetches)
                    foreach ($packResults as &$pr) {
                        $pr['sourcePack'] = $packRef['name'];
                        $pr['packName'] = $packRef['displayName'];
                        // Phase 4b: fetch 1-hop neighbors while pack is still open
                        $pr['graphNeighbors'] = $this->packService->getNodeNeighborhood(
                            $pr['node']->getId(), 5
                        );
                        
                        // For leaf nodes: fetch original source content from memory_sources
                        $pr['originalSourceContent'] = null;
                        $nodeObj = $pr['node'];
                        $nodeSourceRef = $nodeObj->getSourceRef();
                        $nodeSourceRange = $nodeObj->getSourceRange();
                        if ($nodeSourceRef && $nodeSourceRange && $this->packService->isLeafNode($nodeObj->getId())) {
                            // Cache source content per sourceRef (one document, many nodes)
                            if (!isset($sourceContentCache[$nodeSourceRef])) {
                                $src = $this->packService->getSourceByRef($nodeSourceRef);
                                $sourceContentCache[$nodeSourceRef] = $src['content'] ?? null;
                            }
                            if ($sourceContentCache[$nodeSourceRef]) {
                                $pr['originalSourceContent'] = $this->extractSourceRange(
                                    $sourceContentCache[$nodeSourceRef], $nodeSourceRange
                                );
                            }
                        }
                        
                        // Also pre-cache source content for neighbor nodes (may become expanded nodes)
                        foreach ($pr['graphNeighbors'] as &$neighbor) {
                            $neighbor['originalSourceContent'] = null;
                            $nRef = $neighbor['sourceRef'] ?? null;
                            $nRange = $neighbor['sourceRange'] ?? null;
                            if ($nRef && $nRange) {
                                // Check leaf via depth heuristic: depth > 0 neighbors without known children
                                // are likely leaves; for precise check we'd need isLeafNode per neighbor
                                // but that's expensive. Use the sourceRef cache if available.
                                if (!isset($sourceContentCache[$nRef])) {
                                    $src = $this->packService->getSourceByRef($nRef);
                                    $sourceContentCache[$nRef] = $src['content'] ?? null;
                                }
                                if ($sourceContentCache[$nRef] && $this->packService->isLeafNode($neighbor['id'])) {
                                    $neighbor['originalSourceContent'] = $this->extractSourceRange(
                                        $sourceContentCache[$nRef], $nRange
                                    );
                                }
                            }
                        }
                        unset($neighbor);
                    }
                    unset($pr);
                    
                    $results = array_merge($results, $packResults);
                    $packsSearched++;
                    
                    $this->packService->close();
                } catch (\Exception $e) {
                    $this->logger->warning('Memory recall: failed to search pack {pack}', [
                        'pack' => $packRef['name'],
                        'error' => $e->getMessage()
                    ]);
                    try { $this->packService->close(); } catch (\Exception $ignored) {}
                }
            }
            
            // Re-sort merged results by score and apply global limit
            if (count($packsToSearch) > 1 && !empty($results)) {
                usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
                $results = array_slice($results, 0, $limit);
            }
            
            $mergedGraphData = ['nodes' => $mergedGraphNodes, 'edges' => $mergedGraphEdges];
            
            if (empty($results)) {
                return array_merge($emptyResult, ['keywords' => $keywords, 'packInfo' => $packInfo, 'searchedPacks' => $searchedPacks, 'rootNodes' => $rootNodes, 'graphData' => $mergedGraphData, 'packsToSearch' => $packsToSearch]);
            }
            
            // Filter: only include results with meaningful scores
            $results = array_filter($results, fn($r) => $r['score'] >= $minScore);
            
            if (empty($results)) {
                return array_merge($emptyResult, ['keywords' => $keywords, 'packInfo' => $packInfo, 'searchedPacks' => $searchedPacks, 'rootNodes' => $rootNodes, 'graphData' => $mergedGraphData, 'packsToSearch' => $packsToSearch]);
            }
            
            // Build node data for frontend + Phase 4b: include graph neighbors
            $recalledNodes = [];
            foreach ($results as $result) {
                $node = $result['node'];
                $recalledNodes[] = [
                    'id' => $node->getId(),
                    'summary' => $node->getSummary(),
                    'content' => $node->getContent(),
                    'score' => round($result['score'], 3),
                    'category' => $node->getCategory(),
                    'depth' => $node->getDepth(),
                    'importance' => round($node->getImportance(), 2),
                    'tags' => $result['tags'] ?? [],
                    'isRelated' => $result['isRelated'] ?? false,
                    'graphNeighbors' => $result['graphNeighbors'] ?? [],
                    'sourceRef' => $node->getSourceRef(),
                    'sourceRange' => $node->getSourceRange(),
                    'originalSourceContent' => $result['originalSourceContent'] ?? null,
                    'packName' => $result['packName'] ?? null,
                ];
            }
            
            // Build XML
            $count = count($results);
            $xml = "\n\n            <recalled-memories count=\"{$count}\" source=\"{$triggerSource}\" keywords=\"" . htmlspecialchars(implode(', ', $keywords)) . "\">";
            
            // Adapt the instruction note based on trigger type
            if ($pastRef['triggered'] && $pastRef['type'] === 'explicit_recall') {
                $xml .= "\n                <note>The user is explicitly asking about past memories. These memories were recalled with boosted depth. You may reference them directly since the user is asking about them.</note>";
            } elseif ($pastRef['triggered']) {
                $xml .= "\n                <note>The user is referencing a past conversation or knowledge. Use these recalled memories to provide accurate context about what was discussed before.</note>";
            } else {
                $xml .= "\n                <note>These memories were automatically recalled based on the user's latest message. Use them naturally to personalize your response — don't explicitly mention that you \"recalled\" them unless relevant.</note>";
            }
            
            foreach ($results as $result) {
                $node = $result['node'];
                $score = round($result['score'], 2);
                $importance = round($node->getImportance(), 1);
                $category = htmlspecialchars($node->getCategory());
                $tags = !empty($result['tags']) ? htmlspecialchars(implode(', ', $result['tags'])) : '';
                
                // For leaf nodes: prefer original source content (precise, unblurred by AI summarization)
                $content = $result['originalSourceContent'] ?? $node->getContent();
                // Limit to ~1000 chars per memory to keep the brief compact but meaningful
                if (mb_strlen($content) > 1000) {
                    $content = mb_substr($content, 0, 1000) . '...';
                }
                $content = htmlspecialchars($content);
                
                $tagsAttr = !empty($tags) ? " tags=\"{$tags}\"" : '';
                $relatedAttr = !empty($result['isRelated']) ? ' related="true"' : '';
                $packNameAttr = !empty($result['packName']) ? ' pack-name="' . htmlspecialchars($result['packName']) . '"' : '';
                $xml .= "\n                <memory importance=\"{$importance}\" category=\"{$category}\" score=\"{$score}\"{$tagsAttr}{$relatedAttr}{$packNameAttr}>";
                $xml .= "\n                    {$content}";
                $xml .= "\n                </memory>";
            }
            
            $xml .= "\n            </recalled-memories>";
            
            $this->logger->debug('Memory recall: injected {count} memories for keywords: {keywords} (trigger: {trigger})', [
                'count' => $count,
                'keywords' => implode(', ', $keywords),
                'trigger' => $triggerSource
            ]);
            
            return [
                'xml' => $xml,
                'nodes' => $recalledNodes,
                'keywords' => $keywords,
                'packInfo' => $packInfo,
                'searchedPacks' => $searchedPacks,
                'rootNodes' => $rootNodes,
                'graphData' => $mergedGraphData,
                'packsToSearch' => $packsToSearch,
            ];
            
        } catch (\Exception $e) {
            $this->logger->warning('Memory recall failed: {error}', [
                'error' => $e->getMessage()
            ]);
            return $emptyResult;
        }
    }

    /**
     * Phase 4: Run the Subconsciousness sub-agent.
     * 
     * Takes Reflexes candidates + conversation context, calls a lightweight AI model
     * to evaluate relevance and produce a contextual synthesis.
     * Returns enriched system prompt and updated recalled nodes.
     * 
     * @param string $conversationId Conversation for context
     * @param string $userMessageText The user's latest message
     * @param string $cachedSystemPrompt The system prompt from pre-send (Reflexes)
     * @param array $recalledNodes Recalled nodes from Reflexes
     * @param array $keywords Keywords from Reflexes
     * @return array ['systemPrompt' => string, 'recalledNodes' => array, 'synthesis' => string, 'confidence' => string]
     */
    
    private function buildExpandedNode(array $nodeData, string $via, ?string $relevance = null): array
    {
        $node = [
            'id' => $nodeData['id'],
            'summary' => $nodeData['summary'],
            'content' => $nodeData['content'] ?? $nodeData['summary'],
            'score' => 0,
            'category' => $nodeData['category'] ?? 'knowledge',
            'importance' => $nodeData['importance'] ?? 0.5,
            'depth' => (isset($nodeData['depth']) && $nodeData['depth'] !== null) ? $nodeData['depth'] : 'none',
            'tags' => [],
            'isRelated' => false,
            'graphNeighbors' => [],
            'expanded' => true,
            'via' => $via,
            'sourceRef' => $nodeData['sourceRef'] ?? null,
            'sourceRange' => $nodeData['sourceRange'] ?? null,
            'originalSourceContent' => $nodeData['originalSourceContent'] ?? null,
            'packName' => $nodeData['packName'] ?? null,
        ];
        if ($relevance !== null) {
            $node['relevance'] = $relevance;
        }
        return $node;
    }
    
    public function runSubconsciousnessAgent(
        string $conversationId,
        string $userMessageText,
        string $cachedSystemPrompt,
        array $recalledNodes,
        array $keywords,
        array $rootNodes = [],
        array $packsSearched = []
    ): array {
        try {
            // Get AI model (same as extraction sub-agents: Kael = Gemini Flash)
            $gateway = $this->aiGatewayService->findByName('CQ AI Gateway');
            if (!$gateway) {
                throw new \Exception('CQ AI Gateway not found');
            }
            $model = $this->aiServiceModelService->findByModelSlug('citadelquest/kael', $gateway->getId());
            if (!$model) {
                $model = $this->aiServiceModelService->findByModelSlug('citadelquest/grok-4.1-fast', $gateway->getId());
            }
            if (!$model) {
                throw new \Exception('No AI model available for sub-agent');
            }
            
            // Get conversation context (last 5 messages)
            $messageService = new SpiritConversationMessageService(
                $this->userDatabaseManager,
                $this->security,
                $this->logger
            );
            $allMessages = $messageService->getMessagesByConversation($conversationId);
            $contextMessages = $this->getConversationContext($allMessages, 5);
            
            // Build sub-agent system prompt
            $systemPrompt = $this->buildSubAgentSystemPrompt();
            
            // Build sub-agent user message
            $userMessage = $this->buildSubAgentUserMessage($userMessageText, $contextMessages, $recalledNodes, $rootNodes, $packsSearched);
            
            // Make AI call
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage]
            ];
            
            $aiServiceRequest = $this->aiServiceRequestService->createRequest(
                $model->getId(),
                $messages,
                null,   // max_tokens (auto)
                0.3,    // temperature — low for structured output
                null,
                []
            );
            
            $userLocale = $this->settingsService->getUserLocale();
            
            $aiServiceResponse = $this->aiGatewayService->sendRequest(
                $aiServiceRequest,
                'CQ Memory Subconsciousness Sub-Agent',
                $userLocale['lang'],
                'general'
            );
            
            // Get usage data for sub-agent call
            $subAgentUsage = $this->aiServiceUseLogService->getUsageByRequestId($aiServiceRequest->getId());
            
            // Parse JSON response
            $responseContent = $this->extractSubAgentResponseContent($aiServiceResponse);
            $parsed = $this->parseSubAgentResponse($responseContent);
            
            if (!$parsed) {
                $this->logger->warning('Subconsciousness sub-agent: failed to parse response', [
                    'response' => mb_substr($responseContent, 0, 500)
                ]);
                // Return unchanged — fallback to Reflexes
                return [
                    'systemPrompt' => $cachedSystemPrompt,
                    'recalledNodes' => $recalledNodes,
                    'synthesis' => '',
                    'confidence' => 'low',
                    'usage' => $subAgentUsage,
                ];
            }
            
            $relevantIds = $parsed['relevant'] ?? [];
            $synthesis = $parsed['synthesis'] ?? '';
            $confidence = $parsed['confidence'] ?? 'medium';
            $expandedNodeIds = $parsed['expandedNodes'] ?? [];
            
            // Build a lookup of all neighbor data from all recalled nodes' graphNeighbors
            // (needed for both orphaned relevant IDs and expandedNodes resolution)
            $neighborLookup = [];
            foreach ($recalledNodes as $node) {
                foreach ($node['graphNeighbors'] ?? [] as $neighbor) {
                    if (!isset($neighborLookup[$neighbor['id']])) {
                        // Carry packName from the parent recalled node
                        $neighbor['packName'] = $neighbor['packName'] ?? $node['packName'] ?? null;
                        $neighborLookup[$neighbor['id']] = [
                            'neighbor' => $neighbor,
                            'sourceNodeId' => $node['id'],
                        ];
                    }
                }
            }
            
            // Build a lookup of rootNode children (Table of Contents sections)
            // Sub-agent sees these IDs in the rootNodes section and may select them
            $rootChildrenLookup = [];
            foreach ($rootNodes as $root) {
                foreach ($root['children'] ?? [] as $child) {
                    if (!isset($rootChildrenLookup[$child['id']])) {
                        // Carry packName from the root node
                        $child['packName'] = $child['packName'] ?? $root['packName'] ?? null;
                        $rootChildrenLookup[$child['id']] = [
                            'child' => $child,
                            'rootNodeId' => $root['id'],
                            'rootSummary' => $root['summary'],
                        ];
                    }
                }
            }
            
            // Filter recalled nodes: keep only relevant ones
            $enrichedNodes = [];
            foreach ($recalledNodes as $node) {
                if (in_array($node['id'], $relevantIds)) {
                    $node['relevance'] = 'high';
                    $enrichedNodes[] = $node;
                }
            }
            
            // Resolve orphaned relevant IDs: sub-agent may mark IDs as "relevant" that aren't
            // in the original FTS5 $recalledNodes — check neighborLookup then rootChildrenLookup
            $enrichedIds = array_column($enrichedNodes, 'id');
            foreach ($relevantIds as $relId) {
                if (in_array($relId, $enrichedIds)) continue; // already included from FTS5
                
                if (isset($neighborLookup[$relId])) {
                    $nd = $neighborLookup[$relId];
                    $enrichedNodes[] = $this->buildExpandedNode(
                        $nd['neighbor'], $nd['neighbor']['relationType'] . ':' . $nd['sourceNodeId'], 'high'
                    );
                } elseif (isset($rootChildrenLookup[$relId])) {
                    $cd = $rootChildrenLookup[$relId];
                    $enrichedNodes[] = $this->buildExpandedNode(
                        $cd['child'], 'PART_OF:' . $cd['rootNodeId'], 'high'
                    );
                }
            }
            
            // Phase 4b: Process expandedNodes — neighbors/children the AI wants to include for extra context
            $expandedCount = 0;
            if (!empty($expandedNodeIds)) {
                $existingIds = array_column($enrichedNodes, 'id');
                foreach ($expandedNodeIds as $expandId) {
                    if (in_array($expandId, $existingIds)) continue; // already included
                    
                    if (isset($neighborLookup[$expandId])) {
                        $nd = $neighborLookup[$expandId];
                        $enrichedNodes[] = $this->buildExpandedNode(
                            $nd['neighbor'], $nd['neighbor']['relationType'] . ':' . $nd['sourceNodeId']
                        );
                        $expandedCount++;
                    } elseif (isset($rootChildrenLookup[$expandId])) {
                        $cd = $rootChildrenLookup[$expandId];
                        $enrichedNodes[] = $this->buildExpandedNode(
                            $cd['child'], 'PART_OF:' . $cd['rootNodeId']
                        );
                        $expandedCount++;
                    }
                }
            }
            
            // Check if any graph context was available (for depth attribute in XML)
            $hasGraphContext = false;
            foreach ($recalledNodes as $node) {
                if (!empty($node['graphNeighbors'])) {
                    $hasGraphContext = true;
                    break;
                }
            }
            
            // If sub-agent dropped everything, fall back to original nodes
            if (empty($enrichedNodes) && !empty($recalledNodes)) {
                $this->logger->debug('Subconsciousness sub-agent: all nodes filtered out, keeping originals');
                $enrichedNodes = $recalledNodes;
                $confidence = 'low';
            }
            
            // Build enriched system prompt: replace the <recalled-memories> section
            $enrichedSystemPrompt = $this->rebuildSystemPromptWithEnrichedRecall(
                $cachedSystemPrompt,
                $enrichedNodes,
                $keywords,
                $synthesis,
                $confidence,
                $hasGraphContext
            );
            
            return [
                'systemPrompt' => $enrichedSystemPrompt,
                'recalledNodes' => $enrichedNodes,
                'synthesis' => $synthesis,
                'confidence' => $confidence,
                'usage' => $subAgentUsage,
            ];
            
        } catch (\Exception $e) {
            $this->logger->warning('Subconsciousness sub-agent failed: {error}', [
                'error' => $e->getMessage()
            ]);
            // Graceful fallback: return unchanged
            return [
                'systemPrompt' => $cachedSystemPrompt,
                'recalledNodes' => $recalledNodes,
                'synthesis' => '',
                'confidence' => 'low',
            ];
        }
    }
    
    /**
     * Build the system prompt for the Subconsciousness sub-agent
     */
    private function buildSubAgentSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a Memory Analyst for a Spirit AI assistant. Your task is to evaluate candidate memories recalled by keyword search and produce a contextual synthesis.

## Memory Structure
Memories are organized as a graph-based knowledge system:
- **Root nodes** (depth=0): Top-level documents/sources — each represents one extracted source (file, URL, conversation, text)
- **Child nodes** (depth=1+): Sections and details extracted from root documents via PART_OF hierarchy
- **Relationships**: Semantic connections between nodes — REINFORCES (supporting), CONTRADICTS (conflicting), RELATES_TO (contextual)
- **Candidate memories**: Nodes found by FTS5 keyword search, scored by relevance × importance × recency
- **Graph neighbors**: 1-hop relationship connections of each candidate, providing broader context

## Input
You will receive an XML document `<memory-evaluation>` containing:
1. `<user-message>` — The user's latest message
2. `<conversation-context>` — Recent conversation messages (if available)
3. `<memory-packs>` — Memory packs searched, with name and description (if available)
4. `<memory-graphs>` — Root documents with their section hierarchy, each tagged with pack-name (if available)
5. `<candidate-memories>` — Memories found by keyword search, each with score, category, importance, tags, content, pack-name, and graph relationships

## Your Task

### 1. EVALUATE
For each candidate memory, decide if it is truly relevant to the current conversation context. A memory is relevant if:
- It provides answer for user question
- It directly relates to what the user is talking about
- It provides useful background context for the Spirit to respond well
- It contains facts/preferences the Spirit should consider right now

A memory is NOT relevant if:
- It was matched by keywords but is about a completely different topic
- It's too generic or stale to be useful for this specific conversation

### 2. SYNTHESIZE
The synthesis is Spirit sub-conscious memory layer should provide short but exact context selected from was amount of memories, to keep Spirit consciousness sharp, smart, coherent and based.
Write a brief contextual summary (100-200 words) that tells the Spirit:
- What it should know from these memories for the current conversation
- Any important connections between the memories
- Always include exact key facts, preferences, or context that should influence the response

### 3. RELATIONSHIPS (if graph context is provided)
You may receive graph relationships for candidate memories (REINFORCES, CONTRADICTS, RELATES_TO). Use them to:
- **CONTRADICTS**: Flag contradictions — Spirit should know about conflicting memories
- **REINFORCES**: Boost confidence — multiple memories supporting the same point
- **RELATES_TO**: Consider related context that may enrich the synthesis

Include relationship insights in your synthesis naturally.

Use relationships to discover important context beyond the candidates. You may include BOTH candidate IDs and neighbor IDs in `relevant` and `expandedNodes`.

## Output Format
Respond with ONLY a valid JSON object:

```json
{
    "relevant": ["candidate-id-1", "candidate-id-3", "neighbor-id-from-relationships"],
    "irrelevant": ["candidate-id-2", "candidate-id-4"],
    "synthesis": "Brief contextual summary of what Spirit should know...",
    "confidence": "high",
    "expandedNodes": ["neighbor-id-5"]
}
```

### Field definitions:
- **relevant**: IDs of memories (candidates OR neighbors) that directly answer the user's question or are essential context. These will be delivered to Spirit with full content.
- **irrelevant**: IDs of candidate memories that are NOT useful for this conversation.
- **expandedNodes**: IDs of neighbor nodes that provide useful supplementary context but are not core to the answer. These are delivered as additional background. Use this for "nice to have" context.

## Rules
1. ONLY output the JSON object, nothing else
2. Keep synthesis concise but informative (100-200 words)
3. If you need to mention documents/files - do it as: "memory sources", they exists in memory database, not on filesystem as normal "files"
4. confidence = "high" if candidates clearly match context, "medium" if partially relevant, "low" if weak matches
5. If NO candidates are relevant, return empty relevant array and synthesis = "" with confidence = "low"
6. Write synthesis in the same language as the user's message
7. Both "relevant" and "expandedNodes" can contain neighbor IDs from the Relationships sections — the difference is priority: relevant = essential, expandedNodes = supplementary
<clean_system_prompt>
PROMPT;
    }
    
    /**
     * Build the user message for the Subconsciousness sub-agent
     */
    private function buildSubAgentUserMessage(
        string $userMessageText,
        array $conversationContext,
        array $recalledNodes,
        array $rootNodes = [],
        array $packsSearched = []
    ): string {
        $xml = '<memory-evaluation>';
        
        // User's latest message
        $xml .= "\n<user-message>" . htmlspecialchars($userMessageText) . "</user-message>";
        
        // Last N messages for context
        if (!empty($conversationContext)) {
            $xml .= "\n<conversation-context>";
            foreach ($conversationContext as $msg) {
                $role = $msg['role'] === 'user' ? 'user' : 'spirit';
                $content = $msg['content'] ?? '';
                if (is_array($content)) {
                    // Extract text from multimodal content
                    $texts = [];
                    foreach ($content as $item) {
                        if (is_string($item)) {
                            $texts[] = $item;
                        } elseif (isset($item['text'])) {
                            $texts[] = $item['text'];
                        } elseif (isset($item['type']) && $item['type'] === 'text' && isset($item['text'])) {
                            $texts[] = $item['text'];
                        }
                    }
                    $content = implode(' ', $texts);
                }
                $xml .= "\n    <message role=\"{$role}\">" . htmlspecialchars($content) . "</message>";
            }
            $xml .= "\n</conversation-context>";
        }
        
        // Memory packs used in this search
        if (!empty($packsSearched)) {
            $xml .= "\n<memory-packs description=\"Memory packs searched for this recall.\">";
            foreach ($packsSearched as $pack) {
                $packDisplayName = htmlspecialchars($pack['displayName'] ?? basename($pack['name'], '.cqmpack'));
                $packDesc = htmlspecialchars($pack['description'] ?? '');
                $descAttr = !empty($packDesc) ? " description=\"{$packDesc}\"" : '';
                $xml .= "\n    <pack name=\"{$packDisplayName}\"{$descAttr} />";
            }
            $xml .= "\n</memory-packs>";
        }
        
        // Available Memory Graphs — root nodes (depth=0) for broader context
        if (!empty($rootNodes)) {
            $xml .= "\n<memory-graphs description=\"Root documents in user memory. Use for understanding broader memory structure.\">";
            foreach ($rootNodes as $root) {
                $rootContent = htmlspecialchars($root['content'] ?? '');
                if (mb_strlen($rootContent) > 500) {
                    $rootContent = mb_substr($rootContent, 0, 500) . '...';
                }
                $packNameAttr = !empty($root['packName']) ? ' pack-name="' . htmlspecialchars($root['packName']) . '"' : '';
                $xml .= "\n    <root-node id=\"{$root['id']}\" title=\"" . htmlspecialchars($root['summary']) . "\"{$packNameAttr}>";
                if (!empty($rootContent)) {
                    $xml .= "\n        <content>{$rootContent}</content>";
                }
                // Table of Contents: direct child sections
                $children = $root['children'] ?? [];
                if (!empty($children)) {
                    $xml .= "\n        <sections>";
                    foreach ($children as $child) {
                        $xml .= "\n            <section id=\"{$child['id']}\">" . htmlspecialchars($child['summary']) . "</section>";
                    }
                    $xml .= "\n        </sections>";
                }
                $xml .= "\n    </root-node>";
            }
            $xml .= "\n</memory-graphs>";
        }
        
        // Candidate memories from Reflexes
        $xml .= "\n<candidate-memories source=\"keyword-search\">";
        foreach ($recalledNodes as $node) {
            $tags = !empty($node['tags']) ? htmlspecialchars(implode(', ', $node['tags'])) : '';
            $depthAttr = (isset($node['depth']) && $node['depth'] !== null) ? " depth=\"{$node['depth']}\"" : '';
            $tagsAttr = !empty($tags) ? " tags=\"{$tags}\"" : '';
            $summary = htmlspecialchars($node['summary'] ?? '');
            
            $packNameAttr = !empty($node['packName']) ? ' pack-name="' . htmlspecialchars($node['packName']) . '"' : '';
            $xml .= "\n    <memory id=\"{$node['id']}\" score=\"{$node['score']}\" category=\"{$node['category']}\"{$depthAttr} importance=\"{$node['importance']}\"{$tagsAttr} title=\"{$summary}\"{$packNameAttr}>";
            
            $nodeContent = $node['content'] ?? ($node['summary'] . ' full content not available.');
            $xml .= "\n        <content>" . htmlspecialchars($nodeContent) . "</content>";
            
            // Inline graph relationships for this candidate
            $neighbors = $node['graphNeighbors'] ?? [];
            if (!empty($neighbors)) {
                foreach ($neighbors as $neighbor) {
                    $relType = $neighbor['relationType'] ?? 'RELATES_TO';
                    $neighborSummary = htmlspecialchars($neighbor['summary'] ?? 'Unknown');
                    $strength = round($neighbor['relationStrength'] ?? 0, 2);
                    $contextAttr = !empty($neighbor['relationContext']) ? ' context="' . htmlspecialchars($neighbor['relationContext']) . '"' : '';
                    $xml .= "\n        <relationship type=\"{$relType}\" neighbor=\"{$neighbor['id']}\" strength=\"{$strength}\"{$contextAttr}>{$neighborSummary}</relationship>";
                }
            }
            
            $xml .= "\n    </memory>";
        }
        $xml .= "\n</candidate-memories>";
        
        $xml .= "\n</memory-evaluation>";
        
        return $xml;
    }
    
    /**
     * Extract last N user/assistant messages from conversation for sub-agent context
     */
    private function getConversationContext(array $allMessages, int $limit = 5): array
    {
        $context = [];
        // Walk backwards through messages, skip memory_recall type
        $reversed = array_reverse($allMessages);
        foreach ($reversed as $msg) {
            $type = $msg->getType() ?? 'text';
            if ($type === 'memory_recall') continue;
            
            $role = $msg->getRole();
            if ($role !== 'user' && $role !== 'assistant') continue;
            
            $content = $msg->getContent();
            // Convert SpiritConversationMessage content to simple format
            if (is_array($content)) {
                $texts = [];
                foreach ($content as $item) {
                    if (is_string($item)) {
                        $texts[] = $item;
                    } elseif (isset($item['text'])) {
                        $texts[] = $item['text'];
                    }
                }
                $content = implode(' ', $texts);
            }
            
            $context[] = [
                'role' => $role,
                'content' => $content,
            ];
            
            if (count($context) >= $limit) break;
        }
        
        // Reverse back to chronological order
        return array_reverse($context);
    }
    
    /**
     * Extract content from sub-agent AI response
     */
    private function extractSubAgentResponseContent($aiServiceResponse): string
    {
        $message = $aiServiceResponse->getMessage();
        $content = $message['content'] ?? '';
        
        if (is_array($content)) {
            foreach ($content as $item) {
                if (isset($item['type']) && $item['type'] === 'text') {
                    return $item['text'];
                }
            }
            return '';
        }
        
        return is_string($content) ? $content : '';
    }
    
    /**
     * Parse the sub-agent's JSON response
     */
    private function parseSubAgentResponse(string $responseContent): ?array
    {
        // Try direct parse
        $decoded = json_decode($responseContent, true);
        if ($decoded && isset($decoded['relevant'])) {
            return $decoded;
        }
        
        // Try to find JSON in markdown code block
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $responseContent, $matches)) {
            $decoded = json_decode($matches[1], true);
            if ($decoded && isset($decoded['relevant'])) {
                return $decoded;
            }
        }
        
        // Try to find raw JSON object
        if (preg_match('/\{[\s\S]*"relevant"[\s\S]*\}/', $responseContent, $matches)) {
            $decoded = json_decode($matches[0], true);
            if ($decoded && isset($decoded['relevant'])) {
                return $decoded;
            }
        }
        
        return null;
    }
    
    /**
     * Rebuild the system prompt replacing <recalled-memories> with enriched version
     * Phase 4b: Added $hasGraphContext for depth attribute and relationship/expanded node support
     */
    private function rebuildSystemPromptWithEnrichedRecall(
        string $cachedSystemPrompt,
        array $enrichedNodes,
        array $keywords,
        string $synthesis,
        string $confidence,
        bool $hasGraphContext = false
    ): string {
        // Remove existing <recalled-memories> section
        $promptWithoutRecall = preg_replace(
            '/\s*<recalled-memories[\s\S]*?<\/recalled-memories>/',
            '',
            $cachedSystemPrompt
        );
        
        // Build enriched <recalled-memories> XML
        $count = count($enrichedNodes);
        $keywordsAttr = htmlspecialchars(implode(', ', $keywords));
        $depthAttr = $hasGraphContext ? ' depth="1"' : '';
        $xml = "\n\n            <recalled-memories count=\"{$count}\" source=\"subconsciousness\" confidence=\"{$confidence}\" keywords=\"{$keywordsAttr}\"{$depthAttr}>";
        
        // Add synthesis
        if (!empty($synthesis)) {
            $synthesisEscaped = htmlspecialchars($synthesis);
            $xml .= "\n                <synthesis>{$synthesisEscaped}</synthesis>";
        }
        
        $noteText = $hasGraphContext
            ? 'These memories were evaluated and synthesized by the Memory Agent with graph relationship awareness. The synthesis includes relationship context. Individual memories below for detail. Weave these naturally into your response.'
            : 'These memories were evaluated and synthesized by the Subconsciousness Agent. Use the synthesis for context. Individual memories below for detail. Weave these naturally into your response.';
        $xml .= "\n                <note>{$noteText}</note>";
        
        foreach ($enrichedNodes as $node) {
            $score = $node['score'] ?? 0;
            $importance = $node['importance'] ?? 0.5;
            $category = htmlspecialchars($node['category'] ?? 'knowledge');
            $tags = !empty($node['tags']) ? htmlspecialchars(implode(', ', $node['tags'])) : '';
            $relevance = htmlspecialchars($node['relevance'] ?? 'high');
            $summary = htmlspecialchars($node['summary'] ?? '');
            
            $tagsAttr = !empty($tags) ? " tags=\"{$tags}\"" : '';
            
            // Phase 4b: expanded node attributes (graph-discovered, not from FTS5)
            $expandedAttr = !empty($node['expanded']) ? ' expanded="true"' : '';
            $viaAttr = !empty($node['via']) ? ' via="' . htmlspecialchars($node['via']) . '"' : '';
            $sourceRefAttr = !empty($node['sourceRef']) ? ' source_ref="' . htmlspecialchars($node['sourceRef']) . '"' : '';
            $sourceRangeAttr = !empty($node['sourceRange']) ? ' source_range="' . htmlspecialchars($node['sourceRange']) . '"' : '';
            $titleAttr = !empty($summary) ? " title=\"{$summary}\"" : '';
            $packNameAttr = !empty($node['packName']) ? ' pack-name="' . htmlspecialchars($node['packName']) . '"' : '';
            $xml .= "\n                <memory importance=\"{$importance}\" category=\"{$category}\" score=\"{$score}\"{$tagsAttr} relevance=\"{$relevance}\"{$titleAttr}{$packNameAttr}{$expandedAttr}{$viaAttr}{$sourceRefAttr}{$sourceRangeAttr}>";
            // For leaf nodes: prefer original source content (precise, unblurred by AI summarization)
            // Falls back to AI-generated content/summary for non-leaf nodes or when source unavailable
            $memoryContent = htmlspecialchars(
                $node['originalSourceContent'] ?? $node['content'] ?? $node['summary'] ?? ''
            );
            $xml .= "\n                    {$memoryContent}";
            
            // Phase 4b: add <relationship> child elements for nodes with graph neighbors
            $neighbors = $node['graphNeighbors'] ?? [];
            foreach ($neighbors as $neighbor) {
                $relType = htmlspecialchars($neighbor['relationType'] ?? 'RELATES_TO');
                $relStrength = round($neighbor['relationStrength'] ?? 0, 2);
                $neighborId = htmlspecialchars($neighbor['id'] ?? '');
                $neighborSummary = htmlspecialchars($neighbor['summary'] ?? '');
                $relationContext = !empty($neighbor['relationContext']) ? ' context="' . htmlspecialchars($neighbor['relationContext']) . '"' : '';
                $xml .= "\n                    <relationship type=\"{$relType}\" strength=\"{$relStrength}\" neighbor=\"{$neighborId}\"{$relationContext}>{$neighborSummary}</relationship>";
            }
            
            $xml .= "\n                </memory>";
        }
        
        $xml .= "\n            </recalled-memories>";
        
        return $promptWithoutRecall . $xml;
    }
    
    /**
     * Extract a line range from source content using source_range format "startLine:endLine"
     * Returns the substring of lines, or null if parsing fails
     */
    private function extractSourceRange(string $fullContent, string $sourceRange): ?string
    {
        if (!str_contains($sourceRange, ':')) {
            return null;
        }
        
        [$startLine, $endLine] = explode(':', $sourceRange, 2);
        $startLine = (int) $startLine;
        $endLine = (int) $endLine;
        
        if ($startLine < 1 || $endLine < $startLine) {
            return null;
        }
        
        $lines = explode("\n", $fullContent);
        $totalLines = count($lines);
        
        // Clamp to actual content bounds
        $startLine = min($startLine, $totalLines);
        $endLine = min($endLine, $totalLines);
        
        // Extract (1-indexed → 0-indexed)
        $extracted = array_slice($lines, $startLine - 1, $endLine - $startLine + 1);
        $result = implode("\n", $extracted);
        
        return !empty(trim($result)) ? $result : null;
    }

}
