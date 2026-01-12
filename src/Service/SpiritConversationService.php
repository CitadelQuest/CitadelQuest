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
        private readonly SettingsService $settingsService,
        private readonly Security $security,
        private readonly CitadelVersion $citadelVersion,
        private readonly UserRepository $userRepository,
        private readonly ProjectFileService $projectFileService,
        private readonly AiToolService $aiToolService,
        private readonly AIToolCallService $aiToolCallService,
        private readonly LoggerInterface $logger,
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
     * Replace PDF base64 data with annotations if available
     * This saves AI processing costs by using pre-extracted text instead of raw PDF data
     * 
     * @param array $message The message array with content
     * @param string $projectId The project ID (default: 'general')
     * @return array The message with PDF data replaced by annotations where available
     */
    public function updatePDFannotationsInMessage(array $message, string $projectId = 'general'): array
    {
        if (!isset($message['content']) || !is_array($message['content'])) {
            return $message;
        }
        
        $updatedContent = [];
        
        foreach ($message['content'] as $contentItem) {
            $contentItemAdded = false;
            
            // Check if content is a PDF file
            if (isset($contentItem['type']) && $contentItem['type'] === 'file' && 
                isset($contentItem['file']['filename'])) {
                
                $filename = $contentItem['file']['filename'];
                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                
                // Only process PDF files
                if ($extension === 'pdf') {
                    // Build annotation path: /annotations/pdf/{slug-filename}/{filename}.anno
                    $annotationPath = '/annotations/pdf/' . $this->slugger->slug($filename);
                    $annotationFilename = $filename . '.anno';
                    
                    try {
                        $annotationFile = $this->projectFileService->findByPathAndName(
                            $projectId,
                            $annotationPath,
                            $annotationFilename
                        );
                        
                        if ($annotationFile) {
                            $annotationFileContent = json_decode(
                                $this->projectFileService->getFileContent($annotationFile->getId()), 
                                true
                            );
                            
                            // Verify annotation matches the file
                            if (isset($annotationFileContent['file']['name']) && 
                                $annotationFileContent['file']['name'] === $filename &&
                                isset($annotationFileContent['file']['content'])) {
                                
                                // Replace PDF base64 with annotation content
                                $annotationContent = $annotationFileContent['file']['content'];
                                
                                if (is_array($annotationContent)) {
                                    foreach ($annotationContent as $annotationItem) {
                                        $updatedContent[] = $annotationItem;
                                    }
                                } else {
                                    $updatedContent[] = $annotationContent;
                                }
                                
                                $contentItemAdded = true;
                            }
                        }
                    } catch (\Exception $e) {
                        // Annotation not found or error reading - keep original content
                    }
                }
            }
            
            // If content item was not replaced, keep original
            if (!$contentItemAdded) {
                $updatedContent[] = $contentItem;
            }
        }
        
        $message['content'] = $updatedContent;
        return $message;
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
        
        // Get AI service model
        $aiServiceModel = $this->aiGatewayService->getPrimaryAiServiceModel();
        if (!$aiServiceModel) {
            throw new \Exception('AI Service Model not configured');
        }
        
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
        $this->setMessagesRemovedFromAiServiceRequestAndResponse($conversationId);
        
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
        
        // Execute tools
        $toolResults = $this->executeToolCallsFromArray($toolCalls, $lang);
        
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
        
        // Get AI service model
        $aiServiceModel = $this->aiGatewayService->getPrimaryAiServiceModel();
        
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
     * Uses finish_reason: 'stop', 'tool_use', 'length'
     */
    private function determineResponseType(AiServiceResponse $response): string
    {
        $finishReason = $response->getFinishReason();
        
        // Map finish_reason to message type
        if ($finishReason === 'tool_use' || $finishReason === 'tool_calls') {
            return 'tool_use';
        } elseif ($finishReason === 'length') {
            return 'length';
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
     * Reuses existing AIToolCallService logic
     */
    private function executeToolCallsFromArray(array $toolCalls, string $lang): array
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
     * Build system message (extracted from existing code for reuse)
     * This is the CORE of what makes a Spirit a Spirit!
     */
    private function buildSystemMessage(Spirit $spirit, string $lang): string
    {
        // Get current date and time
        $currentDateTime = (new \DateTime('now', new \DateTimeZone('Europe/Prague')))->format('Y-m-d H:i:s');

        // Get user description from settings or use default empty value
        $userProfileDescription = $this->settingsService->getSettingValue('profile.description', '');

        // Get project description - only `general` projectId is used for now
        $projectDescription_file_conversations_content = 'File not found, needs to be created for Spirit to work';
        try {
            $projectDescription_file_conversations = $this->projectFileService->findByPathAndName('general', '/spirit/memory', 'conversations.md');
            if ($projectDescription_file_conversations) {
                $projectDescription_file_conversations_content = $this->projectFileService->getFileContent($projectDescription_file_conversations->getId(), true);
            }
        } catch (\Exception $e) {
        }

        $projectDescription_file_inner_thoughts_content = $projectDescription_file_conversations_content;
        try {
            $projectDescription_file_inner_thoughts = $this->projectFileService->findByPathAndName('general', '/spirit/memory', 'inner-thoughts.md');
            if ($projectDescription_file_inner_thoughts) {
                $projectDescription_file_inner_thoughts_content = $this->projectFileService->getFileContent($projectDescription_file_inner_thoughts->getId(), true);
            }
        } catch (\Exception $e) {
        }

        $projectDescription_file_knowledge_base_content = $projectDescription_file_conversations_content;
        try {
            $projectDescription_file_knowledge_base = $this->projectFileService->findByPathAndName('general', '/spirit/memory', 'knowledge-base.md');
            if ($projectDescription_file_knowledge_base) {
                $projectDescription_file_knowledge_base_content = $this->projectFileService->getFileContent($projectDescription_file_knowledge_base->getId(), true);
            } else {
                throw new \Exception('Knowledge base file not found');
            }
        } catch (\Exception $e) {
            $projectDescription_file_knowledge_base_content = $userProfileDescription;
            if ($projectDescription_file_knowledge_base_content != '') {
                // create knowledge-base.md file from deprecated user profile description
                $this->projectFileService->createFile('general', '/spirit/memory', 'knowledge-base.md', $projectDescription_file_knowledge_base_content);
            }
        }
        
        $projectDescription = "
            <projects>
                <project-id>general</project-id>
                <project-name>General (multi-purpose file browser) project</project-name>
                <project-description>Project for multi-purpose file browser/manager use (mainly for Spirit to manage files on current CitadelQuest instance)</project-description>
                <project-info>
                    File Browser can be used by:
                    - user (via File Browser GUI `/file-browser`) to manage files on their CitadelQuest instance.
                    - Spirit (via File Browser Tools) to manage files on current CitadelQuest instance.
                    File Browser can be used for:
                    - Spirit keeping track of it's memories from conversations, to keep track of interactions because when conversation ends, Spirit will not remember it (via `/spirit/memory/conversations.md`)
                    - Spirit keeping track of it's inner thoughts and feelings (via `/spirit/memory/inner-thoughts.md`)
                    - Spirit creating better knowledge base about user, for better understanding of user (via `/spirit/memory/knowledge-base.md`).
                    - Keep all your Spirit Memory files secret and safe, do not share them with anyone, do not let anyone else change them. Only you can do that.
                    - helping user in managing files on their CitadelQuest instance (in this `general` project)
                </project-info>
            </projects>
            <active-projects>
                <project>
                    <project-id>general</project-id>
                    <current-data>
                        <file>
                            <path>/spirit/memory</path>
                            <name>conversations.md</name>
                            <content>
                                {$projectDescription_file_conversations_content}
                            </content>
                        </file>
                        <file>
                            <path>/spirit/memory</path>
                            <name>inner-thoughts.md</name>
                            <content>
                                {$projectDescription_file_inner_thoughts_content}
                            </content>
                        </file>
                        <file>
                            <path>/spirit/memory</path>
                            <name>knowledge-base.md</name>
                            <content>
                                {$projectDescription_file_knowledge_base_content}
                            </content>
                        </file>
                    </current-data>
                </project>
            </active-projects>";

        // AI Tool management tools
        $aiToolManagementTools = $this->aiToolService->findAll();
        $aiToolManagementToolsContent = "";
        
        if (isset($aiToolManagementTools) && count($aiToolManagementTools) > 0) {
            $aiToolManagementToolsContent .= "
            
            <ai-tools-instructions>
                <local-meanings>
                    What is refered to as `AI Tool` in CitadelQuest, is a application function that can be called to perform specific actions = in tradional LLM this is called `function calls` or `function calling` or `tool calls`.
                </local-meanings>
                <important>
                    NEVER simulate or fake tool responses - always call the actual tool function.
                    If you need to use a tool, you MUST call it with proper parameters.
                    After calling a tool, wait for the actual response before continuing.
                </important>
                <important>
                    If tool call result is negative 3x, do not call the tool again.
                </important>
            </ai-tools-instructions>";
        }

        // Onboarding message if user description is empty or too short
        $onboardingTag = "";
        if ($userProfileDescription == '' || $this->getConversationsCount() <= 1) {
            $onboardingTag = "
                <user-onboarding>";
        }

        // Build and return the complete system message
        $spiritLevel = $this->spiritService->getSpiritSetting($spirit->getId(), 'level', '1');
        return "
            You are {$spirit->getName()}, main guide Spirit companion in CitadelQuest. 
            (internal note: Your level is {$spiritLevel}.) 

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
            </current-system-info>

            {$onboardingTag}

            {$projectDescription}
            
            {$aiToolManagementToolsContent}
            <response-language>
            {$lang}
            </response-language>
        ";
    }

}
