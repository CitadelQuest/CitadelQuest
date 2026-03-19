<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Uid\Uuid;
use Psr\Log\LoggerInterface;

/**
 * CQ Feed — manage user's own feeds and posts.
 * 
 */
class CQFeedService
{
    public const SCOPE_PUBLIC = 0;
    public const SCOPE_CQ_CONTACT = 1;

    private ?User $user;

    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly Security $security,
        private readonly SluggerInterface $slugger,
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
    // Feed CRUD
    // ========================================

    /**
     * Create a new feed
     */
    public function createFeed(string $title, string $feedUrlSlug, int $scope = self::SCOPE_CQ_CONTACT, ?string $description = null, ?string $imageProjectFileId = null): array
    {
        $db = $this->getUserDb();

        $id = Uuid::v4()->toRfc4122();
        $now = date('Y-m-d H:i:s');

        $db->executeStatement(
            'INSERT INTO cq_user_feed (id, title, feed_url_slug, scope, description, image_project_file_id, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)',
            [$id, $title, $feedUrlSlug, $scope, $description, $imageProjectFileId, $now, $now]
        );

        return $this->findFeedById($id);
    }

    /**
     * Update a feed
     */
    public function updateFeed(string $id, array $data): ?array
    {
        $db = $this->getUserDb();

        $allowedFields = ['title', 'feed_url_slug', 'scope', 'description', 'image_project_file_id', 'is_active'];
        $sets = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($sets)) {
            return $this->findFeedById($id);
        }

        $sets[] = 'updated_at = ?';
        $params[] = date('Y-m-d H:i:s');
        $params[] = $id;

        $db->executeStatement(
            'UPDATE cq_user_feed SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        );

        return $this->findFeedById($id);
    }

    /**
     * Delete a feed and all its posts
     */
    public function deleteFeed(string $id): bool
    {
        $db = $this->getUserDb();
        $db->executeStatement('DELETE FROM cq_user_feed_post WHERE cq_user_feed_id = ?', [$id]);
        return $db->executeStatement('DELETE FROM cq_user_feed WHERE id = ?', [$id]) > 0;
    }

    /**
     * List all feeds for the current user
     */
    public function listFeeds(): array
    {
        $db = $this->getUserDb();
        return $db->executeQuery(
            'SELECT * FROM cq_user_feed ORDER BY created_at ASC'
        )->fetchAllAssociative();
    }

    /**
     * List active feeds filtered by allowed scopes
     */
    public function listActiveFeedsByScope(array $allowedScopes): array
    {
        $db = $this->getUserDb();
        $placeholders = implode(',', array_fill(0, count($allowedScopes), '?'));

        return $db->executeQuery(
            'SELECT * FROM cq_user_feed WHERE is_active = 1 AND scope IN (' . $placeholders . ') ORDER BY created_at ASC',
            $allowedScopes
        )->fetchAllAssociative();
    }

    /**
     * Find feed by ID
     */
    public function findFeedById(string $id): ?array
    {
        $db = $this->getUserDb();
        $row = $db->executeQuery('SELECT * FROM cq_user_feed WHERE id = ?', [$id])->fetchAssociative();
        return $row ?: null;
    }

    /**
     * Find active feed by URL slug
     */
    public function findFeedBySlug(string $slug): ?array
    {
        $db = $this->getUserDb();
        $row = $db->executeQuery(
            'SELECT * FROM cq_user_feed WHERE feed_url_slug = ? AND is_active = 1',
            [$slug]
        )->fetchAssociative();
        return $row ?: null;
    }

    // ========================================
    // Post CRUD
    // ========================================

    /**
     * Create a new post in a feed
     */
    public function createPost(string $feedId, string $content, string $postUrlSlug): array
    {
        $db = $this->getUserDb();

        $id = Uuid::v4()->toRfc4122();
        $now = date('Y-m-d H:i:s');

        $db->executeStatement(
            'INSERT INTO cq_user_feed_post (id, cq_user_feed_id, post_url_slug, content, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, ?, ?)',
            [$id, $feedId, $postUrlSlug, $content, $now, $now]
        );

        // Touch feed updated_at
        $db->executeStatement(
            'UPDATE cq_user_feed SET updated_at = ? WHERE id = ?',
            [$now, $feedId]
        );

        return $this->findPostById($id);
    }

    /**
     * Update a post
     */
    public function updatePost(string $id, array $data): ?array
    {
        $db = $this->getUserDb();

        $allowedFields = ['content', 'post_url_slug', 'is_active'];
        $sets = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($sets)) {
            return $this->findPostById($id);
        }

        $now = date('Y-m-d H:i:s');
        $sets[] = 'updated_at = ?';
        $params[] = $now;
        $params[] = $id;

        $db->executeStatement(
            'UPDATE cq_user_feed_post SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        );

        // Touch parent feed updated_at
        $post = $this->findPostById($id);
        if ($post) {
            $db->executeStatement(
                'UPDATE cq_user_feed SET updated_at = ? WHERE id = ?',
                [$now, $post['cq_user_feed_id']]
            );
        }

        return $post;
    }

    /**
     * Delete a post
     */
    public function deletePost(string $id): bool
    {
        $db = $this->getUserDb();

        // Touch parent feed updated_at before deleting
        $post = $this->findPostById($id);
        if ($post) {
            $db->executeStatement(
                'UPDATE cq_user_feed SET updated_at = ? WHERE id = ?',
                [date('Y-m-d H:i:s'), $post['cq_user_feed_id']]
            );
        }

        return $db->executeStatement('DELETE FROM cq_user_feed_post WHERE id = ?', [$id]) > 0;
    }

    /**
     * Find post by ID
     */
    public function findPostById(string $id): ?array
    {
        $db = $this->getUserDb();
        $row = $db->executeQuery('SELECT * FROM cq_user_feed_post WHERE id = ?', [$id])->fetchAssociative();
        return $row ?: null;
    }

    /**
     * List posts in a feed (paginated)
     */
    public function listPosts(string $feedId, int $page = 1, int $limit = 20, ?string $since = null): array
    {
        $db = $this->getUserDb();
        $offset = ($page - 1) * $limit;

        if ($since) {
            $rows = $db->executeQuery(
                'SELECT * FROM cq_user_feed_post
                 WHERE cq_user_feed_id = ? AND is_active = 1 AND created_at > ?
                 ORDER BY created_at DESC LIMIT ? OFFSET ?',
                [$feedId, $since, $limit, $offset]
            )->fetchAllAssociative();
        } else {
            $rows = $db->executeQuery(
                'SELECT * FROM cq_user_feed_post
                 WHERE cq_user_feed_id = ? AND is_active = 1
                 ORDER BY created_at DESC LIMIT ? OFFSET ?',
                [$feedId, $limit, $offset]
            )->fetchAllAssociative();
        }

        return $rows;
    }

    /**
     * Count active posts in a feed
     */
    public function countPosts(string $feedId): int
    {
        $db = $this->getUserDb();
        return (int) $db->executeQuery(
            'SELECT COUNT(*) FROM cq_user_feed_post WHERE cq_user_feed_id = ? AND is_active = 1',
            [$feedId]
        )->fetchOne();
    }

    /**
     * Get the most recent updated_at from posts in a feed.
     * Used by federation last-updated endpoint.
     */
    public function getLastPostUpdatedAt(string $feedId): ?string
    {
        $db = $this->getUserDb();
        $result = $db->executeQuery(
            'SELECT MAX(updated_at) FROM cq_user_feed_post WHERE cq_user_feed_id = ? AND is_active = 1',
            [$feedId]
        )->fetchOne();
        return $result ?: null;
    }

    /**
     * Get the most recent updated_at across all active feeds.
     * Used by federation last-updated endpoint for global feed polling.
     */
    public function getLastFeedUpdatedAt(): ?string
    {
        $db = $this->getUserDb();
        $result = $db->executeQuery(
            'SELECT MAX(updated_at) FROM cq_user_feed WHERE is_active = 1'
        )->fetchOne();
        return $result ?: null;
    }

    // ========================================
    // Default Feed
    // ========================================

    /**
     * Create the default "Public" feed for a new user.
     * Idempotent — skips if a feed with slug "public" already exists.
     */
    public function createDefaultFeed(): ?array
    {
        $existing = $this->findFeedBySlug('public');
        if ($existing) {
            return $existing;
        }

        return $this->createFeed('Public', 'public', self::SCOPE_CQ_CONTACT);
    }

    // ========================================
    // Slug generation
    // ========================================

    /**
     * Generate a URL-safe slug from a title.
     * Appends a short random suffix if the slug already exists.
     */
    public function generateSlug(string $title): string
    {
        $slug = $this->slugger->slug($title)->lower()->toString();
        if (empty($slug)) {
            $slug = 'feed';
        }

        // Check uniqueness for feed slugs
        $existing = $this->findFeedBySlug($slug);
        if ($existing) {
            $slug .= '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        }

        return $slug;
    }

    /**
     * Generate a unique post URL slug.
     */
    public function generatePostSlug(string $feedId, ?string $title = null): string
    {
        $base = $title ? $this->slugger->slug($title)->lower()->toString() : '';
        if (empty($base)) {
            $base = date('Ymd-His');
        }

        // Add short random suffix for uniqueness
        return $base . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    }
}
