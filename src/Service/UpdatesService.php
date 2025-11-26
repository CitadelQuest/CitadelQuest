<?php

namespace App\Service;

use App\Entity\User;
use App\Service\UserDatabaseManager;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Unified Updates Service
 * Provides a single endpoint for all real-time updates
 */
class UpdatesService
{
    private ?User $user;

    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly Security $security,
        private readonly CqChatService $cqChatService,
        private readonly CqChatMsgService $cqChatMsgService,
        private readonly CqContactService $cqContactService
    ) {
        $this->user = $security->getUser();
    }

    public function setUser(User $user): void
    {
        $this->user = $user;
    }

    private function getUserDb()
    {
        if (!$this->user) {
            throw new \RuntimeException('User not authenticated');
        }

        return $this->userDatabaseManager->getDatabaseConnection($this->user);
    }

    /**
     * Get all updates since a specific timestamp
     * 
     * @param string|null $since ISO 8601 timestamp
     * @param string|null $openChatId Currently open chat ID for detailed message updates
     * @return array Unified updates response
     */
    public function getUpdates(?string $since = null, ?string $openChatId = null): array
    {
        $userDb = $this->getUserDb();
        $currentTime = new \DateTime();
        
        // If no since timestamp, use 1 minute ago as default
        if (!$since) {
            $sinceDateTime = (new \DateTime())->modify('-1 minute');
            $since = $sinceDateTime->format('Y-m-d H:i:s');
        } else {
            // Convert ISO 8601 to MySQL format
            $sinceDateTime = new \DateTime($since);
            $since = $sinceDateTime->format('Y-m-d H:i:s');
        }

        // 1. Get total unread messages count
        $unreadCount = $this->getUnreadMessagesCount();

        // 2. Check if there are any updates since timestamp
        $hasUpdates = $this->hasUpdates($since);
        
        // 3. If there are updates, return ALL chats (so dropdown stays populated)
        //    Otherwise return empty array (no need to re-render)
        $chats = $hasUpdates ? $this->getAllChats() : [];

        // 4. If a chat is open, get detailed message updates for that chat
        $messages = [];
        $statusUpdates = [];
        
        if ($openChatId) {
            $messages = $this->getNewMessages($openChatId, $since);
            $statusUpdates = $this->getMessageStatusUpdates($openChatId, $since);
        }

        return [
            'timestamp' => $currentTime->format('c'), // ISO 8601
            'unreadCount' => $unreadCount,
            'chats' => $chats,
            'messages' => $messages,
            'statusUpdates' => $statusUpdates
        ];
    }

    /**
     * Get total count of unread messages across all chats
     */
    private function getUnreadMessagesCount(): int
    {
        $userDb = $this->getUserDb();
        
        $result = $userDb->executeQuery(
            'SELECT COUNT(*) as count FROM cq_chat_msg 
             WHERE cq_contact_id IS NOT NULL 
             AND status != ?',
            ['SEEN']
        )->fetchAssociative();

        return (int) ($result['count'] ?? 0);
    }

    /**
     * Check if there are any updates since timestamp
     */
    private function hasUpdates(string $since): bool
    {
        $userDb = $this->getUserDb();
        
        $result = $userDb->executeQuery(
            'SELECT COUNT(*) as count FROM cq_chat_msg 
             WHERE created_at > ? OR updated_at > ?',
            [$since, $since]
        )->fetchAssociative();
        
        return ($result['count'] ?? 0) > 0;
    }
    
    /**
     * Get ALL chats with their current state
     */
    private function getAllChats(): array
    {
        $userDb = $this->getUserDb();
        
        $results = $userDb->executeQuery(
            'SELECT 
                c.id,
                c.cq_contact_id,
                c.title,
                c.is_star,
                c.is_pin,
                c.is_mute,
                c.is_group_chat,
                c.updated_at,
                (SELECT COUNT(*) FROM cq_chat_msg 
                 WHERE cq_chat_id = c.id 
                 AND cq_contact_id IS NOT NULL 
                 AND status != ?) as unread_count,
                (SELECT content FROM cq_chat_msg 
                 WHERE cq_chat_id = c.id 
                 ORDER BY created_at DESC LIMIT 1) as last_message_content,
                (SELECT created_at FROM cq_chat_msg 
                 WHERE cq_chat_id = c.id 
                 ORDER BY created_at DESC LIMIT 1) as last_message_at
             FROM cq_chat c
             WHERE c.is_active = 1
             ORDER BY 
                CASE WHEN last_message_at IS NULL THEN 0 ELSE 1 END DESC,
                last_message_at DESC',
            ['SEEN']
        )->fetchAllAssociative();

        // Enrich with contact information and format for frontend
        return array_map(function($chat) {
            // Convert snake_case to camelCase for frontend
            $formatted = [
                'id' => $chat['id'],
                'cqContactId' => $chat['cq_contact_id'],
                'title' => $chat['title'],
                'isStar' => (bool) $chat['is_star'],
                'isPin' => (bool) $chat['is_pin'],
                'isMute' => (bool) $chat['is_mute'],
                'isGroupChat' => (bool) $chat['is_group_chat'],
                'updatedAt' => $chat['updated_at'],
                'unreadCount' => (int) $chat['unread_count'],
                'lastMessage' => [
                    'content' => $chat['last_message_content'] ?? ''
                ],
                'lastMessageAt' => $chat['last_message_at']
            ];
            
            // Add contact information
            if ($chat['cq_contact_id']) {
                $contact = $this->cqContactService->findById($chat['cq_contact_id']);
                if ($contact) {
                    $formatted['contact'] = $contact->jsonSerialize();
                }
            }
            
            return $formatted;
        }, $results);
    }

    /**
     * Get chats that have updates since the given timestamp
     */
    private function getChatsWithUpdates(string $since): array
    {
        $userDb = $this->getUserDb();
        
        // Get chats with new or updated messages
        $results = $userDb->executeQuery(
            'SELECT DISTINCT 
                c.id,
                c.cq_contact_id,
                c.title,
                c.is_star,
                c.is_pin,
                c.is_mute,
                c.updated_at,
                (SELECT COUNT(*) FROM cq_chat_msg 
                 WHERE cq_chat_id = c.id 
                 AND cq_contact_id IS NOT NULL 
                 AND status != ?) as unread_count,
                (SELECT content FROM cq_chat_msg 
                 WHERE cq_chat_id = c.id 
                 ORDER BY created_at DESC LIMIT 1) as last_message_content,
                (SELECT created_at FROM cq_chat_msg 
                 WHERE cq_chat_id = c.id 
                 ORDER BY created_at DESC LIMIT 1) as last_message_at
             FROM cq_chat c
             INNER JOIN cq_chat_msg m ON m.cq_chat_id = c.id
             WHERE c.is_active = 1
             AND (m.created_at > ? OR m.updated_at > ?)
             ORDER BY last_message_at DESC',
            ['SEEN', $since, $since]
        )->fetchAllAssociative();

        // Enrich with contact information
        return array_map(function($chat) {
            if ($chat['cq_contact_id']) {
                $contact = $this->cqContactService->findById($chat['cq_contact_id']);
                if ($contact) {
                    $chat['contact'] = [
                        'id' => $contact->getId(),
                        'username' => $contact->getCqContactUsername(),
                        'domain' => $contact->getCqContactDomain()
                    ];
                }
            }
            return $chat;
        }, $results);
    }

    /**
     * Get new messages for a specific chat since timestamp
     */
    private function getNewMessages(string $chatId, string $since): array
    {
        $userDb = $this->getUserDb();
        
        $results = $userDb->executeQuery(
            'SELECT * FROM cq_chat_msg 
             WHERE cq_chat_id = ? 
             AND created_at > ?
             ORDER BY created_at ASC',
            [$chatId, $since]
        )->fetchAllAssociative();

        // Check if this is a group chat
        $chat = $this->cqChatService->findById($chatId);
        $isGroupChat = $chat && $chat->isGroupChat();
        
        // Enrich messages with contact information (for group chats)
        if ($isGroupChat) {
            $results = array_map(function($message) {
                $contactId = $message['cq_contact_id'] ?? null;
                if ($contactId) {
                    $contact = $this->cqContactService->findById($contactId);
                    if ($contact) {
                        $message['contactUsername'] = $contact->getCqContactUsername();
                        $message['contactDomain'] = $contact->getCqContactDomain();
                    }
                }
                return $message;
            }, $results);
        }

        return $results;
    }

    /**
     * Get message status updates for a specific chat since timestamp
     * Returns messages where status was updated (not just created)
     */
    private function getMessageStatusUpdates(string $chatId, string $since): array
    {
        $userDb = $this->getUserDb();
        
        // Get messages that were updated (status changed) since the timestamp
        // We check updated_at > since to get recent changes
        // We exclude messages where updated_at = created_at (just created, no status change yet)
        $results = $userDb->executeQuery(
            'SELECT id, status, updated_at, created_at
             FROM cq_chat_msg 
             WHERE cq_chat_id = ? 
             AND updated_at > ?
             AND (updated_at != created_at OR updated_at > ?)
             ORDER BY updated_at ASC',
            [$chatId, $since, $since]
        )->fetchAllAssociative();

        return $results;
    }
}
