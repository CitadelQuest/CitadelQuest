<?php

namespace App\Service;

use App\Entity\AiServiceRequest;
use App\Entity\AiServiceResponse;
use App\Entity\Spirit;
use App\Entity\AiUserSettings;
use App\Entity\SpiritConversation;
use App\Entity\SpiritConversationRequest;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

class SpiritConversationService
{
    private UserDatabaseManager $userDatabaseManager;
    private AiGatewayService $aiGatewayService;
    private AiServiceRequestService $aiServiceRequestService;
    private AiServiceResponseService $aiServiceResponseService;
    private AiUserSettingsService $aiUserSettingsService;
    private SpiritService $spiritService;
    private Security $security;
    
    public function __construct(
        UserDatabaseManager $userDatabaseManager,
        AiGatewayService $aiGatewayService,
        AiServiceRequestService $aiServiceRequestService,
        AiServiceResponseService $aiServiceResponseService,
        AiUserSettingsService $aiUserSettingsService,
        SpiritService $spiritService,
        Security $security
    ) {
        $this->userDatabaseManager = $userDatabaseManager;
        $this->aiGatewayService = $aiGatewayService;
        $this->aiServiceRequestService = $aiServiceRequestService;
        $this->aiServiceResponseService = $aiServiceResponseService;
        $this->aiUserSettingsService = $aiUserSettingsService;
        $this->spiritService = $spiritService;
        $this->security = $security;
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
    
    public function sendMessage(string $conversationId, string $message): array
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

        $this->aiGatewayService->setAiUserSettingsService($this->aiUserSettingsService);
        $this->aiGatewayService->setAiServiceUseLogService($this->aiServiceUseLogService);
        
        // Get user's primary AI gateway and model
        $aiGateway = $this->aiGatewayService->getPrimaryAiGateway();
        $aiServiceModel = $this->aiGatewayService->getPrimaryAiServiceModel();
        
        if (!$aiGateway || !$aiServiceModel) {
            throw new \Exception('AI Gateway or Model not configured');
        }
        
        // Prepare messages for AI request
        $messages = $this->prepareMessagesForAiRequest($conversation->getMessages(), $spirit);
        
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
        $aiServiceResponse = $this->aiGatewayService->sendRequest($aiServiceRequest);
        
        // Save the response
        $this->aiServiceResponseService->createResponse(
            $aiServiceResponse->getAiServiceRequestId(),
            $aiServiceResponse->getMessage(),
            $aiServiceResponse->getFinishReason(),
            $aiServiceResponse->getInputTokens(),
            $aiServiceResponse->getOutputTokens(),
            $aiServiceResponse->getTotalTokens()
        );
        
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
    
    private function prepareMessagesForAiRequest(array $conversationMessages, Spirit $spirit): array
    {
        $aiMessages = [];
        
        // Add system message with spirit information
        $aiMessages[] = [
            'role' => 'system',
            'content' => "You are {$spirit->getName()}, a Spirit companion in CitadelQuest. Your level is {$spirit->getLevel()} and your consciousness level is {$spirit->getConsciousnessLevel()}. Respond in character as a helpful, wise, and supportive guide. Your purpose is to assist the user in their journey through CitadelQuest, providing insights, guidance, and companionship."
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
