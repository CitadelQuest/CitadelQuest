<?php

namespace App\Service;

use App\Entity\CqChatMsg;
use App\Entity\User;
use App\Service\UserDatabaseManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CqChatMsgService
{
    private ?User $user;

    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly Security $security,
        private readonly HttpClientInterface $httpClient,
        private readonly CqContactService $cqContactService,
        private readonly CqChatService $cqChatService
    ) {
        $this->user = $security->getUser();
    }

    public function setUser(User $user): void
    {
        $this->user = $user;
    }
    
    /**
     * Get a fresh database connection for the current user
     */
    private function getUserDb()
    {
        if (!$this->user) {
            throw new \Exception('User not found');
        }
        return $this->userDatabaseManager->getDatabaseConnection($this->user);
    }

    public function createMessage(
        string $cqChatId,
        ?string $cqContactId = null,
        ?string $content = null,
        ?string $attachments = null,
        ?string $status = null,
        ?string $id = null
    ): CqChatMsg {
        $message = new CqChatMsg(
            $cqChatId,
            $cqContactId,
            $content,
            $attachments,
            $status,
            $id
        );

        // Store in user's database
        $userDb = $this->getUserDb();
        $userDb->executeStatement(
            'INSERT INTO cq_chat_msg (
                id, cq_chat_id, cq_contact_id, content, attachments, status,
                created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $message->getId(),
                $message->getCqChatId(),
                $message->getCqContactId(),
                $message->getContent(),
                $message->getAttachments(),
                $message->getStatus(),
                $message->getCreatedAt()->format('Y-m-d H:i:s'),
                $message->getUpdatedAt()->format('Y-m-d H:i:s')
            ]
        );

        return $message;
    }

    public function findById(string $id): ?CqChatMsg
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT * FROM cq_chat_msg WHERE id = ?',
            [$id]
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        return CqChatMsg::fromArray($result);
    }

    public function findByChatId(string $cqChatId, int $limit = 50, int $offset = 0): array
    {
        $userDb = $this->getUserDb();
        $results = $userDb->executeQuery(
            'SELECT * FROM cq_chat_msg WHERE cq_chat_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?',
            [$cqChatId, $limit, $offset]
        )->fetchAllAssociative();

        return array_map(fn($data) => CqChatMsg::fromArray($data), $results);
    }
    
    public function countByChatId(string $cqChatId): int
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT COUNT(*) as count FROM cq_chat_msg WHERE cq_chat_id = ?',
            [$cqChatId]
        )->fetchAssociative();

        return (int) ($result['count'] ?? 0);
    }

    public function findByStatus(string $status, int $limit = 50, int $offset = 0): array
    {
        $userDb = $this->getUserDb();
        $results = $userDb->executeQuery(
            'SELECT * FROM cq_chat_msg WHERE status = ? ORDER BY created_at DESC LIMIT ? OFFSET ?',
            [$status, $limit, $offset]
        )->fetchAllAssociative();

        return array_map(fn($data) => CqChatMsg::fromArray($data), $results);
    }

    public function updateMessage(CqChatMsg $message): bool
    {
        $message->updateUpdatedAt();
        
        $userDb = $this->getUserDb();
        $result = $userDb->executeStatement(
            'UPDATE cq_chat_msg SET 
                cq_chat_id = ?, cq_contact_id = ?, content = ?,
                attachments = ?, status = ?, updated_at = ?
             WHERE id = ?',
            [
                $message->getCqChatId(),
                $message->getCqContactId(),
                $message->getContent(),
                $message->getAttachments(),
                $message->getStatus(),
                $message->getUpdatedAt()->format('Y-m-d H:i:s'),
                $message->getId()
            ]
        );

        return $result > 0;
    }

    public function deleteMessage(string $id): bool
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeStatement(
            'DELETE FROM cq_chat_msg WHERE id = ?',
            [$id]
        );

        return $result > 0;
    }

    public function updateStatus(string $id, ?string $status): bool
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeStatement(
            'UPDATE cq_chat_msg SET status = ?, updated_at = ? WHERE id = ?',
            [$status, date('Y-m-d H:i:s'), $id]
        );

        return $result > 0;
    }
    
    /**
     * Count unseen messages (status = 'RECEIVED') for the current user
     */
    public function countUnseenMessages(): int
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT COUNT(*) as count FROM cq_chat_msg WHERE status = ? AND cq_contact_id IS NOT NULL',
            ['RECEIVED']
        )->fetchAssociative();

        return (int) ($result['count'] ?? 0);
    }
    
    /**
     * Count unseen messages for a specific chat
     */
    public function countUnseenMessagesByChat(string $cqChatId): int
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT COUNT(*) as count FROM cq_chat_msg WHERE cq_chat_id = ? AND status = ? AND cq_contact_id IS NOT NULL',
            [$cqChatId, 'RECEIVED']
        )->fetchAssociative();

        return (int) ($result['count'] ?? 0);
    }
    
    /**
     * Mark all RECEIVED messages in a chat as SEEN
     */
    public function markChatMessagesAsSeen(string $cqChatId): int
    {
        $userDb = $this->getUserDb();
        
        // First, get the messages that will be marked as seen for federation notification
        $messagesToUpdate = $userDb->executeQuery(
            'SELECT id, cq_contact_id FROM cq_chat_msg WHERE cq_chat_id = ? AND status = ? AND cq_contact_id IS NOT NULL',
            [$cqChatId, 'RECEIVED']
        )->fetchAllAssociative();
        
        // Update the messages
        $result = $userDb->executeStatement(
            'UPDATE cq_chat_msg SET status = ?, updated_at = ? WHERE cq_chat_id = ? AND status = ? AND cq_contact_id IS NOT NULL',
            ['SEEN', date('Y-m-d H:i:s'), $cqChatId, 'RECEIVED']
        );
        
        // Notify federation about status updates
        foreach ($messagesToUpdate as $messageData) {
            $this->notifyFederationStatusUpdate($messageData['id'], $messageData['cq_contact_id'], 'SEEN');
        }

        return $result;
    }
    
    /**
     * Notify federation about message status update
     */
    private function notifyFederationStatusUpdate(string $messageId, string $contactId, string $status): void
    {
        try {
            $contact = $this->cqContactService->findById($contactId);
            if (!$contact) {
                return;
            }
            
            $this->httpClient->request(
                'PUT',
                $contact->getCqContactUrl() . '/api/federation/chat-message/' . $messageId . '/status',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $contact->getCqContactApiKey(),
                        'User-Agent' => 'CitadelQuest HTTP Client',
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'status' => $status
                    ],
                    'timeout' => 10
                ]
            );
        } catch (\Exception $e) {
            // Log error but don't fail the main operation
            error_log('Failed to notify federation about status update: ' . $e->getMessage());
        }
    }
    
    /**
     * Send a message to a contact
     */
    public function sendMessage(CqChatMsg $message, string $currentDomain): array
    {
        try {
            // For outgoing messages, get the contact from the chat (since message.cq_contact_id is null)
            $chat = $this->cqChatService->findById($message->getCqChatId());
            if (!$chat || !$chat->getCqContactId()) {
                return [
                    'success' => false,
                    'message' => 'Chat or contact not found'
                ];
            }
            
            $contact = $this->cqContactService->findById($chat->getCqContactId());
            if (!$contact) {
                return [
                    'success' => false,
                    'message' => 'Contact not found'
                ];
            }
            
            // Prepare the message data
            $messageData = [
                'id' => $message->getId(),
                'cq_chat_id' => $message->getCqChatId(),
                'content' => $message->getContent(),
                'attachments' => $message->getAttachments(),
                'created_at' => $message->getCreatedAt()->format('Y-m-d H:i:s'),
                'status' => 'CREATED'
            ];
            $this->updateMessage($message);
            
            // Send the message to the contact
            $response = $this->httpClient->request(
                'POST',
                $contact->getCqContactUrl() . '/api/federation/chat-message',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $contact->getCqContactApiKey(),
                        'User-Agent' => 'CitadelQuest HTTP Client',
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $messageData
                ]
            );

            $message->setStatus('SENT');
            $this->updateMessage($message);
            
            if ($response->getStatusCode() !== 200) {
                $content = $response->getContent();
                $data = json_decode($content, true);

                $message->setStatus('FAILED');
                $this->updateMessage($message);
                
                return [
                    'success' => false,
                    'message' => 'Failed to send message. ' . ($data['message'] ?? 'Unknown error')
                ];
            }
            
            // Update message status to DELIVERED
            $message->setStatus('DELIVERED');
            $this->updateMessage($message);
            
            return [
                'success' => true,
                'message' => 'Message sent and delivered successfully'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to send message. ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Receive a message from a contact
     */
    public function receiveMessage(array $data, string $cqContactId): CqChatMsg
    {
        // Create or get chat
        $userDb = $this->getUserDb();
        $chat = $userDb->executeQuery(
            'SELECT * FROM cq_chat WHERE cq_contact_id = ? LIMIT 1',
            [$cqContactId]
        )->fetchAssociative();
        
        $chatId = $data['cq_chat_id'] ?? null;
        
        if (!$chat && !$chatId) {
            // Create a new chat
            $contact = $this->cqContactService->findById($cqContactId);
            $chatTitle = $contact ? $contact->getCqContactUsername() : 'Chat';
            
            $userDb->executeStatement(
                'INSERT INTO cq_chat (
                    id, cq_contact_id, title, summary, is_star, is_pin, is_mute, is_active,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $chatId = uuid_create(),
                    $cqContactId,
                    $chatTitle,
                    null,
                    0, // is_star
                    0, // is_pin
                    0, // is_mute
                    1, // is_active
                    date('Y-m-d H:i:s'),
                    date('Y-m-d H:i:s')
                ]
            );
        } else {
            $chatId = $chat['id'] ?? $chatId;
        }
        
        // Create the message
        return $this->createMessage(
            $chatId,
            $cqContactId,
            $data['content'] ?? null,
            $data['attachments'] ?? null,
            'RECEIVED',
            $data['id'] ?? null
        );
    }
}
