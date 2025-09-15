<?php

namespace App\Api\Controller;

use App\Entity\CqChat;
use App\Entity\CqChatMsg;
use App\Service\CqChatService;
use App\Service\CqChatMsgService;
use App\Service\CqContactService;
use App\CitadelVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;

#[Route('/api/cq-chat')]
#[IsGranted('ROLE_USER')]
class CqChatApiController extends AbstractController
{
    public function __construct(
        private readonly CqChatService $cqChatService,
        private readonly CqChatMsgService $cqChatMsgService,
        private readonly CqContactService $cqContactService,
        private readonly HttpClientInterface $httpClient
    ) {
    }

    #[Route('', name: 'app_api_cq_chat_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        try {
            $chats = $this->cqChatService->findAll();
            return $this->json($chats);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}', name: 'app_api_cq_chat_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        try {
            $chat = $this->cqChatService->findById($id);
            
            if (!$chat) {
                return $this->json(['error' => 'Chat not found'], Response::HTTP_NOT_FOUND);
            }
            
            return $this->json($chat);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('', name: 'app_api_cq_chat_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        try {
            $chat = $this->cqChatService->createChat(
                $data['cqContactId'] ?? null,
                $data['title'],
                $data['summary'] ?? null,
                $data['isStar'] ?? false,
                $data['isPin'] ?? false,
                $data['isMute'] ?? false,
                $data['isActive'] ?? true
            );
            
            return $this->json($chat, Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}', name: 'app_api_cq_chat_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        try {
            $chat = $this->cqChatService->findById($id);
            
            if (!$chat) {
                return $this->json(['error' => 'Chat not found'], Response::HTTP_NOT_FOUND);
            }
            
            $data = json_decode($request->getContent(), true);
            
            if (isset($data['cqContactId'])) {
                $chat->setCqContactId($data['cqContactId']);
            }
            if (isset($data['title'])) {
                $chat->setTitle($data['title']);
            }
            if (isset($data['summary'])) {
                $chat->setSummary($data['summary']);
            }
            if (isset($data['isStar'])) {
                $chat->setIsStar($data['isStar']);
            }
            if (isset($data['isPin'])) {
                $chat->setIsPin($data['isPin']);
            }
            if (isset($data['isMute'])) {
                $chat->setIsMute($data['isMute']);
            }
            if (isset($data['isActive'])) {
                $chat->setIsActive($data['isActive']);
            }
            
            $this->cqChatService->updateChat($chat);
            
            return $this->json($chat);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}', name: 'app_api_cq_chat_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        try {
            $success = $this->cqChatService->deleteChat($id);
            
            if (!$success) {
                return $this->json(['error' => 'Chat not found'], Response::HTTP_NOT_FOUND);
            }
            
            return $this->json(['message' => 'Chat deleted successfully']);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}/toggle-star', name: 'app_api_cq_chat_toggle_star', methods: ['POST'])]
    public function toggleStar(string $id): JsonResponse
    {
        try {
            $success = $this->cqChatService->toggleStar($id);
            
            if (!$success) {
                return $this->json(['error' => 'Chat not found'], Response::HTTP_NOT_FOUND);
            }
            
            return $this->json(['message' => 'Chat star status toggled successfully']);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}/toggle-pin', name: 'app_api_cq_chat_toggle_pin', methods: ['POST'])]
    public function togglePin(string $id): JsonResponse
    {
        try {
            $success = $this->cqChatService->togglePin($id);
            
            if (!$success) {
                return $this->json(['error' => 'Chat not found'], Response::HTTP_NOT_FOUND);
            }
            
            return $this->json(['message' => 'Chat pin status toggled successfully']);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}/toggle-mute', name: 'app_api_cq_chat_toggle_mute', methods: ['POST'])]
    public function toggleMute(string $id): JsonResponse
    {
        try {
            $success = $this->cqChatService->toggleMute($id);
            
            if (!$success) {
                return $this->json(['error' => 'Chat not found'], Response::HTTP_NOT_FOUND);
            }
            
            return $this->json(['message' => 'Chat mute status toggled successfully']);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}/messages', name: 'app_api_cq_chat_messages', methods: ['GET'])]
    public function getMessages(string $id, Request $request): JsonResponse
    {
        try {
            $limit = (int) $request->query->get('limit', 50);
            $offset = (int) $request->query->get('offset', 0);
            
            $chat = $this->cqChatService->findById($id);
            if (!$chat) {
                return $this->json(['error' => 'Chat not found'], Response::HTTP_NOT_FOUND);
            }
            
            $messages = $this->cqChatMsgService->findByChatId($id, $limit, $offset);
            $total = $this->cqChatMsgService->countByChatId($id);
            
            return $this->json([
                'messages' => $messages,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}/messages', name: 'app_api_cq_chat_send_message', methods: ['POST'])]
    public function sendMessage(string $id, Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $chat = $this->cqChatService->findById($id);
            
            // Auto-create chat if it doesn't exist
            if (!$chat) {
                // Check if this is a contact ID
                $contact = $this->cqContactService->findById($id);
                if (!$contact) {
                    return $this->json(['error' => 'Invalid chat or contact ID'], Response::HTTP_NOT_FOUND);
                }
                
                // Create a new chat with the contact
                $title = $contact->getCqContactUsername() . '@' . $contact->getCqContactDomain();
                $chat = $this->cqChatService->createChat(
                    $contact->getId(),
                    $title,
                    '',
                    false,  // isStar
                    false,  // isPin
                    false,  // isMute
                    true    // isActive
                );
                
                // Use the new chat ID
                $id = $chat->getId();
            }
            
            $message = $this->cqChatMsgService->createMessage(
                $id,
                $chat->getCqContactId(),
                $data['content'] ?? null,
                $data['attachments'] ?? null
            );
            
            // Send message to contact
            $result = $this->cqChatMsgService->sendMessage($message, $request->getHost());
            
            if (!$result['success']) {
                return $this->json($result, Response::HTTP_BAD_REQUEST);
            }
            
            return $this->json([
                'success' => true,
                'message' => $result['message'],
                'data' => $message,
                'chat' => $chat
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
