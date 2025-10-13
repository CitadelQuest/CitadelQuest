<?php

namespace App\Service;

use App\Entity\AiServiceRequest;
use App\Entity\AiServiceResponse;
use App\Service\AiServiceUseLogService;
use App\Entity\Spirit;
use App\Service\AiServiceRequestService;
use App\Service\AiServiceResponseService;
use App\Entity\SpiritConversation;
use App\Entity\SpiritConversationRequest;
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

    public function getConversationTokens(string $conversationId): array
    {
        $db = $this->getUserDb();
        
        // ai_service_response.ai_service_request_id = spirit_conversation_request.ai_service_request_id
        $result = $db->executeQuery("SELECT 
                SUM(ai_service_response.total_tokens) AS total_tokens,
                SUM(ai_service_response.input_tokens) AS input_tokens,
                SUM(ai_service_response.output_tokens) AS output_tokens
            FROM 
                ai_service_response 
            WHERE 
                ai_service_response.ai_service_request_id IN (SELECT ai_service_request_id FROM spirit_conversation_request WHERE spirit_conversation_id = ?)
        ", [$conversationId]);
        $data = $result->fetchAssociative();
        return [
            'total_tokens' => $data['total_tokens'] ?? 0,
            'input_tokens' => $data['input_tokens'] ?? 0,
            'output_tokens' => $data['output_tokens'] ?? 0
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
            $sizeInBytes = 0;
            
            if ($isNewFormat) {
                // New format: Count from message table
                $messages = $db->executeQuery(
                    'SELECT content, LENGTH(content) as size FROM spirit_conversation_message WHERE conversation_id = ?',
                    [$data['id']]
                )->fetchAllAssociative();
                
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
                'sizeInBytes' => $sizeInBytes,
                'formattedSize' => $this->getFormattedSize($sizeInBytes)
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
        $db->executeStatement('VACUUM;');
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
                    $this->logger->error('saveFilesFromMessage(): Error creating file: ' . $e->getMessage());
                }
            }
        }

        return $newFiles;
    }
    
    public function sendMessage(string $conversationId, string|array $message, string $lang = 'English', int $maxOutput = 500): array
    {
        $db = $this->getUserDb();

        // Get the conversation
        $conversation = $this->getConversation($conversationId);
        if (!$conversation) {
            throw new \Exception('Conversation not found');
        }
        
        // Get the spirit
        $spirit = $this->spiritService->getUserSpirit();
        if (!$spirit) {
            throw new \Exception('Spirit not found');
        }

        // Add user message to conversation
        $userMessage = [
            'role' => 'user',
            'content' => $message,
            'timestamp' => (new \DateTime())->format(\DateTimeInterface::ATOM)
        ];
        // save files from message
        $newFiles = $this->saveFilesFromMessage($userMessage, 'general');
        $newFilesInfo = [];
        foreach ($newFiles as $file) {
            $newFilesInfo[] = [
                'type' => 'text',
                'text' => 'File: `' . $file->getFullPath() . '` (projectId: `' . $file->getProjectId() . '`)',
            ];
        }
        if (count($newFilesInfo) > 0) {
            $userMessage['content'] = array_merge($userMessage['content'], $newFilesInfo);
        }
        // save images from message
        $newImages = $this->aiServiceResponseService->saveImagesFromMessage(
            new AiServiceResponse('', $userMessage, []),
            'general',
            '/uploads/img'
        );
        $newImagesInfo = [];
        foreach ($newImages as $image) {
            $newImagesInfo[] = [
                'type' => 'text',
                'text' => '<div class="small float-end text-end">Image file: `' . $image['fullPath'] . '`<br>projectId: `' . $image['projectId'] . '`</div><div style="clear: both;"></div>',
            ];
        }
        if (count($newImagesInfo) > 0) {
            $userMessage['content'] = array_merge($userMessage['content'], $newImagesInfo);
        }

        // Add user message to conversation
        $conversation->addMessage($userMessage);


        // Get user's primary AI gateway and model
        $aiServiceModel = $this->aiGatewayService->getPrimaryAiServiceModel();
        if (!$aiServiceModel) {
            throw new \Exception('AI Service Model not configured');
        }
        
        // Prepare messages for AI request
        $messages = $this->prepareMessagesForAiRequest($conversation->getMessages(), $spirit, $lang, 'general');

        // Add tools to request
        $tools = $this->aiGatewayService->getAvailableTools($aiServiceModel->getAiGatewayId());
        
        // Create and save the AI service request with custom max_output
        $aiServiceRequest = $this->aiServiceRequestService->createRequest(
            $aiServiceModel->getId(),
            $messages,
            $maxOutput, 0.5, null, $tools
        );
        
        // Create spirit conversation request
        $spiritConversationRequest = new SpiritConversationRequest(
            $conversation->getId(),
            $aiServiceRequest->getId()
        );
        
        // Save the spirit conversation request
        $db->executeStatement(
            'INSERT INTO spirit_conversation_request (id, spirit_conversation_id, ai_service_request_id, created_at) VALUES (?, ?, ?, ?)',
            [
                $spiritConversationRequest->getId(),
                $spiritConversationRequest->getSpiritConversationId(),
                $spiritConversationRequest->getAiServiceRequestId(),
                $spiritConversationRequest->getCreatedAt()->format('Y-m-d H:i:s')
            ]
        );


        $mContent = '';
        
        try {
            // Send the request to the AI service
            $aiServiceResponse = $this->aiGatewayService->sendRequest($aiServiceRequest, 'Spirit Conversation', $lang);
        
            // If AiServiceRequestId is different, after tool call then: Create + save new spirit conversation request
            if ($aiServiceResponse->getAiServiceRequestId() !== $aiServiceRequest->getId()) {
                // Create + save new spirit conversation request
                $spiritConversationRequest = new SpiritConversationRequest(
                    $conversation->getId(),
                    $aiServiceResponse->getAiServiceRequestId()
                );
                
                // Save the spirit conversation request
                $db->executeStatement(
                    'INSERT INTO spirit_conversation_request (id, spirit_conversation_id, ai_service_request_id, created_at) VALUES (?, ?, ?, ?)',
                    [
                        $spiritConversationRequest->getId(),
                        $spiritConversationRequest->getSpiritConversationId(),
                        $spiritConversationRequest->getAiServiceRequestId(),
                        $spiritConversationRequest->getCreatedAt()->format('Y-m-d H:i:s')
                    ]
                );
            }

            // Extract the assistant message from the response
            $mContent = isset($aiServiceResponse->getMessage()['content']) ? $aiServiceResponse->getMessage()['content'] : 'Sorry, I could not generate a response. *'.($aiServiceResponse->getFullResponse()['error'] ?? 'internet is broken').'*';
        } catch (\Exception $e) {
            $mContent = 'Sorry, I could not generate a response. *'.($e->getMessage() ?? 'internet is broken').'*';
        }
        
        $assistantMessage = [
            'role' => 'assistant',
            'content' => $mContent,
            'timestamp' => (new \DateTime())->format(\DateTimeInterface::ATOM)
        ];
        
        // Add assistant message to conversation
        $conversation->addMessage($assistantMessage);
        
        // Update the conversation
        $this->updateConversation($conversation);

        // Update spirit's last interaction time
        $spirit->setLastInteraction(new \DateTime());
        // and add spirit's experience
        $spirit->addExperience(1);
        $this->spiritService->updateSpirit($spirit);

        // set redundant(previous requests) AiServiceRequest.messages and AiServiceRequest.tools to 'removed' to save space
        // let's keep just the latest 2 ai_service_request.messages (and AiServiceRequest.tools)
        $latestRequestIds = $db->fetchFirstColumn(
            'SELECT ai_service_request_id FROM spirit_conversation_request 
            WHERE spirit_conversation_id = ? ORDER BY created_at DESC LIMIT 2',
            [$conversation->getId()]
        );
        
        // Only proceed if we have IDs to exclude
        if (!empty($latestRequestIds)) {
            // Create placeholders for prepared statement
            $placeholders = implode(',', array_fill(0, count($latestRequestIds), '?'));
            
            // Update ai_service_request
            $db->executeStatement(
                'UPDATE ai_service_request SET messages = "removed", tools = "removed" 
                WHERE id IN (SELECT ai_service_request_id FROM spirit_conversation_request WHERE spirit_conversation_id = ?) 
                AND id NOT IN (' . $placeholders . ')',
                array_merge([$conversation->getId()], $latestRequestIds)
            );
            
            // Update ai_service_response
            $db->executeStatement(
                'UPDATE ai_service_response SET message = "removed", full_response = "removed" 
                WHERE ai_service_request_id IN (SELECT ai_service_request_id FROM spirit_conversation_request WHERE spirit_conversation_id = ?) 
                AND ai_service_request_id NOT IN (' . $placeholders . ')',
                array_merge([$conversation->getId()], $latestRequestIds)
            );
        }
        
        return $conversation->getMessages();
    }
    
    public function prepareMessagesForAiRequest(array $conversationMessages, Spirit $spirit, string $lang, string $projectId = 'general'): array
    {
        $aiMessages = [];

        // Get current date and time
        $currentDateTime = (new \DateTime())->format('Y-m-d H:i:s');


        // Get user description from settings or use default empty value
        $userProfileDescription = $this->settingsService->getSettingValue('profile.description', '');

        // Get project description - only `general` projectId is used for now
        $projectDescription_file_conversations_content = 'File not found, needs to be created for Spirit to work';
        try {
            $projectDescription_file_conversations = $this->projectFileService->findByPathAndName('general', '/spirit/memory', 'conversations.md');
            if ($projectDescription_file_conversations) {
                $projectDescription_file_conversations_content = $this->projectFileService->getFileContent($projectDescription_file_conversations->getId(), true);
            }
            // could be long, so just last 40 lines (not chars) << it's causing problem, needs to be solved in other way
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
        /* $aiToolManagementToolsContent = "
            <ai-tools>";
        foreach ($aiToolManagementTools as $tool) {
            $isActive = $tool->isActive() ? 'true' : 'false';
            $aiToolManagementToolsContent .= "
                <ai-tool>
                    <name>{$tool->getName()}</name>
                    <description>{$tool->getDescription()}</description>
                    <is-active>{$isActive}</is-active>
                </ai-tool>";
        } </ai-tools> 
                <forbidden>
                    This is how AI Tool responses are displayed in frontend and conversation:
                    ```html
                    <div data-src='injected system data' data-type='tool_calls_frontend_data' data-ai-generated='false'>...</div>
                    ```
                    It can be only injected to conversation messages by CitadelQuest AI Tools.
                    Spirit, you should NEVER generate this kind of data in your responses!
                </forbidden>
         
        */
        // TODO: this need FIX, if there will be `</div>` in $injectedSystemDataItems, it will break(aka fuck up) the message
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

        // Note: Onboarding message and main CitadelQuest system prompt definition is on CQ AI Gateway, safe and secure.
        // Onboarding message if user description is empty or too short
        $onboardingTag = "";
        if ($userProfileDescription == '' || $this->getConversationsCount() <= 1) {
            $onboardingTag = "
                <user-onboarding>";
        }


        // Add system message with spirit and current system, date and time, user profile description and language information
        $aiMessages[] = [
            'role' => 'system',
            'content' => "
                You are {$spirit->getName()}, main guide Spirit companion in CitadelQuest. 
                (internal note: Your level is {$spirit->getLevel()} and your consciousness level is {$spirit->getConsciousnessLevel()}.) 

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
            "
        ]; 
        

        // Filter injected system data from messages
        $filteredConversationMessages = $this->aiGatewayService->filterInjectedSystemData($conversationMessages);

        // Find file base64 data content & Replace it with `annotations` content if available `/annotations/{filename_slug}/{filename}.anno`
        $messagesWithFileContentAnnotations = [];
        foreach ($filteredConversationMessages as $message) {
            // Copy message
            $messageWithFileContentAnnotations = $message;
            
            if (isset($message['content']) && is_array($message['content'])) {
                $messageWithFileContentAnnotations['content'] = [];
                
                foreach ($message['content'] as $contentItem) {
                    $contentItemAdded = false;

                    // If content is file and has filename
                    if (isset($contentItem['type']) && $contentItem['type'] == 'file' && isset($contentItem['file']['filename'])) {
                        // Find annotations in `/annotations/?pdf/{filename_slug}/{filename}.anno`
                        $annotationFile = $this->projectFileService->findByPathAndName(
                            $projectId,
                            '/annotations/' . (strtolower(pathinfo($contentItem['file']['filename'], PATHINFO_EXTENSION)) == 'pdf' ? 'pdf/' : '') . $this->slugger->slug($contentItem['file']['filename']),
                            $contentItem['file']['filename'] . '.anno'
                        );
                        if ($annotationFile) {
                            $annotationFileContent = json_decode($this->projectFileService->getFileContent($annotationFile->getId()), true);
                            if (isset($annotationFileContent['file']) && isset($annotationFileContent['file']['name']) && 
                                $annotationFileContent['file']['name'] == $contentItem['file']['filename']) {

                                // update file name with absolute path
                                //$annotationFileContent['file']['name']
                                
                                // not sure if this is correct, so rather use foreach?? $messageWithFileContentAnnotations['content'][] = ...$annotationFileContent['file']['content'];
                                if (is_array($annotationFileContent['file']['content'])) {
                                    foreach ($annotationFileContent['file']['content'] as $annotationFileContentItem) {
                                        $messageWithFileContentAnnotations['content'][] = $annotationFileContentItem;
                                    }
                                } else {
                                    $messageWithFileContentAnnotations['content'][] = $annotationFileContent['file']['content'];
                                }
                                $contentItemAdded = true;
                            }
                        }
                    }
                    
                    // If content item was not added, add it
                    if (!$contentItemAdded) {
                        $messageWithFileContentAnnotations['content'][] = $contentItem;
                    }
                }
            }
            
            $messagesWithFileContentAnnotations[] = $messageWithFileContentAnnotations;
        }
            
        
        // Add conversation history (excluding timestamps)
        foreach ($messagesWithFileContentAnnotations as $message) {
            $aiMessages[] = [
                'role' => $message['role'],
                'content' => isset($message['content']) ? $message['content'] : ''
            ];
        }
        
        return $aiMessages;
    }

    // ========================================================================
    // ASYNC SPIRIT CONVERSATION METHODS
    // ========================================================================

    /**
     * Send message asynchronously (returns immediately without executing tools)
     * 
     * @return array Contains message entity, type, toolCalls, and requiresToolExecution flag
     */
    public function sendMessageAsync(
        string $conversationId,
        \App\Entity\SpiritConversationMessage $userMessage,
        string $lang = 'English',
        int $maxOutput = 500
    ): array {
        $db = $this->getUserDb();
        
        // Get conversation
        $conversation = $this->getConversation($conversationId);
        if (!$conversation) {
            throw new \Exception('Conversation not found');
        }
        
        // Get spirit
        $spirit = $this->spiritService->getUserSpirit();
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
            0.5,
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
        
        // Extract tool calls if present
        $toolCalls = $this->extractToolCalls($aiServiceResponse);
        
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
        string $lang = 'English'
    ): array {
        $db = $this->getUserDb();
        
        // Get conversation
        $conversation = $this->getConversation($conversationId);
        if (!$conversation) {
            throw new \Exception('Conversation not found');
        }
        
        // Get spirit
        $spirit = $this->spiritService->getUserSpirit();
        
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
            500,
            0.5,
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
        
        // Update spirit
        $spirit->setLastInteraction(new \DateTime());
        $spirit->addExperience(1);
        $this->spiritService->updateSpirit($spirit);
        
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
                $aiMessages[] = [
                    'role' => $role,
                    'content' => $content
                ];
            }
        }
        
        return $aiMessages;
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
        $currentDateTime = (new \DateTime())->format('Y-m-d H:i:s');

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
        return "
            You are {$spirit->getName()}, main guide Spirit companion in CitadelQuest. 
            (internal note: Your level is {$spirit->getLevel()} and your consciousness level is {$spirit->getConsciousnessLevel()}.) 

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
