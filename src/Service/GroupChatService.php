<?php

namespace App\Service;

use App\Entity\CqChat;
use App\Entity\CqChatGroupMember;
use App\Entity\User;
use App\Service\UserDatabaseManager;
use Symfony\Bundle\SecurityBundle\Security;

class GroupChatService
{
    private ?User $user;

    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly Security $security,
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

    /**
     * Create a new group chat
     * 
     * @param string $groupName
     * @param array $contactIds Array of contact IDs to add as members
     * @return CqChat
     */
    public function createGroupChat(string $groupName, array $contactIds): CqChat
    {
        $userDb = $this->getUserDb();
        
        try {
            // Create the group chat (cq_contact_id is NULL for group chats)
            $chat = new CqChat(
                null, // cq_contact_id is NULL for group chats
                $groupName,
                null, // summary
                false, // is_star
                false, // is_pin
                false, // is_mute
                true, // is_active
                true, // is_group_chat
                null // group_host_contact_id is NULL for local user as host
            );

            // Store in cq_chat table
            $userDb->executeStatement(
                'INSERT INTO cq_chat (
                    id, cq_contact_id, title, summary, is_star, is_pin, is_mute, is_active,
                    is_group_chat, group_host_contact_id, created_at, updated_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $chat->getId(),
                    $chat->getCqContactId(),
                    $chat->getTitle(),
                    $chat->getSummary(),
                    $chat->isStar() ? 1 : 0,
                    $chat->isPin() ? 1 : 0,
                    $chat->isMute() ? 1 : 0,
                    $chat->isActive() ? 1 : 0,
                    $chat->isGroupChat() ? 1 : 0,
                    $chat->getGroupHostContactId(),
                    $chat->getCreatedAt()->format('Y-m-d H:i:s'),
                    $chat->getUpdatedAt()->format('Y-m-d H:i:s')
                ]
            );

            // Add all members using the same connection
            foreach ($contactIds as $contactId) {
                $this->addMemberToChatWithConnection($userDb, $chat->getId(), $contactId, 'member');
            }
            
            return $chat;
            
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Add a member to a group chat
     * 
     * @param string $chatId
     * @param string $contactId
     * @param string $role 'host' or 'member'
     * @return CqChatGroupMember
     */
    public function addMember(string $chatId, string $contactId, string $role = 'member'): CqChatGroupMember
    {
        // Check if user is host
        if (!$this->isUserHost($chatId)) {
            throw new \Exception('Only the host can add members');
        }

        return $this->addMemberToChat($chatId, $contactId, $role);
    }

    /**
     * Internal method to add member with provided connection
     */
    private function addMemberToChatWithConnection($connection, string $chatId, string $contactId, string $role = 'member'): CqChatGroupMember
    {
        // Check if member already exists
        $existing = $connection->executeQuery(
            'SELECT * FROM cq_chat_group_members WHERE cq_chat_id = ? AND cq_contact_id = ?',
            [$chatId, $contactId]
        )->fetchAssociative();

        if ($existing) {
            throw new \Exception('Member already exists in this group');
        }

        $member = new CqChatGroupMember($chatId, $contactId, $role);

        $connection->executeStatement(
            'INSERT INTO cq_chat_group_members (
                id, cq_chat_id, cq_contact_id, role, joined_at, created_at
             ) VALUES (?, ?, ?, ?, ?, ?)',
            [
                $member->getId(),
                $member->getCqChatId(),
                $member->getCqContactId(),
                $member->getRole(),
                $member->getJoinedAt()->format('Y-m-d H:i:s'),
                $member->getCreatedAt()->format('Y-m-d H:i:s')
            ]
        );

        return $member;
    }
    
    /**
     * Internal method to add member without host check (uses new connection)
     */
    private function addMemberToChat(string $chatId, string $contactId, string $role = 'member'): CqChatGroupMember
    {
        $userDb = $this->getUserDb();
        return $this->addMemberToChatWithConnection($userDb, $chatId, $contactId, $role);
    }

    /**
     * Remove a member from a group chat
     * 
     * @param string $chatId
     * @param string $contactId
     * @return bool
     */
    public function removeMember(string $chatId, string $contactId): bool
    {
        // Check if user is host
        if (!$this->isUserHost($chatId)) {
            throw new \Exception('Only the host can remove members');
        }

        $userDb = $this->getUserDb();
        
        $userDb->executeStatement(
            'DELETE FROM cq_chat_group_members WHERE cq_chat_id = ? AND cq_contact_id = ?',
            [$chatId, $contactId]
        );

        return true;
    }

    /**
     * Get all members of a group chat
     * 
     * @param string $chatId
     * @return array Array of CqChatGroupMember
     */
    public function getGroupMembers(string $chatId): array
    {
        $userDb = $this->getUserDb();
        
        $results = $userDb->executeQuery(
            'SELECT * FROM cq_chat_group_members WHERE cq_chat_id = ? ORDER BY joined_at ASC',
            [$chatId]
        )->fetchAllAssociative();

        return array_map(fn($data) => CqChatGroupMember::fromArray($data), $results);
    }

    /**
     * Get member count for a group chat
     * 
     * @param string $chatId
     * @return int
     */
    public function getMemberCount(string $chatId): int
    {
        $userDb = $this->getUserDb();
        
        $result = $userDb->executeQuery(
            'SELECT COUNT(*) as count FROM cq_chat_group_members WHERE cq_chat_id = ?',
            [$chatId]
        )->fetchAssociative();

        return (int) $result['count'];
    }

    /**
     * Check if current user is the host of a group chat
     * 
     * @param string $chatId
     * @return bool
     */
    public function isUserHost(string $chatId): bool
    {
        $chat = $this->cqChatService->findById($chatId);
        
        if (!$chat || !$chat->isGroupChat()) {
            return false;
        }

        // If group_host_contact_id is NULL, the local user is the host
        return $chat->getGroupHostContactId() === null;
    }

    /**
     * Check if a contact is a member of a group chat
     * 
     * @param string $chatId
     * @param string $contactId
     * @return bool
     */
    public function isMember(string $chatId, string $contactId): bool
    {
        $userDb = $this->getUserDb();
        
        $result = $userDb->executeQuery(
            'SELECT COUNT(*) as count FROM cq_chat_group_members WHERE cq_chat_id = ? AND cq_contact_id = ?',
            [$chatId, $contactId]
        )->fetchAssociative();

        return (int) $result['count'] > 0;
    }

    /**
     * Get the host contact ID of a group chat
     * Returns NULL if local user is the host
     * 
     * @param string $chatId
     * @return string|null
     */
    public function getHostContactId(string $chatId): ?string
    {
        $chat = $this->cqChatService->findById($chatId);
        
        if (!$chat || !$chat->isGroupChat()) {
            return null;
        }

        return $chat->getGroupHostContactId();
    }
}
