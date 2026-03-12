<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;
use Psr\Log\LoggerInterface;

/**
 * CQ Follow — follow/unfollow CQ Profiles and manage followers.
 * 
 * @see /docs/features/CQ-FOLLOW.md
 */
class CQFollowService
{
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
    // CQ Follow (who I follow)
    // ========================================

    /**
     * Follow a CQ Profile
     */
    public function follow(string $cqContactId, string $cqContactUrl, string $cqContactDomain, string $cqContactUsername): array
    {
        $db = $this->getUserDb();

        // Check if already following
        $existing = $this->getFollowByContactId($cqContactId);
        if ($existing) {
            return $existing;
        }

        $id = Uuid::v4()->toRfc4122();
        $now = date('Y-m-d H:i:s');

        $db->executeStatement(
            'INSERT INTO cq_follow (id, cq_contact_id, cq_contact_url, cq_contact_domain, cq_contact_username, last_visited_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $cqContactId, $cqContactUrl, $cqContactDomain, $cqContactUsername, $now, $now]
        );

        return $this->getFollowById($id);
    }

    /**
     * Unfollow a CQ Profile
     */
    public function unfollow(string $cqContactId): bool
    {
        $db = $this->getUserDb();
        $affected = $db->executeStatement('DELETE FROM cq_follow WHERE cq_contact_id = ?', [$cqContactId]);
        return $affected > 0;
    }

    /**
     * Get follow entry by cq_contact_id
     */
    public function getFollowByContactId(string $cqContactId): ?array
    {
        $db = $this->getUserDb();
        $row = $db->executeQuery('SELECT * FROM cq_follow WHERE cq_contact_id = ?', [$cqContactId])->fetchAssociative();
        return $row ?: null;
    }

    /**
     * Get follow entry by ID
     */
    public function getFollowById(string $id): ?array
    {
        $db = $this->getUserDb();
        $row = $db->executeQuery('SELECT * FROM cq_follow WHERE id = ?', [$id])->fetchAssociative();
        return $row ?: null;
    }

    /**
     * List all follows
     */
    public function listFollows(): array
    {
        $db = $this->getUserDb();
        return $db->executeQuery('SELECT * FROM cq_follow ORDER BY created_at DESC')->fetchAllAssociative();
    }

    /**
     * Update last_visited_at for a follow
     */
    public function updateLastVisited(string $cqContactId): void
    {
        $db = $this->getUserDb();
        $now = date('Y-m-d H:i:s');
        $db->executeStatement('UPDATE cq_follow SET last_visited_at = ? WHERE cq_contact_id = ?', [$now, $cqContactId]);
    }

    /**
     * Update cq_contact_url and domain after migration
     */
    public function updateFollowUrl(string $cqContactId, string $newUrl, string $newDomain, string $newUsername): void
    {
        $db = $this->getUserDb();
        $db->executeStatement(
            'UPDATE cq_follow SET cq_contact_url = ?, cq_contact_domain = ?, cq_contact_username = ? WHERE cq_contact_id = ?',
            [$newUrl, $newDomain, $newUsername, $cqContactId]
        );
    }

    /**
     * Get follow count
     */
    public function getFollowCount(): int
    {
        $db = $this->getUserDb();
        return (int) $db->executeQuery('SELECT COUNT(*) FROM cq_follow')->fetchOne();
    }

    /**
     * Check if following a specific contact
     */
    public function isFollowing(string $cqContactId): bool
    {
        return $this->getFollowByContactId($cqContactId) !== null;
    }

    // ========================================
    // CQ Followers (who follows me)
    // ========================================

    /**
     * Add a follower (called when receiving follow notification from remote Citadel)
     */
    public function addFollower(string $cqContactId, string $cqContactUrl, string $cqContactDomain, string $cqContactUsername): array
    {
        $db = $this->getUserDb();

        // Check if already a follower
        $existing = $this->getFollowerByContactId($cqContactId);
        if ($existing) {
            // Update info in case it changed
            $db->executeStatement(
                'UPDATE cq_followers SET cq_contact_url = ?, cq_contact_domain = ?, cq_contact_username = ? WHERE cq_contact_id = ?',
                [$cqContactUrl, $cqContactDomain, $cqContactUsername, $cqContactId]
            );
            return $this->getFollowerByContactId($cqContactId);
        }

        $id = Uuid::v4()->toRfc4122();
        $now = date('Y-m-d H:i:s');

        $db->executeStatement(
            'INSERT INTO cq_followers (id, cq_contact_id, cq_contact_url, cq_contact_domain, cq_contact_username, created_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$id, $cqContactId, $cqContactUrl, $cqContactDomain, $cqContactUsername, $now]
        );

        return $this->getFollowerById($id);
    }

    /**
     * Remove a follower (called when receiving unfollow notification)
     */
    public function removeFollower(string $cqContactId): bool
    {
        $db = $this->getUserDb();
        $affected = $db->executeStatement('DELETE FROM cq_followers WHERE cq_contact_id = ?', [$cqContactId]);
        return $affected > 0;
    }

    /**
     * Get follower by cq_contact_id
     */
    public function getFollowerByContactId(string $cqContactId): ?array
    {
        $db = $this->getUserDb();
        $row = $db->executeQuery('SELECT * FROM cq_followers WHERE cq_contact_id = ?', [$cqContactId])->fetchAssociative();
        return $row ?: null;
    }

    /**
     * Get follower by ID
     */
    public function getFollowerById(string $id): ?array
    {
        $db = $this->getUserDb();
        $row = $db->executeQuery('SELECT * FROM cq_followers WHERE id = ?', [$id])->fetchAssociative();
        return $row ?: null;
    }

    /**
     * List all followers
     */
    public function listFollowers(): array
    {
        $db = $this->getUserDb();
        return $db->executeQuery('SELECT * FROM cq_followers ORDER BY created_at DESC')->fetchAllAssociative();
    }

    /**
     * Get follower count
     */
    public function getFollowerCount(): int
    {
        $db = $this->getUserDb();
        return (int) $db->executeQuery('SELECT COUNT(*) FROM cq_followers')->fetchOne();
    }

    /**
     * Update follower URL after migration notification
     */
    public function updateFollowerUrl(string $cqContactId, string $newUrl, string $newDomain, string $newUsername): void
    {
        $db = $this->getUserDb();
        $db->executeStatement(
            'UPDATE cq_followers SET cq_contact_url = ?, cq_contact_domain = ?, cq_contact_username = ? WHERE cq_contact_id = ?',
            [$newUrl, $newDomain, $newUsername, $cqContactId]
        );
    }
}
