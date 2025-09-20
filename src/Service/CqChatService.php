<?php

namespace App\Service;

use App\Entity\CqChat;
use App\Entity\User;
use App\Service\UserDatabaseManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CqChatService
{
    private ?User $user;

    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly Security $security,
        private readonly HttpClientInterface $httpClient
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

    public function createChat(
        string $cqContactId,
        string $title,
        ?string $summary = null,
        bool $isStar = true,
        bool $isPin = true,
        bool $isMute = true,
        bool $isActive = true,
        ?string $specificId = null
    ): CqChat {
        $chat = new CqChat(
            $cqContactId,
            $title,
            $summary,
            $isStar,
            $isPin,
            $isMute,
            $isActive,
            $specificId
        );

        // Store in user's database
        $userDb = $this->getUserDb();
        $userDb->executeStatement(
            'INSERT INTO cq_chat (
                id, cq_contact_id, title, summary, is_star, is_pin, is_mute, is_active,
                created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $chat->getId(),
                $chat->getCqContactId(),
                $chat->getTitle(),
                $chat->getSummary(),
                $chat->isStar() ? 1 : 0,
                $chat->isPin() ? 1 : 0,
                $chat->isMute() ? 1 : 0,
                $chat->isActive() ? 1 : 0,
                $chat->getCreatedAt()->format('Y-m-d H:i:s'),
                $chat->getUpdatedAt()->format('Y-m-d H:i:s')
            ]
        );

        return $chat;
    }

    public function findById(string $id): ?CqChat
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT * FROM cq_chat WHERE id = ?',
            [$id]
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        return CqChat::fromArray($result);
    }

    public function findByContactId(string $cqContactId, bool $activeOnly = true): array
    {
        $userDb = $this->getUserDb();
        $sql = 'SELECT * FROM cq_chat WHERE cq_contact_id = ?';
        $params = [$cqContactId];
        
        if ($activeOnly) {
            $sql .= ' AND is_active = ?';
            $params[] = 1;
        }
        
        $sql .= ' ORDER BY updated_at DESC';
        
        $results = $userDb->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(fn($data) => CqChat::fromArray($data), $results);
    }

    public function findAll(bool $activeOnly = true): array
    {
        $userDb = $this->getUserDb();
        $sql = 'SELECT * FROM cq_chat';
        $params = [];
        
        if ($activeOnly) {
            $sql .= ' WHERE is_active = ?';
            $params[] = 1;
        }
        
        $sql .= ' ORDER BY updated_at DESC';
        
        $results = $userDb->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(fn($data) => CqChat::fromArray($data), $results);
    }

    public function findStarred(): array
    {
        $userDb = $this->getUserDb();
        $results = $userDb->executeQuery(
            'SELECT * FROM cq_chat WHERE is_star = ? AND is_active = ? ORDER BY updated_at DESC',
            [1, 1]
        )->fetchAllAssociative();

        return array_map(fn($data) => CqChat::fromArray($data), $results);
    }

    public function findPinned(): array
    {
        $userDb = $this->getUserDb();
        $results = $userDb->executeQuery(
            'SELECT * FROM cq_chat WHERE is_pin = ? AND is_active = ? ORDER BY updated_at DESC',
            [1, 1]
        )->fetchAllAssociative();

        return array_map(fn($data) => CqChat::fromArray($data), $results);
    }

    public function updateChat(CqChat $chat): bool
    {
        $chat->updateUpdatedAt();
        
        $userDb = $this->getUserDb();
        $result = $userDb->executeStatement(
            'UPDATE cq_chat SET 
                cq_contact_id = ?, title = ?, summary = ?,
                is_star = ?, is_pin = ?, is_mute = ?, is_active = ?, updated_at = ?
             WHERE id = ?',
            [
                $chat->getCqContactId(),
                $chat->getTitle(),
                $chat->getSummary(),
                $chat->isStar() ? 1 : 0,
                $chat->isPin() ? 1 : 0,
                $chat->isMute() ? 1 : 0,
                $chat->isActive() ? 1 : 0,
                $chat->getUpdatedAt()->format('Y-m-d H:i:s'),
                $chat->getId()
            ]
        );

        return $result > 0;
    }

    public function deleteChat(string $id): bool
    {
        $userDb = $this->getUserDb();

        // delete also related `cq_chat_msg`
        $userDb->executeStatement(
            'DELETE FROM cq_chat_msg WHERE cq_chat_id = ?',
            [$id]
        );
        
        $result = $userDb->executeStatement(
            'DELETE FROM cq_chat WHERE id = ?',
            [$id]
        );

        return $result > 0;
    }

    public function toggleStar(string $id): bool
    {
        $chat = $this->findById($id);
        if (!$chat) {
            return false;
        }
        
        $chat->setIsStar(!$chat->isStar());
        return $this->updateChat($chat);
    }

    public function togglePin(string $id): bool
    {
        $chat = $this->findById($id);
        if (!$chat) {
            return false;
        }
        
        $chat->setIsPin(!$chat->isPin());
        return $this->updateChat($chat);
    }

    public function toggleMute(string $id): bool
    {
        $chat = $this->findById($id);
        if (!$chat) {
            return false;
        }
        
        $chat->setIsMute(!$chat->isMute());
        return $this->updateChat($chat);
    }

    public function activateChat(string $id): bool
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeStatement(
            'UPDATE cq_chat SET is_active = 1, updated_at = ? WHERE id = ?',
            [date('Y-m-d H:i:s'), $id]
        );

        return $result > 0;
    }

    public function deactivateChat(string $id): bool
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeStatement(
            'UPDATE cq_chat SET is_active = 0, updated_at = ? WHERE id = ?',
            [date('Y-m-d H:i:s'), $id]
        );

        return $result > 0;
    }
}
