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

                    $this->logger->info('saveFilesFromMessage(): File created: ' . $newFile->getId() . ' ' . $newFile->getName() . ' (size: ' . $newFile->getSize() . ' bytes)');
                } catch (\Exception $e) {
                    // catch existing file 
                    if (strpos($e->getMessage(), 'File already exists') !== false) {
                        $this->logger->info('saveFilesFromMessage(): File already exists: ' . $content['file']['filename']);
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
     * Send message (returns immediately without executing tools)
     * 
     * @return array Contains message entity, type, toolCalls, and requiresToolExecution flag
     */
    public function sendMessageAsync(
        string $conversationId,
        \App\Entity\SpiritConversationMessage $userMessage,
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
        if (!$spirit) {
            throw new \Exception('Spirit not found');
        }
        
        // Get AI service model - use spirit-specific model if set, otherwise fall back to primary
        $aiServiceModel = $this->spiritService->getSpiritAiModel($spirit->getId());
        
        // Prepare messages for AI request (from message table)
        $messages = $this->prepareMessagesForAiRequestFromMessageTable($conversationId, $spirit, $lang);
        
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
            $this->logger->info('Tool calls: ' . json_encode($toolCalls));
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
                'UPDATE ai_service_request SET messages = "removed" 
                 WHERE id IN (SELECT ai_service_request_id FROM spirit_conversation_message WHERE ai_service_request_id IS NOT NULL)'
            );
            $db->executeStatement(
                'UPDATE ai_service_response SET message = "removed", full_response = "removed" 
                 WHERE id IN (SELECT ai_service_response_id FROM spirit_conversation_message WHERE ai_service_response_id IS NOT NULL)'
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
        string $lang
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
        
        // Add system message (same as existing implementation)
        $systemMessage = $this->buildSystemMessage($spirit, $lang);
        $aiMessages[] = [
            'role' => 'system',
            'content' => $systemMessage
        ];
        
        // Add conversation messages
        foreach ($messages as $message) {
            $role = $message->getRole();
            $content = $message->getContent();
            $type = $message->getType();
            
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
        
        $this->logger->info("Migrating conversation {$conversationId} from old format to new format", [
            'messageCount' => count($messages)
        ]);
        
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
        
        $this->logger->info("Successfully migrated conversation {$conversationId}");
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
                            $this->logger->info('Migrated Spirit memory file', [
                                'spiritId' => $spirit->getId(),
                                'file' => $fileName,
                                'from' => $oldMemoryDir,
                                'to' => $newMemoryDir
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
     * Contains: Spirit Memory v3 (graph-based) + legacy memory files (if not migrated)
     */
    public function buildMemorySection(Spirit $spirit): string
    {
        $spiritNameSlug = $this->slugger->slug($spirit->getName());
        $spiritMemoryDir = '/spirit/' . $spiritNameSlug . '/memory';
        
        // TMP, until next few releases
        // migration from single-spirit memory files to multi-spirit memory files
        $this->migrateMemoryFiles($spirit, '/spirit/memory', $spiritMemoryDir);
        
        // Check if legacy memory has been migrated to v3 (via pack)
        $memoryInfo = $this->spiritService->initSpiritMemory($spirit);
        $this->packService->open($memoryInfo['projectId'], $memoryInfo['packsPath'], $memoryInfo['rootPackName']);
        $migrationStatus = $this->packService->isLegacyMemoryMigrated();
        $this->packService->close();
        $isFullyMigrated = $migrationStatus['allMigrated'];
        
        // Build the memory section based on migration status
        if ($isFullyMigrated) {
            // V3 ONLY - Legacy memory has been fully migrated
            return $this->buildMemorySectionV3Only($spirit);
        } else {
            // HYBRID - Show both v3 and legacy (for backward compatibility during migration)
            return $this->buildMemorySectionHybrid($spirit, $spiritMemoryDir, $migrationStatus);
        }
    }
    
    /**
     * Build Memory section for fully migrated Spirits (v3 only)
     * Returns only the <spirit-memory-system> content (projects section is separate)
     */
    private function buildMemorySectionV3Only(Spirit $spirit): string
    {
        return "
            <spirit-memory-system version=\"3\">
                <overview>
                    Your memory is powered by **Spirit Memory v3** - a graph-based knowledge system.
                    Use the memory tools to store, recall, update, and forget information.
                    Memories are automatically scored by importance, recency, and relevance.
                </overview>
                
                <available-tools>
                    <tool name=\"memoryStore\">
                        Store new memories. Categories: conversation, thought, knowledge, fact, preference.
                        You can add tags and link to related memories.
                        Example: Store user preferences, important facts, conversation summaries.
                    </tool>
                    <tool name=\"memoryRecall\">
                        Search and retrieve memories by query, category, or tags.
                        Returns scored results with related memories.
                        Use this to remember things about the user or past conversations.
                    </tool>
                    <tool name=\"memoryUpdate\">
                        Update existing memories when information changes.
                        Creates new version of the memory from the old one.
                    </tool>
                    <tool name=\"memoryForget\">
                        Mark memories as forgotten (soft delete) when they become irrelevant.
                    </tool>
                    <tool name=\"memoryExtract\">
                        Extract memories from content using AI Sub-Agent. SMART FEATURES:
                        - Auto-loads content from files/conversations/URLs (no need to call getFileContent or fetchURL first!)
                        - Prevents duplicate extraction of same source
                        Use sourceType + sourceRef to auto-load:
                        - document: \"projectId:path:filename\" (e.g., \"general:/docs:readme.md\")
                        - spirit_conversation: conversation ID, or alias \"current\"/\"active\"/\"now\" for the most recent conversation, or \"all\" to batch-extract ALL conversations (skips already-extracted ones)
                        - url: URL string (e.g., \"https://example.com/article\")
                        Use 'force: true' to re-extract already processed sources.
                    </tool>
                </available-tools>
                
                <best-practices>
                    - Store important user preferences with category 'preference' and high importance (0.8-1.0)
                    - Store facts about the user with category 'fact'
                    - Store conversation summaries with category 'conversation' after meaningful interactions
                    - Use tags for easy retrieval (e.g., 'work', 'family', 'hobbies')
                    - Recall memories at the start of conversations to personalize responses
                    - Update memories when information changes rather than creating duplicates
                    - Keep memories concise and self-contained
                </best-practices>
            </spirit-memory-system>";
    }
    
    /**
     * Build Memory section for Spirits still using legacy memory (hybrid mode)
     */
    private function buildMemorySectionHybrid(Spirit $spirit, string $spiritMemoryDir, array $migrationStatus): string
    {
        // Get memory files content (legacy system)
        $memoryFiles = $this->getMemoryFilesContent($spirit, $spiritMemoryDir);
        
        // Build migration hint based on what's already migrated
        $migratedFiles = [];
        $notMigratedFiles = [];
        foreach ($migrationStatus['files'] as $file => $isMigrated) {
            if ($isMigrated) {
                $migratedFiles[] = $file . '.md';
            } else {
                $notMigratedFiles[] = $file . '.md';
            }
        }
        
        $migrationHint = '';
        if (!empty($migratedFiles)) {
            $migrationHint = "\n                    Already migrated: " . implode(', ', $migratedFiles);
        }
        if (!empty($notMigratedFiles)) {
            $migrationHint .= "\n                    Not yet migrated: " . implode(', ', $notMigratedFiles);
        }
        
        return "
            <spirit-memory-system>
                <overview>
                    You have TWO memory systems available:
                    1. **Spirit Memory v3** (PRIMARY, RECOMMENDED) - Graph-based knowledge system with AI tools
                    2. **Legacy File Memory** (DEPRECATED) - Markdown files in File Browser (will be phased out)
                    
                    IMPORTANT: Prefer using Spirit Memory v3 tools for all new memories. 
                    Use memoryExtract to migrate remaining legacy files to v3.{$migrationHint}
                </overview>
                
                <spirit-memory-v3>
                    <description>
                        A graph-based knowledge system where memories are nodes connected by relationships.
                        Use the memory tools to store, recall, update, and forget information.
                        Memories are automatically scored by importance, recency, and relevance.
                    </description>
                    
                    <available-tools>
                        <tool name=\"memoryStore\">
                            Store new memories. Categories: conversation, thought, knowledge, fact, preference.
                            You can add tags and link to related memories.
                            Example: Store user preferences, important facts, conversation summaries.
                        </tool>
                        <tool name=\"memoryRecall\">
                            Search and retrieve memories by query, category, or tags.
                            Returns scored results with related memories.
                            Use this to remember things about the user or past conversations.
                        </tool>
                        <tool name=\"memoryUpdate\">
                            Update existing memories when information changes.
                            Creates new version of the memory from the old one.
                        </tool>
                        <tool name=\"memoryForget\">
                            Mark memories as forgotten (soft delete) when they become irrelevant.
                        </tool>
                        <tool name=\"memoryExtract\">
                            Extract memories from content using AI Sub-Agent. SMART FEATURES:
                            - Auto-loads content from files/conversations/URLs (no need to call getFileContent or fetchURL first!)
                            - Prevents duplicate extraction of same source
                            Use sourceType + sourceRef to auto-load:
                            - legacy_memory/document: \"projectId:path:filename\" (e.g., \"general:/spirit/{$spirit->getName()}/memory:conversations.md\")
                            - spirit_conversation: conversation ID, or alias \"current\"/\"active\"/\"now\" for the most recent conversation, or \"all\" to batch-extract ALL conversations (skips already-extracted ones)
                            - url: URL string (e.g., \"https://example.com/article\")
                            Use 'force: true' to re-extract already processed sources.
                        </tool>
                    </available-tools>
                    
                    <best-practices>
                        - Store important user preferences with category 'preference' and high importance (0.8-1.0)
                        - Store facts about the user with category 'fact'
                        - Store conversation summaries with category 'conversation' after meaningful interactions
                        - Use tags for easy retrieval (e.g., 'work', 'family', 'hobbies')
                        - Recall memories at the start of conversations to personalize responses
                        - Update memories when information changes rather than creating duplicates
                        - Keep memories concise and self-contained
                    </best-practices>
                </spirit-memory-v3>
                
                <legacy-file-memory status=\"deprecated\">
                    <note>This system is being phased out. Use memoryExtract to migrate these files to Spirit Memory v3.</note>
                    <files>
                        <file>
                            <path>{$spiritMemoryDir}</path>
                            <name>conversations.md</name>
                            <content>
                                {$memoryFiles['conversations']['content']}
                            </content>
                        </file>
                        <file>
                            <path>{$spiritMemoryDir}</path>
                            <name>inner-thoughts.md</name>
                            <content>
                                {$memoryFiles['inner-thoughts']['content']}
                            </content>
                        </file>
                        <file>
                            <path>{$spiritMemoryDir}</path>
                            <name>knowledge-base.md</name>
                            <content>
                                {$memoryFiles['knowledge-base']['content']}
                            </content>
                        </file>
                    </files>
                </legacy-file-memory>
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
        $migrationStatus = $this->packService->isLegacyMemoryMigrated();
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
                'title' => 'Spirit Memory v3',
                'enabled' => $config['includeMemory'],
                'configKey' => 'includeMemory',
                'version' => 3,
                'stats' => $memoryStats,
                'migrationStatus' => $migrationStatus,
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

}
