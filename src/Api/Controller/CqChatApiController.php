<?php

namespace App\Api\Controller;

use App\Entity\CqChat;
use App\Entity\CqChatMsg;
use App\Service\CqChatService;
use App\Service\CqChatMsgService;
use App\Service\CqContactService;
use App\Service\SettingsService;
use App\CitadelVersion;
use Psr\Log\LoggerInterface;
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
        private readonly SettingsService $settingsService,
        private readonly HttpClientInterface $httpClient,
        private readonly \App\Service\GroupChatService $groupChatService,
        private readonly \App\Service\GroupMessageDeliveryService $deliveryService,
        private readonly LoggerInterface $logger
    ) {
    }

    #[Route('', name: 'app_api_cq_chat_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        try {
            $chats = $this->cqChatService->findAll();

            // Check for unseen messages in each chat and load contact data
            $chats = array_map(function ($chat) {
                $chatData = $chat->jsonSerialize();
                $unseenCount = $this->cqChatMsgService->countUnseenMessagesByChat($chatData['id']);
                $chatData['hasNewMsgs'] = $unseenCount > 0;
                $chatData['unseenCount'] = $unseenCount;
                $chatData['unreadCount'] = $unseenCount; // Alias for compatibility
                
                // Load contact data
                if ($chat->getCqContactId()) {
                    $contact = $this->cqContactService->findById($chat->getCqContactId());
                    if ($contact) {
                        $chatData['contact'] = $contact->jsonSerialize();
                    }
                }
                
                // Get last message
                $messages = $this->cqChatMsgService->findByChatId($chatData['id'], 1);
                if (!empty($messages)) {
                    $chatData['lastMessage'] = $messages[0]->jsonSerialize();
                }
                
                return $chatData;
            }, $chats);

            return $this->json($chats);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/dropdown', name: 'app_api_cq_chat_dropdown', methods: ['GET'])]
    public function dropdown(): JsonResponse
    {
        try {
            // Get unseen count
            $unseenCount = $this->cqChatMsgService->countUnseenMessages();
            
            // Get chats (limit to 10 for dropdown)
            $chats = $this->cqChatService->findAll();
            $chats = array_slice($chats, 0, 10);

            // Process chats with contact data and last message
            $chats = array_map(function ($chat) {
                $chatData = $chat->jsonSerialize();
                $unseenCount = $this->cqChatMsgService->countUnseenMessagesByChat($chatData['id']);
                $chatData['hasNewMsgs'] = $unseenCount > 0;
                $chatData['unseenCount'] = $unseenCount;
                $chatData['unreadCount'] = $unseenCount;
                
                // Load contact data
                if ($chat->getCqContactId()) {
                    $contact = $this->cqContactService->findById($chat->getCqContactId());
                    if ($contact) {
                        $chatData['contact'] = $contact->jsonSerialize();
                    }
                }
                
                // Get last message
                $messages = $this->cqChatMsgService->findByChatId($chatData['id'], 1);
                if (!empty($messages)) {
                    $chatData['lastMessage'] = $messages[0]->jsonSerialize();
                }
                
                return $chatData;
            }, $chats);

            return $this->json([
                'unseenCount' => $unseenCount,
                'chats' => $chats
            ]);
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

    #[Route('/unseen-count', name: 'app_api_cq_chat_unseen_count', methods: ['GET'])]
    public function getUnseenCount(): JsonResponse
    {
        try {
            $unseenCount = $this->cqChatMsgService->countUnseenMessages();

            return $this->json([
                'success' => true,
                'unseenCount' => $unseenCount
            ]);
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
            
            $chatData = $chat->jsonSerialize();
            
            // Load contact data for direct chats
            if ($chat->getCqContactId()) {
                $contact = $this->cqContactService->findById($chat->getCqContactId());
                if ($contact) {
                    $chatData['contact'] = $contact->jsonSerialize();
                }
            }
            
            // Load members for group chats
            if ($chat->isGroupChat()) {
                $members = $this->groupChatService->getGroupMembers($id);
                $chatData['members'] = array_map(function($member) {
                    $memberData = $member->jsonSerialize();
                    $contact = $this->cqContactService->findById($member->getCqContactId());
                    if ($contact) {
                        $memberData['contact'] = $contact->jsonSerialize();
                    }
                    return $memberData;
                }, $members);
                
                // If user is not the host, include host contact info
                $hostContactId = $chat->getGroupHostContactId();
                if ($hostContactId) {
                    $hostContact = $this->cqContactService->findById($hostContactId);
                    if ($hostContact) {
                        $chatData['hostContact'] = $hostContact->jsonSerialize();
                    }
                }
            }
            
            return $this->json($chatData);
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
            
            // Convert messages to array format for JSON response
            $messagesArray = array_map(function($msg) {
                return $msg instanceof \JsonSerializable ? $msg->jsonSerialize() : $msg;
            }, $messages);
            
            // For group chats, enrich messages with contact information
            if ($chat->isGroupChat()) {
                $messagesArray = array_map(function($message) {
                    $contactId = $message['cqContactId'] ?? $message['cq_contact_id'] ?? null;
                    if ($contactId) {
                        $contact = $this->cqContactService->findById($contactId);
                        if ($contact) {
                            $message['contactUsername'] = $contact->getCqContactUsername();
                            $message['contactDomain'] = $contact->getCqContactDomain();
                        }
                    }
                    return $message;
                }, $messagesArray);
            }
            
            // Calculate if there are more messages to load
            $hasMore = ($offset + $limit) < $total;
            
            return $this->json([
                'messages' => $messagesArray,
                'pagination' => [
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset,
                    'hasMore' => $hasMore
                ]
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}/mark-seen', name: 'app_api_cq_chat_mark_seen', methods: ['POST'])]
    public function markSeen(string $id): JsonResponse
    {
        try {
            $chat = $this->cqChatService->findById($id);
            
            if (!$chat) {
                return $this->json(['error' => 'Chat not found'], Response::HTTP_NOT_FOUND);
            }
            
            $markedCount = $this->cqChatMsgService->markChatMessagesAsSeen($id);
            
            return $this->json([
                'success' => true,
                'markedCount' => $markedCount
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
                null, // NULL for outgoing messages (sent by current user)
                $data['content'] ?? null,
                $data['attachments'] ?? null
            );
            
            // Send message to contact
            $result = $this->cqChatMsgService->sendMessage($message, $request->getHost());
            
            if (!$result['success']) {
                return $this->json($result, Response::HTTP_BAD_REQUEST);
            }

            // Update chat (updated_at)
            $this->cqChatService->updateChat($chat);
            
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

    /**
     * Create a new group chat
     */
    #[Route('/group', name: 'app_api_cq_chat_group_create', methods: ['POST'])]
    public function createGroup(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            $groupName = $data['group_name'] ?? null;
            $contactIds = $data['contact_ids'] ?? [];
            
            if (!$groupName || empty($contactIds)) {
                return $this->json(['error' => 'Group name and contact IDs are required'], Response::HTTP_BAD_REQUEST);
            }
            
            $chat = $this->groupChatService->createGroupChat($groupName, $contactIds);
            
            return $this->json([
                'success' => true,
                'chat' => $chat
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->json([
                'error' => $e->getMessage(),
                'details' => $e->getFile() . ':' . $e->getLine()
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Get group members
     */
    #[Route('/group/{id}/members', name: 'app_api_cq_chat_group_members', methods: ['GET'])]
    public function getGroupMembers(string $id): JsonResponse
    {
        try {
            $members = $this->groupChatService->getGroupMembers($id);
            
            // Load contact data for each member
            $membersData = array_map(function($member) {
                $memberData = $member->jsonSerialize();
                $contact = $this->cqContactService->findById($member->getCqContactId());
                if ($contact) {
                    $memberData['contact'] = $contact->jsonSerialize();
                }
                return $memberData;
            }, $members);
            
            return $this->json([
                'success' => true,
                'members' => $membersData,
                'count' => count($membersData)
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Add member to group
     */
    #[Route('/group/{id}/members', name: 'app_api_cq_chat_group_add_member', methods: ['POST'])]
    public function addGroupMember(string $id, Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $contactId = $data['contact_id'] ?? null;
            
            if (!$contactId) {
                return $this->json(['error' => 'Contact ID is required'], Response::HTTP_BAD_REQUEST);
            }
            
            $member = $this->groupChatService->addMember($id, $contactId);
            
            return $this->json([
                'success' => true,
                'member' => $member
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Remove member from group
     */
    #[Route('/group/{id}/members/{contactId}', name: 'app_api_cq_chat_group_remove_member', methods: ['DELETE'])]
    public function removeGroupMember(string $id, string $contactId): JsonResponse
    {
        try {
            $this->groupChatService->removeMember($id, $contactId);
            
            return $this->json([
                'success' => true,
                'message' => 'Member removed successfully'
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Send group message
     */
    #[Route('/group/{id}/messages', name: 'app_api_cq_chat_group_send_message', methods: ['POST'])]
    public function sendGroupMessage(string $id, Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $content = $data['content'] ?? null;
            $attachments = $data['attachments'] ?? null;
            
            // Need either content or attachments
            if (!$content && !$attachments) {
                return $this->json(['error' => 'Message content or attachments required'], Response::HTTP_BAD_REQUEST);
            }
            
            $chat = $this->cqChatService->findById($id);
            if (!$chat || !$chat->isGroupChat()) {
                return $this->json(['error' => 'Group chat not found'], Response::HTTP_NOT_FOUND);
            }
            
            // Create the message
            $message = $this->cqChatMsgService->sendGroupMessage($id, $content, $attachments);
            
            // If user is host, forward to all members
            if ($this->groupChatService->isUserHost($id)) {
                $members = $this->groupChatService->getGroupMembers($id);
                
                $this->deliveryService->createDeliveryRecords(
                    $message->getId(),
                    array_map(fn($m) => $m->getCqContactId(), $members)
                );
                
                // Forward message to all members via Federation API
                $this->forwardMessageToMembers($id, $message, $members);
            } else {
                // Send to host
                $hostContactId = $chat->getGroupHostContactId();
                if ($hostContactId) {
                    $this->sendMessageToHost($id, $message, $hostContactId);
                }
            }

            // Update chat (updated_at)
            $this->cqChatService->updateChat($chat);
            
            return $this->json([
                'success' => true,
                'message' => $message
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Forward message to all group members (host only)
     */
    private function forwardMessageToMembers(string $groupChatId, $message, array $members): void
    {
        $user = $this->getUser();
        $senderAddress = $user->getUsername() . '@' . $_SERVER['HTTP_HOST'];
        
        // Get group chat to send name
        $chat = $this->cqChatService->findById($groupChatId);
        $groupName = $chat ? $chat->getTitle() : 'Group Chat';
        
        // Build member list to send to all members (so they can display the full list)
        $memberList = [];
        foreach ($members as $member) {
            $contact = $this->cqContactService->findById($member->getCqContactId());
            if ($contact) {
                $memberList[] = [
                    'contact_id' => $contact->getId(),
                    'username' => $contact->getCqContactUsername(),
                    'domain' => $contact->getCqContactDomain(),
                    'role' => $member->getRole()
                ];
            }
        }
        
        foreach ($members as $member) {
            try {
                $contact = $this->cqContactService->findById($member->getCqContactId());
                if (!$contact) {
                    continue;
                }
                
                $recipientUrl = 'https://' . $contact->getCqContactDomain() . '/' 
                    . $contact->getCqContactUsername() . '/api/federation/group-message-forward';
                
                $this->httpClient->request('POST', $recipientUrl, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $contact->getCqContactApiKey(),
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'group_chat_id' => $groupChatId,
                        'group_name' => $groupName,
                        'original_sender' => $senderAddress,
                        'message_id' => $message->getId(),
                        'content' => $message->getContent(),
                        'attachments' => $message->getAttachments(),
                        'timestamp' => $message->getCreatedAt()->format('c'),
                        'members' => $memberList
                    ]
                ]);
                
            } catch (\Exception $e) {
                $this->logger->error('Failed to forward message to member: ' . $e->getMessage());
            }
        }
    }

    /**
     * Send message to host (member only)
     */
    private function sendMessageToHost(string $groupChatId, $message, string $hostContactId): void
    {
        try {
            $hostContact = $this->cqContactService->findById($hostContactId);
            if (!$hostContact) {
                throw new \Exception('Host contact not found');
            }
            
            $hostUrl = 'https://' . $hostContact->getCqContactDomain() . '/' 
                . $hostContact->getCqContactUsername() . '/api/federation/group-message';
            
            $this->httpClient->request('POST', $hostUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $hostContact->getCqContactApiKey(),
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'group_chat_id' => $groupChatId,
                    'message_id' => $message->getId(),
                    'content' => $message->getContent(),
                    'attachments' => $message->getAttachments(),
                    'timestamp' => $message->getCreatedAt()->format('c')
                ]
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to send message to host: ' . $e->getMessage());
            throw $e;
        }
    }
}

