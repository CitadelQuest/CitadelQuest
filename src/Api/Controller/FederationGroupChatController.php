<?php

namespace App\Api\Controller;

use App\Entity\User;
use App\Service\CqContactService;
use App\Service\CqChatMsgService;
use App\Service\CqChatService;
use App\Service\GroupChatService;
use App\Service\GroupMessageDeliveryService;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

class FederationGroupChatController extends AbstractController
{
    public function __construct(
        private readonly CqContactService $cqContactService,
        private readonly CqChatMsgService $cqChatMsgService,
        private readonly CqChatService $cqChatService,
        private readonly GroupChatService $groupChatService,
        private readonly GroupMessageDeliveryService $deliveryService,
        private readonly EntityManagerInterface $entityManager,
        private readonly SettingsService $settingsService,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Receive a group message from a member (sent to host)
     */
    #[Route('/{username}/api/federation/group-message', name: 'app_api_federation_group_message', methods: ['POST'])]
    public function groupMessage(Request $request, string $username): JsonResponse
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
            
            // Set user context for all services
            $this->cqContactService->setUser($user);
            $this->cqChatMsgService->setUser($user);
            $this->cqChatService->setUser($user);
            $this->groupChatService->setUser($user);
            $this->deliveryService->setUser($user);
            $this->settingsService->setUser($user);
            
            // Find the contact by API key (sender)
            $contact = $this->cqContactService->findByApiKey($apiKey);
            if (!$contact) {
                return $this->json(['error' => 'Contact not found or unauthorized'], Response::HTTP_UNAUTHORIZED);
            }
            
            $groupChatId = $data['group_chat_id'] ?? null;
            $messageId = $data['message_id'] ?? null;
            $content = $data['content'] ?? null;
            $attachments = $data['attachments'] ?? null;
            
            if (!$groupChatId || !$messageId || (!$content && !$attachments)) {
                return $this->json(['error' => 'Missing required fields'], Response::HTTP_BAD_REQUEST);
            }
            
            // Verify this is a group chat and user is the host
            $chat = $this->cqChatService->findById($groupChatId);
            if (!$chat || !$chat->isGroupChat()) {
                return $this->json(['error' => 'Group chat not found'], Response::HTTP_NOT_FOUND);
            }
            
            if (!$this->groupChatService->isUserHost($groupChatId)) {
                return $this->json(['error' => 'Only host can receive group messages'], Response::HTTP_FORBIDDEN);
            }
            
            // Verify sender is a member
            if (!$this->groupChatService->isMember($groupChatId, $contact->getId())) {
                return $this->json(['error' => 'Sender is not a member of this group'], Response::HTTP_FORBIDDEN);
            }
            
            // Store the message
            $message = $this->cqChatMsgService->storeForwardedGroupMessage(
                $groupChatId,
                $contact->getId(),
                $content,
                $messageId,
                $attachments
            );
            
            // Create delivery records for all members (except sender)
            $members = $this->groupChatService->getGroupMembers($groupChatId);
            $recipientIds = array_filter(
                array_map(fn($m) => $m->getCqContactId(), $members),
                fn($id) => $id !== $contact->getId()
            );
            
            $this->deliveryService->createDeliveryRecords($messageId, $recipientIds);
            
            // Forward message to all other members
            $this->forwardMessageToMembers($groupChatId, $messageId, $content, $attachments, $contact, $recipientIds);

            // Update chat (updated_at)
            $this->cqChatService->updateChat($chat);
            
            return $this->json([
                'success' => true,
                'message_id' => $message->getId(),
                'delivered_to_host' => true
            ]);
            
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Receive a forwarded group message from host
     */
    #[Route('/{username}/api/federation/group-message-forward', name: 'app_api_federation_group_message_forward', methods: ['POST'])]
    public function groupMessageForward(Request $request, string $username): JsonResponse
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
            
            // Set user context for all services
            $this->cqContactService->setUser($user);
            $this->cqChatMsgService->setUser($user);
            $this->cqChatService->setUser($user);
            $this->groupChatService->setUser($user);
            $this->settingsService->setUser($user);
            
            // Find the contact by API key (should be the host)
            $contact = $this->cqContactService->findByApiKey($apiKey);
            if (!$contact) {
                return $this->json(['error' => 'Contact not found or unauthorized'], Response::HTTP_UNAUTHORIZED);
            }
            
            $groupChatId = $data['group_chat_id'] ?? null;
            $originalSender = $data['original_sender'] ?? null;
            $messageId = $data['message_id'] ?? null;
            $content = $data['content'] ?? null;
            $attachments = $data['attachments'] ?? null;
            
            // Need groupChatId, originalSender, messageId, and either content or attachments
            if (!$groupChatId || !$originalSender || !$messageId || (!$content && !$attachments)) {
                return $this->json(['error' => 'Missing required fields'], Response::HTTP_BAD_REQUEST);
            }
            
            // Check if group chat exists, if not create it
            $chat = $this->cqChatService->findById($groupChatId);
            $isNewChat = false;
            if (!$chat) {
                // Create the group chat with the provided ID
                // Extract group name from data or use default
                $groupName = $data['group_name'] ?? 'Group Chat';
                
                $chat = $this->cqChatService->createChat(
                    $contact->getId(), // Host contact ID
                    $groupName,
                    null, // summary
                    false, // is_star
                    false, // is_pin
                    false, // is_mute
                    true, // is_active
                    true, // is_group_chat
                    $contact->getId(), // group_host_contact_id (the sender is the host)
                    $groupChatId // specific ID
                );
                $isNewChat = true;
            } elseif (!$chat->isGroupChat()) {
                return $this->json(['error' => 'Chat exists but is not a group chat'], Response::HTTP_BAD_REQUEST);
            }
            
            // Sync member list if provided (for new chats or updates)
            $members = $data['members'] ?? [];
            if (!empty($members)) {
                $this->syncGroupMembers($groupChatId, $members);
            }

            // Update chat (updated_at)
            $this->cqChatService->updateChat($chat);
            
            // Verify sender is the host
            if ($chat->getGroupHostContactId() !== $contact->getId()) {
                return $this->json(['error' => 'Only host can forward messages'], Response::HTTP_FORBIDDEN);
            }
            
            // Find the original sender contact by parsing username@domain
            $senderContactId = null;
            $senderUsername = null;
            $senderDomain = null;
            if (str_contains($originalSender, '@')) {
                [$senderUsername, $senderDomain] = explode('@', $originalSender, 2);
                $senderUrl = 'https://' . $senderDomain . '/' . $senderUsername;
                $senderContact = $this->cqContactService->findByUrl($senderUrl);
                // Use real contact ID if found, otherwise use placeholder
                // Placeholder ensures cq_contact_id is non-null so frontend treats it as incoming
                $senderContactId = $senderContact ? $senderContact->getId() : 'nf_' . $senderUsername . '_' . $senderDomain;
            }
            
            // Store the message (always include sender username/domain for non-friend rendering)
            $message = $this->cqChatMsgService->storeForwardedGroupMessage(
                $groupChatId,
                $senderContactId,
                $content,
                $messageId,
                $attachments,
                $senderUsername,
                $senderDomain
            );
            
            // Send delivery status back to host
            $this->sendDeliveryStatus($contact, $groupChatId, $messageId, 'DELIVERED');
            
            return $this->json([
                'success' => true,
                'message_id' => $message->getId(),
                'stored' => true
            ]);
            
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Receive delivery/seen status from a member
     */
    #[Route('/{username}/api/federation/group-message-status', name: 'app_api_federation_group_message_status', methods: ['POST'])]
    public function groupMessageStatus(Request $request, string $username): JsonResponse
    {
        try {
            // Get the authorization header
            $authHeader = $request->headers->get('Authorization');
            if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
                return $this->json(['error' => 'Missing or invalid authorization header'], Response::HTTP_UNAUTHORIZED);
            }
            
            // Extract the API key
            $apiKey = substr($authHeader, 7);
            
            // Get the status data from the request
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
            
            // Set user context for all services
            $this->cqContactService->setUser($user);
            $this->cqChatService->setUser($user);
            $this->cqChatMsgService->setUser($user);
            $this->groupChatService->setUser($user);
            $this->deliveryService->setUser($user);
            $this->settingsService->setUser($user);
            
            // Find the contact by API key (member sending status)
            $contact = $this->cqContactService->findByApiKey($apiKey);
            if (!$contact) {
                return $this->json(['error' => 'Contact not found or unauthorized'], Response::HTTP_UNAUTHORIZED);
            }
            
            $groupChatId = $data['group_chat_id'] ?? null;
            $messageId = $data['message_id'] ?? null;
            $status = $data['status'] ?? null;
            
            if (!$groupChatId || !$messageId || !$status) {
                return $this->json(['error' => 'Missing required fields'], Response::HTTP_BAD_REQUEST);
            }
            
            // Verify user is the host
            if (!$this->groupChatService->isUserHost($groupChatId)) {
                return $this->json(['error' => 'Only host can receive status updates'], Response::HTTP_FORBIDDEN);
            }
            
            // Update delivery status
            $this->deliveryService->updateMemberStatus($messageId, $contact->getId(), $status);

            // Update chat (updated_at)
            $chat = $this->cqChatService->findById($groupChatId);
            if ($chat) {                
                $this->cqChatService->updateChat($chat);
            }
            
            return $this->json([
                'success' => true,
                'status_updated' => true
            ]);
            
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Forward message to all group members
     */
    private function forwardMessageToMembers(
        string $groupChatId,
        string $messageId,
        ?string $content,
        ?string $attachments,
        $senderContact,
        array $recipientContactIds
    ): void {
        $senderAddress = $senderContact->getCqContactUsername() . '@' . $senderContact->getCqContactDomain();
        
        // Build member list so recipients can sync non-friend members
        $members = $this->groupChatService->getGroupMembers($groupChatId);
        $memberList = [];
        foreach ($members as $member) {
            $contact = $this->cqContactService->findById($member->getCqContactId());
            if ($contact) {
                $memberList[] = [
                    'username' => $contact->getCqContactUsername(),
                    'domain' => $contact->getCqContactDomain(),
                    'role' => $member->getRole()
                ];
            }
        }
        
        // Get group name for chat creation on recipient side
        $chat = $this->cqChatService->findById($groupChatId);
        $groupName = $chat ? $chat->getTitle() : 'Group Chat';
        
        foreach ($recipientContactIds as $contactId) {
            try {
                $recipientContact = $this->cqContactService->findById($contactId);
                if (!$recipientContact) {
                    continue;
                }
                
                $recipientUrl = 'https://' . $recipientContact->getCqContactDomain() . '/' 
                    . $recipientContact->getCqContactUsername() . '/api/federation/group-message-forward';
                
                $this->httpClient->request('POST', $recipientUrl, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $recipientContact->getCqContactApiKey(),
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'group_chat_id' => $groupChatId,
                        'original_sender' => $senderAddress,
                        'message_id' => $messageId,
                        'content' => $content,
                        'attachments' => $attachments,
                        'members' => $memberList,
                        'group_name' => $groupName,
                        'timestamp' => date('c')
                    ]
                ]);
                
            } catch (\Exception $e) {
                // Log error but continue with other members
                error_log('Failed to forward message to contact ' . $contactId . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Send delivery status to host
     */
    private function sendDeliveryStatus($hostContact, string $groupChatId, string $messageId, string $status): void
    {
        try {
            $hostUrl = 'https://' . $hostContact->getCqContactDomain() . '/' 
                . $hostContact->getCqContactUsername() . '/api/federation/group-message-status';
            
            $this->httpClient->request('POST', $hostUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $hostContact->getCqContactApiKey(),
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'group_chat_id' => $groupChatId,
                    'message_id' => $messageId,
                    'status' => $status,
                    'timestamp' => date('c')
                ]
            ]);
            
        } catch (\Exception $e) {
            // Log error but don't fail the request
            error_log('Failed to send delivery status to host: ' . $e->getMessage());
        }
    }

    /**
     * Sync group members from host's member list
     * This stores the member list in the local user's database so they can see all members
     */
    private function syncGroupMembers(string $groupChatId, array $members): void
    {
        foreach ($members as $memberData) {
            try {
                $username = $memberData['username'] ?? null;
                $domain = $memberData['domain'] ?? null;
                $role = $memberData['role'] ?? 'member';
                
                if (!$username || !$domain) {
                    continue;
                }
                
                // Try to find local contact
                $contactUrl = 'https://' . $domain . '/' . $username;
                $contact = $this->cqContactService->findByUrl($contactUrl);
                
                // Use contact ID if found, otherwise use a deterministic placeholder
                // Placeholder format: "nf_{username}_{domain}" to identify non-friend members
                $contactId = $contact ? $contact->getId() : 'nf_' . $username . '_' . $domain;
                
                // Check if member already exists
                if ($this->groupChatService->isMember($groupChatId, $contactId)) {
                    continue;
                }
                
                // Add member to local database (bypassing host check since this is federation sync)
                // Always store username/domain for rendering regardless of friend status
                $this->addMemberWithoutHostCheck($groupChatId, $contactId, $role, $username, $domain);
                
            } catch (\Exception $e) {
                // Log error but continue with other members
                error_log('Failed to sync group member: ' . $e->getMessage());
            }
        }
    }

    /**
     * Add a member to a group chat without host check (for federation sync)
     */
    private function addMemberWithoutHostCheck(string $chatId, string $contactId, string $role = 'member', ?string $username = null, ?string $domain = null): void
    {
        $userDb = $this->groupChatService->getUserDb();
        
        $member = new \App\Entity\CqChatGroupMember($chatId, $contactId, $role);
        if ($username) {
            $member->setMemberUsername($username);
            $member->setMemberDomain($domain);
        }
        
        $userDb->executeStatement(
            'INSERT INTO cq_chat_group_members (
                id, cq_chat_id, cq_contact_id, role, member_username, member_domain, joined_at, created_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $member->getId(),
                $member->getCqChatId(),
                $member->getCqContactId(),
                $member->getRole(),
                $member->getMemberUsername(),
                $member->getMemberDomain(),
                $member->getJoinedAt()->format('Y-m-d H:i:s'),
                $member->getCreatedAt()->format('Y-m-d H:i:s')
            ]
        );
    }
}
