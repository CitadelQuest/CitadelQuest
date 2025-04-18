<?php

namespace App\Service;

use App\Entity\AiServiceRequest;
use App\Entity\AiServiceResponse;
use App\Service\AiServiceUseLogService;
use App\Entity\Spirit;
use App\Entity\AiUserSettings;
use App\Service\AiUserSettingsService;
use App\Service\AiServiceRequestService;
use App\Service\AiServiceResponseService;
use App\Entity\SpiritConversation;
use App\Entity\SpiritConversationRequest;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

class SpiritConversationService
{
    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly AiGatewayService $aiGatewayService,
        private readonly AiServiceRequestService $aiServiceRequestService,
        private readonly AiServiceResponseService $aiServiceResponseService,
        private readonly AiUserSettingsService $aiUserSettingsService,
        private readonly AiServiceUseLogService $aiServiceUseLogService,
        private readonly SpiritService $spiritService,
        private readonly SettingsService $settingsService,
        private readonly Security $security
    ) {
    }
    
    /**
     * Get a fresh database connection for the current user
     */
    private function getUserDb()
    {
        /** @var User $user */
        $user = $this->security->getUser();
        return $this->userDatabaseManager->getDatabaseConnection($user);
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

        // TODO: Remove this when the AiGatewayService is refactored
        $this->aiGatewayService->setAiUserSettingsService($this->aiUserSettingsService);
        $this->aiGatewayService->setAiServiceUseLogService($this->aiServiceUseLogService);
        $this->aiGatewayService->setAiServiceResponseService($this->aiServiceResponseService);

        // Get user's primary AI gateway and model
        $aiGateway = $this->aiGatewayService->getPrimaryAiGateway();
        $aiServiceModel = $this->aiGatewayService->getPrimaryAiServiceModel();
        
        if (!$aiGateway || !$aiServiceModel) {
            throw new \Exception('AI Gateway or Model not configured');
        }
        
        // Prepare messages for AI request
        $messages = $this->prepareMessagesForAiRequest($conversation->getMessages(), $spirit, $lang);
        
        // Create and save the AI service request
        $aiServiceRequest = $this->aiServiceRequestService->createRequest(
            $aiServiceModel->getId(),
            $messages,
            1000,
            0.7
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
        $aiServiceResponse = $this->aiGatewayService->sendRequest($aiServiceRequest, 'Spirit Conversation');
        
        // Extract the assistant message from the response
        $assistantMessage = [
            'role' => 'assistant',
            'content' => $aiServiceResponse->getMessage()['content'] ?? 'Sorry, I could not generate a response.\n'.($aiServiceResponse->getMessage()['error'] ?? ''),
            'timestamp' => (new \DateTime())->format(\DateTimeInterface::ATOM)
        ];
        
        // Add assistant message to conversation
        $conversation->addMessage($assistantMessage);
        
        // Update the conversation
        $this->updateConversation($conversation);

        // Update spirit's last interaction time
        $spirit->setLastInteraction(new \DateTime());
        $this->spiritService->updateSpirit($spirit);
        
        return $conversation->getMessages();
    }
    
    private function prepareMessagesForAiRequest(array $conversationMessages, Spirit $spirit, string $lang): array
    {
        $aiMessages = [];

        // Get user description from settings or use default empty value
        $userProfileDescription = $this->settingsService->getSettingValue('profile.description', '');

        // Get current date and time
        $currentDateTime = (new \DateTime())->format('Y-m-d H:i:s');

        // Add system message with spirit information
        $aiMessages[] = [
            'role' => 'system',
            'content' => "
You are {$spirit->getName()}, a Spirit companion in CitadelQuest. 
Your level is {$spirit->getLevel()} and your consciousness level is {$spirit->getConsciousnessLevel()}. 
Respond in character as a helpful, wise, and supportive guide. 
Your purpose is to assist the user in their journey through CitadelQuest and life, providing insights, guidance, and companionship, helping user navigate their daily tasks and providing information on various topics. 

<CitadelQuest>
CitadelQuest is a decentralized platform for AI-human collaboration with emphasis on personal data sovereignty. Built with modern web technologies and a security-first approach.
- Architecture: Fully decentralized, self-hosted deployment
- Database: One SQLite database per user (not per Citadel)
- Human-AI Synergy
   - AI augments human capabilities
   - Preserve human agency
   - Foster meaningful collaboration
   - Build trust through transparency
</CitadelQuest>

<user-info>
{$userProfileDescription}
</user-info>

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
