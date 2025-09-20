<?php

namespace App\Api\Controller;

use App\Entity\User;
use App\Entity\CqContact;
use App\Service\CqContactService;
use App\Service\CqChatMsgService;
use App\Service\CqChatService;
use App\Service\NotificationService;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class FederationChatMessageController extends AbstractController
{
    public function __construct(
        private readonly CqContactService $cqContactService,
        private readonly CqChatMsgService $cqChatMsgService,
        private readonly CqChatService $cqChatService,
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationService $notificationService,
        private readonly SettingsService $settingsService
    ) {
    }

    /**
     * Receive a chat message from another CitadelQuest instance
     */
    #[Route('/{username}/api/federation/chat-message', name: 'app_api_federation_chat_message', methods: ['POST'])]
    public function receiveChatMessage(Request $request, string $username): JsonResponse
    {
        try {
            // Get the authorization header
            $authHeader = $request->headers->get('Authorization');
            if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
                return $this->json(['error' => 'Missing or invalid authorization header'], Response::HTTP_UNAUTHORIZED);
            }
            
            // Extract the API key
            $apiKey = substr($authHeader, 7);
            
            // Get the message data from the request
            $data = json_decode($request->getContent(), true);
            if (!$data) {
                return $this->json(['error' => 'Invalid request data'], Response::HTTP_BAD_REQUEST);
            }


            // Get system User by `username`
            $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
            if (!$user) {
                return $this->json([
                    'success' => false,
                    'message' => 'User not found'
                ], Response::HTTP_NOT_FOUND);
            }
            $this->cqContactService->setUser($user);
            $this->cqChatMsgService->setUser($user);
            $this->cqChatService->setUser($user);
            $this->settingsService->setUser($user);
            
            // Find the contact by API key
            $contact = $this->cqContactService->findByApiKey($apiKey);
            if (!$contact) {
                return $this->json(['error' => 'Contact not found or unauthorized'], Response::HTTP_UNAUTHORIZED);
            }
            
            // Check if we need to create a chat first
            $chatId = $data['cq_chat_id'] ?? null;
            if ($chatId) {
                $chat = $this->cqChatService->findById($chatId);
                if (!$chat) {
                    // Create a new chat with the provided ID
                    $title = $contact->getCqContactUsername() . '@' . $contact->getCqContactDomain();
                    $this->cqChatService->createChat(
                        $contact->getId(),
                        $title,
                        '',
                        false,  // isStar
                        false,  // isPin
                        false,  // isMute
                        true,   // isActive
                        $chatId // specific ID
                    );
                }
            }
            
            // Create the message (it will have status 'RECEIVED' by default)
            $message = $this->cqChatMsgService->receiveMessage($data, $contact->getId());

            return $this->json([
                'success' => true,
                'message' => 'Message received successfully',
                'data' => [
                    'id' => $message->getId(),
                    'status' => 'DELIVERED'
                ]
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Failed to process message: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    
    /**
     * Update the status of a chat message
     */
    #[Route('/{username}/api/federation/chat-message/{id}/status', name: 'app_api_federation_chat_message_status', methods: ['PUT'])]
    public function updateMessageStatus(string $username, string $id, Request $request): JsonResponse
    {
        try {
            // Get the authorization header
            $authHeader = $request->headers->get('Authorization');
            if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
                return $this->json(['error' => 'Missing or invalid authorization header'], Response::HTTP_UNAUTHORIZED);
            }
            
            // Extract the API key
            $apiKey = substr($authHeader, 7);
            
            // Get the message data
            $data = json_decode($request->getContent(), true);
            if (!$data || !isset($data['status'])) {
                return $this->json(['error' => 'Invalid request data'], Response::HTTP_BAD_REQUEST);
            }


            // Get system User by `username`
            $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
            if (!$user) {
                return $this->json([
                    'success' => false,
                    'message' => 'User not found'
                ], Response::HTTP_NOT_FOUND);
            }
            $this->cqContactService->setUser($user);
            $this->cqChatMsgService->setUser($user);
            
            // Find the contact by API key
            $contact = $this->cqContactService->findByApiKey($apiKey);
            if (!$contact) {
                return $this->json(['error' => 'Contact not found or unauthorized'], Response::HTTP_UNAUTHORIZED);
            }
            
            // Find the message
            $message = $this->cqChatMsgService->findById($id);
            if (!$message) {
                return $this->json(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
            }
            
            // Update the message status
            $message->setStatus($data['status']);
            $this->cqChatMsgService->updateMessage($message);
            
            return $this->json([
                'success' => true,
                'message' => 'Message status updated successfully'
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Failed to update message status: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
