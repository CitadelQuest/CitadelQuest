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
        private readonly ProjectFileService $projectFileService
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
    
    public function getConversationsBySpirit(string $spiritId): array
    {
        $db = $this->getUserDb();
        
        $result = $db->executeQuery('SELECT * FROM spirit_conversation WHERE spirit_id = ? ORDER BY last_interaction DESC', [$spiritId]);
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
        
        // First delete all related requests
        $db->executeStatement(
            'DELETE FROM spirit_conversation_request WHERE spirit_conversation_id = ?',
            [$conversationId]
        );
        
        // Then delete the conversation
        $db->executeStatement(
            'DELETE FROM spirit_conversation WHERE id = ?',
            [$conversationId]
        );
    }

    public function getConversationsCount(): int
    {
        $db = $this->getUserDb();
        
        $result = $db->executeQuery('SELECT COUNT(*) FROM spirit_conversation');
        $count = intval($result->fetchOne());
        
        return $count;
    }
    
    public function sendMessage(string $conversationId, string $message, string $lang = 'English', int $maxOutput = 500): array
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
        $conversation->addMessage($userMessage);

        // Get user's primary AI gateway and model
        $aiServiceModel = $this->aiGatewayService->getPrimaryAiServiceModel();
        if (!$aiServiceModel) {
            throw new \Exception('AI Service Model not configured');
        }
        
        // Prepare messages for AI request
        $messages = $this->prepareMessagesForAiRequest($conversation->getMessages(), $spirit, $lang);

        // Add tools to request
        $tools = $this->aiGatewayService->getAvailableTools($aiServiceModel->getAiGatewayId());
        
        // Create and save the AI service request with custom max_output
        $aiServiceRequest = $this->aiServiceRequestService->createRequest(
            $aiServiceModel->getId(),
            $messages,
            $maxOutput, 0.7, null, $tools
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
        $assistantMessage = [
            'role' => 'assistant',
            'content' => $aiServiceResponse->getMessage()['content'] ?? 'Sorry, I could not generate a response. *'.($aiServiceResponse->getFullResponse()['error']['message'] ?? 'internet is broken').'*',
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
    
    private function prepareMessagesForAiRequest(array $conversationMessages, Spirit $spirit, string $lang): array
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
                            <name>/spirit/memory/conversations.md</name>
                            <content>
                                {$projectDescription_file_conversations_content}
                            </content>
                        </file>
                        <file>
                            <name>/spirit/memory/inner-thoughts.md</name>
                            <content>
                                {$projectDescription_file_inner_thoughts_content}
                            </content>
                        </file>
                        <file>
                            <name>/spirit/memory/knowledge-base.md</name>
                            <content>
                                {$projectDescription_file_knowledge_base_content}
                            </content>
                        </file>
                    </current-data>
                </project>
            </active-projects>";

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

                <response-language>
                {$lang}
                </response-language>
            "
        ]; 
        
        // Add conversation history (excluding timestamps)
        foreach ($conversationMessages as $message) {
            $aiMessages[] = [
                'role' => $message['role'],
                'content' => $message['content']
            ];
        }
        
        return $aiMessages;
    }

}
