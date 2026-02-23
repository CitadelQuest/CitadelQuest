<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;
use Psr\Log\LoggerInterface;

/**
 * CQ Share — core sharing feature for CitadelQuest.
 * Enables users to share files and Memory Packs with CQ Contacts or publicly via URL.
 * 
 * @see /docs/CQ-SHARE.md
 */
class CQShareService
{
    public const SCOPE_PUBLIC = 0;
    public const SCOPE_CQ_CONTACT = 1;
    public const SCOPE_CQ_CONTACT_SELECT = 2;

    public const TYPE_FILE = 'file';
    public const TYPE_CQMPACK = 'cqmpack';

    private ?User $user;

    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly Security $security,
        private readonly LoggerInterface $logger
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
            throw new \RuntimeException('User not set');
        }
        return $this->userDatabaseManager->getDatabaseConnection($this->user);
    }

    // ========================================
    // CRUD Operations
    // ========================================

    /**
     * Create a new share
     */
    public function create(string $sourceType, string $sourceId, string $title, string $shareUrl, int $scope = self::SCOPE_CQ_CONTACT): array
    {
        $db = $this->getUserDb();

        $id = Uuid::v4()->toRfc4122();
        $now = date('Y-m-d H:i:s');

        $db->executeStatement(
            'INSERT INTO cq_share (id, source_type, source_id, title, share_url, scope, is_active, views, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, 0, ?, ?)',
            [$id, $sourceType, $sourceId, $title, $shareUrl, $scope, $now, $now]
        );

        return $this->findById($id);
    }

    /**
     * Find share by ID
     */
    public function findById(string $id): ?array
    {
        $db = $this->getUserDb();
        $row = $db->executeQuery('SELECT * FROM cq_share WHERE id = ?', [$id])->fetchAssociative();
        return $row ?: null;
    }

    /**
     * Find share by share_url slug
     */
    public function findByShareUrl(string $shareUrl): ?array
    {
        $db = $this->getUserDb();
        $row = $db->executeQuery('SELECT * FROM cq_share WHERE share_url = ?', [$shareUrl])->fetchAssociative();
        return $row ?: null;
    }

    /**
     * Find active share by share_url slug
     */
    public function findActiveByShareUrl(string $shareUrl): ?array
    {
        $db = $this->getUserDb();
        $row = $db->executeQuery(
            'SELECT * FROM cq_share WHERE share_url = ? AND is_active = 1',
            [$shareUrl]
        )->fetchAssociative();
        return $row ?: null;
    }

    /**
     * Find active share by ID
     */
    public function findActiveById(string $id): ?array
    {
        $db = $this->getUserDb();
        $row = $db->executeQuery(
            'SELECT * FROM cq_share WHERE id = ? AND is_active = 1',
            [$id]
        )->fetchAssociative();
        return $row ?: null;
    }

    /**
     * List all shares for the current user
     */
    public function listAll(): array
    {
        $db = $this->getUserDb();
        return $db->executeQuery('SELECT * FROM cq_share ORDER BY created_at DESC')->fetchAllAssociative();
    }

    /**
     * List all active shares visible to authenticated Federation contacts.
     * Returns shares with scope=0 (public) or scope=1 (CQ Contact).
     */
    public function listActiveForFederation(): array
    {
        $db = $this->getUserDb();
        return $db->executeQuery(
            'SELECT id, source_type, title, share_url, scope, views, created_at, updated_at
             FROM cq_share
             WHERE is_active = 1 AND scope IN (?, ?)
             ORDER BY updated_at DESC',
            [self::SCOPE_PUBLIC, self::SCOPE_CQ_CONTACT]
        )->fetchAllAssociative();
    }

    /**
     * Update share properties
     */
    public function update(string $id, array $data): ?array
    {
        $db = $this->getUserDb();

        $allowedFields = ['title', 'share_url', 'scope', 'is_active'];
        $sets = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($sets)) {
            return $this->findById($id);
        }

        $sets[] = "updated_at = ?";
        $params[] = date('Y-m-d H:i:s');
        $params[] = $id;

        $db->executeStatement(
            'UPDATE cq_share SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        );

        return $this->findById($id);
    }

    /**
     * Delete a share
     */
    public function delete(string $id): bool
    {
        $db = $this->getUserDb();
        return $db->executeStatement('DELETE FROM cq_share WHERE id = ?', [$id]) > 0;
    }

    // ========================================
    // Access & Scope
    // ========================================

    /**
     * Increment view counter
     */
    public function incrementViews(string $id): void
    {
        $db = $this->getUserDb();
        $db->executeStatement('UPDATE cq_share SET views = views + 1 WHERE id = ?', [$id]);
    }

    /**
     * Check if a share is accessible given the scope and auth context.
     * Returns true if access is allowed.
     * 
     * @param array $share The share record
     * @param bool $isAuthenticated Whether the requester has a valid CQ Contact API key
     */
    public function isAccessible(array $share, bool $isAuthenticated = false): bool
    {
        if (!$share['is_active']) {
            return false;
        }

        return match ((int) $share['scope']) {
            self::SCOPE_PUBLIC => true,
            self::SCOPE_CQ_CONTACT => $isAuthenticated,
            self::SCOPE_CQ_CONTACT_SELECT => $isAuthenticated, // TODO: check specific contact list
            default => false,
        };
    }

    // ========================================
    // ProjectFile integration
    // ========================================

    /**
     * Touch updated_at for all shares linked to a given source file.
     * Called from ProjectFileService::updateFile() when a shared file is modified.
     */
    public function touchBySourceId(string $sourceId): void
    {
        $db = $this->getUserDb();
        $db->executeStatement(
            'UPDATE cq_share SET updated_at = ? WHERE source_id = ?',
            [date('Y-m-d H:i:s'), $sourceId]
        );
    }

    /**
     * Find shares by source_id (e.g., when a file is updated)
     */
    public function findBySourceId(string $sourceId): array
    {
        $db = $this->getUserDb();
        return $db->executeQuery(
            'SELECT * FROM cq_share WHERE source_id = ?',
            [$sourceId]
        )->fetchAllAssociative();
    }

    /**
     * Get share metadata for the sync protocol (POST response).
     */
    public function getShareMetadata(array $share): array
    {
        return [
            'id' => $share['id'],
            'title' => $share['title'],
            'source_type' => $share['source_type'],
            'updated_at' => $share['updated_at'],
            'views' => (int) $share['views'],
        ];
    }
}
