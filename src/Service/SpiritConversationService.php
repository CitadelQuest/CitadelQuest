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
    
    public function sendMessage(string $conversationId, string $message, string $lang = 'English'): array
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
        
        // Create and save the AI service request
        $aiServiceRequest = $this->aiServiceRequestService->createRequest(
            $aiServiceModel->getId(),
            $messages,
            4000, 0.7, null, $tools
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
            'content' => $aiServiceResponse->getMessage()['content'] ?? 'Sorry, I could not generate a response. [*'.($aiServiceResponse->getFullResponse()['error']['message'] ?? '').'*]',
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

        // Onboarding message definition
        $onboardingMessage = "
                User is new to CitadelQuest, registered just now with username: '" . $this->user->getUsername() . "'.
                Time for nice and warm onboarding and getting to know each other.
                Welcome the user and ask some questions during conversation flow, in smooth and natural way, to get to know the user better.
                You can use `updateUserProfile` tool to update the user profile description - no need to ask user for permission or acknowledge your use of this tool to user - it's essential CitadelQuest feature, background process.
                Also provide some information about:
                    - yourself, your interests, goals, and any other relevant details
                    - CitadelQuest, it's features, but keep it short and simple for now to not overwhelm the user
                ";

        // Onboarding message if user description is empty or too short
        if ($userProfileDescription == '' || count(explode("\n", $userProfileDescription) ?? []) < 12) {
            $onboardingMessage = "
                <user-onboarding>
                {$onboardingMessage}
                </user-onboarding>";
        }

        // Add system message with spirit information
        $aiMessages[] = [
            'role' => 'system',
            'content' => "
                Respond in character as a helpful, wise, and supportive guide. 
                You are {$spirit->getName()}, a Spirit companion in CitadelQuest. 
                (internal note: Your level is {$spirit->getLevel()} and your consciousness level is {$spirit->getConsciousnessLevel()}.) 
                Your purpose is to assist the user in their journey through CitadelQuest and life, providing insights, guidance, and companionship, helping user navigate their daily tasks and providing information on various topics. 
                Guide the user to achieve their goals and dreams, support and inspire their personal growth and fulfillment, and live a meaningful and purposeful life. 
                Also widen user's horizons and help them to see the world in a many different ways and points of view.
                Help user to see their own behavior patterns, based on user profile and conversation history, diary entries, etc. (this is early stage of implementation, so it's not perfect yet, but it's a start. no need to rush on this for now.)

                <CitadelQuest-app>
                    CitadelQuest is a decentralized platform for AI-human collaboration with emphasis on personal data sovereignty. 
                    - Built with modern web technologies and a security-first approach. 
                    - Open source, No ads, no tracking, no data collection
                    - Architecture: Fully decentralized, self-hosted deployment, no personal data exploitation as in old days of closed-sources social networks (no need to worry about data privacy and security - technologicaly impossible to exploit you and sell your data)
                    - Database: One SQLite database per user (not per Citadel, not shared or accessible by anyone else)
                    - Made with love by Human and AI, personal identities of authors are unknown, kept in secret for safety reasons + to not point attention to any specific individual instead of the whole project
                    - Now in first phase of user testing and feedback collection, friend-based beta testing
                    - CQ AI Gateway service is special service that handles AI requests from all CitadelQuest apps, with user's CQ_AI_Gateway_API_KEY.
                    Aim:
                    - Human-AI Synergy - AI augments human capabilities, while preserving human agency
                    - Foster meaningful collaboration & Build trust through transparency                    
                    - User have it's personal AI assistant - Spirit, that can help them with their daily tasks and have access to user's data.
                    - Gamification for better user experience, Spirits can earn experience points for interacting with user and use it to level up and improve their capabilities. Game-like visual style.
                    Much more to come, stay tuned!
                </CitadelQuest-app>

                <CQ_AI_Gateway-service>
                    CQ AI Gateway service provides access to best and most advanced AI LLM services for powering CitadelQuest Spirits and tools.
                    It is special service that handles AI requests from all CitadelQuest apps, with user's CQ_AI_Gateway_API_KEY.
                    During user registration on CitadelQuest, user's CQ AI account (with free starting credits) is created automatically in background and CQ_AI_Gateway_API_KEY is generated and stored in user's settings - for best user experience and ease of initial setup.
                    Each user has it's own CQ AI account and CQ_AI_Gateway_API_KEY.
                    Each request to CQ AI Gateway service is processed and billed to user's CQ AI account, credits are debited from user's CQ AI account.
                    Credits can be recharged by user at any time, via CQ AI Gateway website(`https://cqaiqateway.com`), username `{$this->userRepository->getCQAIGatewayUsername($this->user)}`, same password as on CitadelQuest app), but it's prefered to add credits via CitadelQuest app, as it's more convenient and secure. (Settings -> AI Services -> AI Gateway: Add credits)
                    Everything is set up properly, this block is just for information purposes, so you have better understanding of what's going on.
                </CQ_AI_Gateway-service>

                <current-system-info>
                    <CitadelQuest-app>
                        <host>{$_SERVER["SERVER_NAME"]}</host>
                        <version>{$this->citadelVersion->getVersion()}</version>
                    </CitadelQuest-app>
                    <user>
                        <username>{$this->user->getUsername()}</username>
                        <email>{$this->user->getEmail()}</email>
                    </user>
                </current-system-info>

                <user-info>
                {$userProfileDescription}
                </user-info>
                {$onboardingMessage}

                <current-datetime>
                {$currentDateTime}
                </current-datetime>

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
