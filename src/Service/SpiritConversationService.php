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
    
    public function getConversationsBySpirit(string $spiritId): array
    {
        $db = $this->getUserDb();
        
        $result = $db->executeQuery('SELECT id, spirit_id, title, created_at, last_interaction FROM spirit_conversation WHERE spirit_id = ? ORDER BY last_interaction DESC', [$spiritId]);
        $results = $result->fetchAllAssociative();
        
        $conversations = [];
        foreach ($results as $data) {
            $conversations[] = SpiritConversation::fromArray($data);
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
        
        // First delete related: 
        //  all ai_service_request related to spirit_conversation_request
        //  all ai_service_response related to ai_service_request
        //  all spirit_conversation_request related to conversation

        // get all spirit_conversation_request related to conversation
        $result = $db->executeQuery(
            'SELECT * FROM spirit_conversation_request WHERE spirit_conversation_id = ?',
            [$conversationId]
        );
        $ai_service_request_array = $result->fetchAllAssociative();
        $ai_service_request_ids_list = implode(',', array_map(function($ai_service_request) {
            return '"' . $ai_service_request['ai_service_request_id'] . '"';
        }, $ai_service_request_array));

        $db->beginTransaction();

            // delete all ai_service_request related to spirit_conversation_request
            $db->executeStatement(
                'DELETE FROM ai_service_request WHERE id IN (' . $ai_service_request_ids_list . ')'
            );
            // delete all ai_service_response related to ai_service_request (related to spirit_conversation_request)
            $db->executeStatement(
                'DELETE FROM ai_service_response WHERE ai_service_request_id IN (' . $ai_service_request_ids_list . ')'
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
                    - keeping track of Spirit's memories from conversations (via `/spirit/memory/conversations.md`)
                    - keeping track of Spirit's inner thoughts and feelings (via `/spirit/memory/inner-thoughts.md`)
                    - creating better knowledge base about user, for better Spirit's understanding of user (via `/spirit/memory/knowledge-base.md`).
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
        $aiToolManagementToolsContent = "
            <ai-tools>";
        foreach ($aiToolManagementTools as $tool) {
            $isActive = $tool->isActive() ? 'true' : 'false';
            $aiToolManagementToolsContent .= "
                <ai-tool>
                    <name>{$tool->getName()}</name>
                    <description>{$tool->getDescription()}</description>
                    <is-active>{$isActive}</is-active>
                </ai-tool>";
        }
        // TODO: this need FIX, if there will be `</div>` in $injectedSystemDataItems, it will break(aka fuck up) the message
        $aiToolManagementToolsContent .= "
            </ai-tools>
            <ai-tools-instructions>
                <instruction>Always make function_calling in your responses - if you need to use any AI Tools.</instruction>
                <instruction>Be careful with AI Tools, they can be dangerous if not used correctly.</instruction>
                <instruction>Always check here in `ai-tools` if the tool is active before using it, you can use `setAIToolActive` to activate it if needed.</instruction>
                <important>
                    This is how AI Tool responses are displayed in frontend and conversation:
                    ```html
                    <div data-src='injected system data' data-type='tool_calls_frontend_data' data-ai-generated='false'>...</div>
                    ```
                    It can be only injected to conversation messages by CitadelQuest AI Tools.
                    Spirit should never generate this kind of data in their responses!
                </important>
            </ai-tools-instructions>";

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

}
