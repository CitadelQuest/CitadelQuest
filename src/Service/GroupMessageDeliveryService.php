<?php

namespace App\Service;

use App\Entity\CqChatMsgDelivery;
use App\Entity\User;
use App\Service\UserDatabaseManager;
use Symfony\Bundle\SecurityBundle\Security;

class GroupMessageDeliveryService
{
    private ?User $user;

    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly Security $security
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

    /**
     * Create delivery records for all group members
     * 
     * @param string $messageId
     * @param array $contactIds Array of contact IDs (recipients)
     * @return array Array of CqChatMsgDelivery
     */
    public function createDeliveryRecords(string $messageId, array $contactIds): array
    {
        $userDb = $this->getUserDb();
        $deliveries = [];

        foreach ($contactIds as $contactId) {
            $delivery = new CqChatMsgDelivery($messageId, $contactId, 'SENT');

            $userDb->executeStatement(
                'INSERT INTO cq_chat_msg_delivery (
                    id, cq_chat_msg_id, cq_contact_id, status, delivered_at, seen_at,
                    created_at, updated_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $delivery->getId(),
                    $delivery->getCqChatMsgId(),
                    $delivery->getCqContactId(),
                    $delivery->getStatus(),
                    $delivery->getDeliveredAt()?->format('Y-m-d H:i:s'),
                    $delivery->getSeenAt()?->format('Y-m-d H:i:s'),
                    $delivery->getCreatedAt()->format('Y-m-d H:i:s'),
                    $delivery->getUpdatedAt()->format('Y-m-d H:i:s')
                ]
            );

            $deliveries[] = $delivery;
        }

        return $deliveries;
    }

    /**
     * Update delivery status for a specific member
     * 
     * @param string $messageId
     * @param string $contactId
     * @param string $status 'SENT', 'DELIVERED', or 'SEEN'
     * @return bool
     */
    public function updateMemberStatus(string $messageId, string $contactId, string $status): bool
    {
        $userDb = $this->getUserDb();
        
        // Get existing delivery record
        $result = $userDb->executeQuery(
            'SELECT * FROM cq_chat_msg_delivery WHERE cq_chat_msg_id = ? AND cq_contact_id = ?',
            [$messageId, $contactId]
        )->fetchAssociative();

        if (!$result) {
            // Create new delivery record if it doesn't exist
            $delivery = new CqChatMsgDelivery($messageId, $contactId, $status);
            
            $userDb->executeStatement(
                'INSERT INTO cq_chat_msg_delivery (
                    id, cq_chat_msg_id, cq_contact_id, status, delivered_at, seen_at,
                    created_at, updated_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $delivery->getId(),
                    $delivery->getCqChatMsgId(),
                    $delivery->getCqContactId(),
                    $delivery->getStatus(),
                    $delivery->getDeliveredAt()?->format('Y-m-d H:i:s'),
                    $delivery->getSeenAt()?->format('Y-m-d H:i:s'),
                    $delivery->getCreatedAt()->format('Y-m-d H:i:s'),
                    $delivery->getUpdatedAt()->format('Y-m-d H:i:s')
                ]
            );
            
            return true;
        }

        // Update existing record
        $delivery = CqChatMsgDelivery::fromArray($result);
        $delivery->setStatus($status);

        $userDb->executeStatement(
            'UPDATE cq_chat_msg_delivery 
             SET status = ?, delivered_at = ?, seen_at = ?, updated_at = ?
             WHERE cq_chat_msg_id = ? AND cq_contact_id = ?',
            [
                $delivery->getStatus(),
                $delivery->getDeliveredAt()?->format('Y-m-d H:i:s'),
                $delivery->getSeenAt()?->format('Y-m-d H:i:s'),
                $delivery->getUpdatedAt()->format('Y-m-d H:i:s'),
                $messageId,
                $contactId
            ]
        );

        return true;
    }

    /**
     * Get delivery status for a message
     * 
     * @param string $messageId
     * @return array
     */
    public function getDeliveryStatus(string $messageId): array
    {
        $userDb = $this->getUserDb();
        
        $results = $userDb->executeQuery(
            'SELECT * FROM cq_chat_msg_delivery WHERE cq_chat_msg_id = ?',
            [$messageId]
        )->fetchAllAssociative();

        $deliveries = array_map(fn($data) => CqChatMsgDelivery::fromArray($data), $results);

        $totalMembers = count($deliveries);
        $delivered = 0;
        $seen = 0;

        foreach ($deliveries as $delivery) {
            if ($delivery->getStatus() === 'DELIVERED' || $delivery->getStatus() === 'SEEN') {
                $delivered++;
            }
            if ($delivery->getStatus() === 'SEEN') {
                $seen++;
            }
        }

        return [
            'total_members' => $totalMembers,
            'delivered' => $delivered,
            'seen' => $seen,
            'deliveries' => $deliveries
        ];
    }

    /**
     * Get "seen by" count for a message
     * 
     * @param string $messageId
     * @return array ['seen' => int, 'total' => int]
     */
    public function getSeenByCount(string $messageId): array
    {
        $userDb = $this->getUserDb();
        
        $result = $userDb->executeQuery(
            'SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = "SEEN" THEN 1 ELSE 0 END) as seen
             FROM cq_chat_msg_delivery 
             WHERE cq_chat_msg_id = ?',
            [$messageId]
        )->fetchAssociative();

        return [
            'seen' => (int) ($result['seen'] ?? 0),
            'total' => (int) ($result['total'] ?? 0)
        ];
    }

    /**
     * Get all delivery records for a message
     * 
     * @param string $messageId
     * @return array Array of CqChatMsgDelivery
     */
    public function getDeliveryRecords(string $messageId): array
    {
        $userDb = $this->getUserDb();
        
        $results = $userDb->executeQuery(
            'SELECT * FROM cq_chat_msg_delivery WHERE cq_chat_msg_id = ? ORDER BY created_at ASC',
            [$messageId]
        )->fetchAllAssociative();

        return array_map(fn($data) => CqChatMsgDelivery::fromArray($data), $results);
    }

    /**
     * Delete all delivery records for a message
     * 
     * @param string $messageId
     * @return bool
     */
    public function deleteDeliveryRecords(string $messageId): bool
    {
        $userDb = $this->getUserDb();
        
        $userDb->executeStatement(
            'DELETE FROM cq_chat_msg_delivery WHERE cq_chat_msg_id = ?',
            [$messageId]
        );

        return true;
    }
}
