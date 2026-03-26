<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Uid\Uuid;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * CQ Feed — manage user's own feeds and posts.
 * 
 */
class CQFeedService
{
    public const REACTION_LIKE = 0;
    public const REACTION_DISLIKE = 1;

    private ?User $user;

    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly Security $security,
        private readonly SluggerInterface $slugger,
        private readonly LoggerInterface $logger,
        private readonly NotificationService $notificationService,
        private readonly TranslatorInterface $translator
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
    public function createFeed(string $title, string $feedUrlSlug, int $scope = CQShareService::SCOPE_CQ_CONTACT, ?string $description = null, ?string $imageProjectFileId = null): array
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

        return $this->createFeed('Public', 'public', CQShareService::SCOPE_PUBLIC);
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

    // ========================================
    // Reactions
    // ========================================

    /**
     * React to a post (upsert). Same reaction again = remove (toggle).
     * Returns the new stats and the user's current reaction (null if removed).
     */
    public function reactToPost(string $postId, string $cqContactId, string $cqContactUrl, int $reaction): array
    {
        $db = $this->getUserDb();

        $existing = $this->getReaction($postId, $cqContactId);

        if ($existing) {
            if ((int) $existing['reaction'] === $reaction) {
                // Same reaction → remove (toggle off)
                $db->executeStatement(
                    'DELETE FROM cq_user_feed_post_reaction WHERE id = ?',
                    [$existing['id']]
                );
                $stats = $this->recalculatePostStats($postId);
                return ['stats' => $stats, 'my_reaction' => null];
            } else {
                // Different reaction → update
                $db->executeStatement(
                    'UPDATE cq_user_feed_post_reaction SET reaction = ?, created_at = ? WHERE id = ?',
                    [$reaction, date('Y-m-d H:i:s'), $existing['id']]
                );
                $stats = $this->recalculatePostStats($postId);
                return ['stats' => $stats, 'my_reaction' => $reaction];
            }
        }

        // New reaction
        $id = Uuid::v4()->toRfc4122();
        $db->executeStatement(
            'INSERT INTO cq_user_feed_post_reaction (id, cq_user_feed_post_id, cq_contact_id, cq_contact_url, reaction, created_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$id, $postId, $cqContactId, $cqContactUrl, $reaction, date('Y-m-d H:i:s')]
        );

        $stats = $this->recalculatePostStats($postId);
        return ['stats' => $stats, 'my_reaction' => $reaction];
    }

    /**
     * Get a specific contact's reaction on a post.
     */
    public function getReaction(string $postId, string $cqContactId): ?array
    {
        $db = $this->getUserDb();
        $row = $db->executeQuery(
            'SELECT * FROM cq_user_feed_post_reaction WHERE cq_user_feed_post_id = ? AND cq_contact_id = ?',
            [$postId, $cqContactId]
        )->fetchAssociative();
        return $row ?: null;
    }

    /**
     * Recalculate and store stats JSON for a post.
     */
    public function recalculatePostStats(string $postId): array
    {
        $db = $this->getUserDb();

        $likes = (int) $db->executeQuery(
            'SELECT COUNT(*) FROM cq_user_feed_post_reaction WHERE cq_user_feed_post_id = ? AND reaction = ?',
            [$postId, self::REACTION_LIKE]
        )->fetchOne();

        $dislikes = (int) $db->executeQuery(
            'SELECT COUNT(*) FROM cq_user_feed_post_reaction WHERE cq_user_feed_post_id = ? AND reaction = ?',
            [$postId, self::REACTION_DISLIKE]
        )->fetchOne();

        $comments = (int) $db->executeQuery(
            'SELECT COUNT(*) FROM cq_user_feed_post_comment WHERE cq_user_feed_post_id = ? AND is_active = 1',
            [$postId]
        )->fetchOne();

        $stats = ['likes' => $likes, 'dislikes' => $dislikes, 'comments' => $comments];
        $statsJson = json_encode($stats);

        $db->executeStatement(
            'UPDATE cq_user_feed_post SET stats = ? WHERE id = ?',
            [$statsJson, $postId]
        );

        return $stats;
    }

    /**
     * Find a post by feed slug and post slug (for federation endpoint).
     */
    public function findPostByFeedSlugAndPostSlug(string $feedSlug, string $postSlug): ?array
    {
        $db = $this->getUserDb();
        $row = $db->executeQuery(
            'SELECT p.* FROM cq_user_feed_post p
             JOIN cq_user_feed f ON p.cq_user_feed_id = f.id
             WHERE f.feed_url_slug = ? AND p.post_url_slug = ? AND f.is_active = 1 AND p.is_active = 1',
            [$feedSlug, $postSlug]
        )->fetchAssociative();
        return $row ?: null;
    }

    // ========================================
    // Comments
    // ========================================

    public const COMMENT_MAX_LENGTH = 2000;
    public const COMMENT_MAX_NESTING = 2; // comment + reply (no deeper)

    /**
     * Add a comment to a post. Returns the new comment row + updated stats.
     */
    public function addComment(string $postId, string $cqContactId, string $cqContactUrl, string $content, ?string $parentId = null): array
    {
        $db = $this->getUserDb();

        $content = mb_substr(trim($content), 0, self::COMMENT_MAX_LENGTH);
        if (empty($content)) {
            throw new \InvalidArgumentException('Comment content cannot be empty');
        }

        // Enforce max nesting: if parent has a parent, reject
        if ($parentId) {
            $parent = $this->findCommentById($parentId);
            if (!$parent) {
                throw new \InvalidArgumentException('Parent comment not found');
            }
            if ($parent['parent_id']) {
                throw new \InvalidArgumentException('Cannot reply to a reply (max 2 nesting levels)');
            }
        }

        $id = Uuid::v4()->toRfc4122();
        $now = date('Y-m-d H:i:s');

        $db->executeStatement(
            'INSERT INTO cq_user_feed_post_comment (id, cq_user_feed_post_id, parent_id, cq_contact_id, cq_contact_url, content, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)',
            [$id, $postId, $parentId, $cqContactId, $cqContactUrl, $content, $now, $now]
        );

        $stats = $this->recalculatePostStats($postId);

        // Notify the post owner about the new comment (skip if commenter is the post owner)
        try {
            if ($this->user && $cqContactId !== $this->user->getId()->toRfc4122()) {
                $commenterName = basename(rtrim($cqContactUrl, '/'));
                $preview = mb_substr($content, 0, 80) . (mb_strlen($content) > 80 ? '…' : '');
                $this->notificationService->createNotification(
                    $this->user,
                    $this->translator->trans('feed.comment_new_title', ['%name%' => $commenterName]),
                    $preview,
                    'info',
                    '/cq-contacts?feed-post=' . urlencode($postId)
                );
            }
        } catch (\Exception $e) {
            $this->logger->warning('CQFeedService::addComment notification error', ['error' => $e->getMessage()]);
        }

        $comment = $this->findCommentById($id);
        return ['comment' => $comment, 'stats' => $stats];
    }

    /**
     * Update a comment's content (only by the original commenter).
     */
    public function updateComment(string $commentId, string $cqContactId, string $newContent): array
    {
        $db = $this->getUserDb();

        $comment = $this->findCommentById($commentId);
        if (!$comment) {
            throw new \InvalidArgumentException('Comment not found');
        }
        if ($comment['cq_contact_id'] !== $cqContactId) {
            throw new \InvalidArgumentException('Not authorized to edit this comment');
        }

        $newContent = mb_substr(trim($newContent), 0, self::COMMENT_MAX_LENGTH);
        if (empty($newContent)) {
            throw new \InvalidArgumentException('Comment content cannot be empty');
        }

        $db->executeStatement(
            'UPDATE cq_user_feed_post_comment SET content = ?, updated_at = ? WHERE id = ?',
            [$newContent, date('Y-m-d H:i:s'), $commentId]
        );

        return $this->findCommentById($commentId);
    }

    /**
     * Delete a comment (only by original commenter). Also deletes child replies.
     */
    public function deleteComment(string $commentId, string $cqContactId): array
    {
        $db = $this->getUserDb();

        $comment = $this->findCommentById($commentId);
        if (!$comment) {
            throw new \InvalidArgumentException('Comment not found');
        }
        if ($comment['cq_contact_id'] !== $cqContactId) {
            throw new \InvalidArgumentException('Not authorized to delete this comment');
        }

        $postId = $comment['cq_user_feed_post_id'];

        // Delete child replies first
        $db->executeStatement(
            'DELETE FROM cq_user_feed_post_comment WHERE parent_id = ?',
            [$commentId]
        );
        $db->executeStatement(
            'DELETE FROM cq_user_feed_post_comment WHERE id = ?',
            [$commentId]
        );

        $stats = $this->recalculatePostStats($postId);
        return ['stats' => $stats];
    }

    /**
     * Toggle comment visibility (post owner moderation).
     */
    public function toggleCommentVisibility(string $commentId): array
    {
        $db = $this->getUserDb();

        $comment = $this->findCommentById($commentId);
        if (!$comment) {
            throw new \InvalidArgumentException('Comment not found');
        }

        $newActive = ((int) $comment['is_active'] === 1) ? 0 : 1;
        $db->executeStatement(
            'UPDATE cq_user_feed_post_comment SET is_active = ? WHERE id = ?',
            [$newActive, $commentId]
        );

        $stats = $this->recalculatePostStats($comment['cq_user_feed_post_id']);
        return ['is_active' => $newActive, 'stats' => $stats];
    }

    /**
     * List comments for a post (active only, with nested replies).
     */
    public function listComments(string $postId, int $page = 1, int $limit = 50): array
    {
        $db = $this->getUserDb();
        $offset = ($page - 1) * $limit;

        // Top-level comments (no parent)
        $topLevel = $db->executeQuery(
            'SELECT * FROM cq_user_feed_post_comment
             WHERE cq_user_feed_post_id = ? AND parent_id IS NULL AND is_active = 1
             ORDER BY created_at ASC LIMIT ? OFFSET ?',
            [$postId, $limit, $offset]
        )->fetchAllAssociative();

        $total = (int) $db->executeQuery(
            'SELECT COUNT(*) FROM cq_user_feed_post_comment
             WHERE cq_user_feed_post_id = ? AND parent_id IS NULL AND is_active = 1',
            [$postId]
        )->fetchOne();

        // Fetch replies for each top-level comment
        $result = [];
        foreach ($topLevel as $comment) {
            $replies = $db->executeQuery(
                'SELECT * FROM cq_user_feed_post_comment
                 WHERE parent_id = ? AND is_active = 1
                 ORDER BY created_at ASC',
                [$comment['id']]
            )->fetchAllAssociative();
            $comment['replies'] = $replies;
            $result[] = $comment;
        }

        return ['comments' => $result, 'total' => $total];
    }

    /**
     * Find a comment by ID (regardless of is_active).
     */
    public function findCommentById(string $id): ?array
    {
        $db = $this->getUserDb();
        $row = $db->executeQuery(
            'SELECT * FROM cq_user_feed_post_comment WHERE id = ?',
            [$id]
        )->fetchAssociative();
        return $row ?: null;
    }
}
