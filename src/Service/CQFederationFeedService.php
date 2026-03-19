<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

/**
 * CQ Federation Feed — manage subscribed feeds and cached posts from remote Citadels.
 * 
 */
class CQFederationFeedService
{
    private ?User $user;

    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly HttpClientInterface $httpClient,
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
    // Subscribed Feed Management
    // ========================================

    /**
     * Subscribe to a remote feed
     */
    public function subscribeFeed(
        string $cqContactId,
        string $cqContactUrl,
        string $cqContactDomain,
        string $cqContactUsername,
        string $feedUrlSlug,
        string $title,
        ?string $description = null
    ): array {
        $db = $this->getUserDb();

        // Check if already subscribed
        $existing = $this->findByContactAndSlug($cqContactId, $feedUrlSlug);
        if ($existing) {
            return $existing;
        }

        $id = Uuid::v4()->toRfc4122();
        $now = date('Y-m-d H:i:s');

        $db->executeStatement(
            'INSERT INTO cq_federation_feed (id, cq_contact_id, cq_contact_url, cq_contact_domain, cq_contact_username, feed_url_slug, title, description, is_active, created_at, updated_at, last_visited_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)',
            [$id, $cqContactId, $cqContactUrl, $cqContactDomain, $cqContactUsername, $feedUrlSlug, $title, $description, $now, $now, $now]
        );

        return $this->findById($id);
    }

    /**
     * Unsubscribe from a feed and delete cached posts
     */
    public function unsubscribeFeed(string $id): bool
    {
        $db = $this->getUserDb();
        $db->executeStatement('DELETE FROM cq_federation_feed_post WHERE cq_feed_id = ?', [$id]);
        return $db->executeStatement('DELETE FROM cq_federation_feed WHERE id = ?', [$id]) > 0;
    }

    /**
     * Find subscribed feed by ID
     */
    public function findById(string $id): ?array
    {
        $db = $this->getUserDb();
        $row = $db->executeQuery('SELECT * FROM cq_federation_feed WHERE id = ?', [$id])->fetchAssociative();
        return $row ?: null;
    }

    /**
     * Find subscribed feed by contact ID and feed slug
     */
    public function findByContactAndSlug(string $cqContactId, string $feedUrlSlug): ?array
    {
        $db = $this->getUserDb();
        $row = $db->executeQuery(
            'SELECT * FROM cq_federation_feed WHERE cq_contact_id = ? AND feed_url_slug = ?',
            [$cqContactId, $feedUrlSlug]
        )->fetchAssociative();
        return $row ?: null;
    }

    /**
     * List all subscribed feeds
     */
    public function listSubscribedFeeds(): array
    {
        $db = $this->getUserDb();
        return $db->executeQuery(
            'SELECT * FROM cq_federation_feed ORDER BY updated_at DESC'
        )->fetchAllAssociative();
    }

    /**
     * List subscribed feeds for a specific contact
     */
    public function listFeedsByContact(string $cqContactId): array
    {
        $db = $this->getUserDb();
        return $db->executeQuery(
            'SELECT * FROM cq_federation_feed WHERE cq_contact_id = ? ORDER BY created_at ASC',
            [$cqContactId]
        )->fetchAllAssociative();
    }

    /**
     * Toggle feed active status
     */
    public function toggleActive(string $id): ?array
    {
        $db = $this->getUserDb();
        $feed = $this->findById($id);
        if (!$feed) return null;

        $newActive = $feed['is_active'] ? 0 : 1;
        $db->executeStatement(
            'UPDATE cq_federation_feed SET is_active = ?, updated_at = ? WHERE id = ?',
            [$newActive, date('Y-m-d H:i:s'), $id]
        );

        return $this->findById($id);
    }

    /**
     * Update last_visited_at for a subscribed feed
     */
    public function updateLastVisited(string $id): void
    {
        $db = $this->getUserDb();
        $db->executeStatement(
            'UPDATE cq_federation_feed SET last_visited_at = ? WHERE id = ?',
            [date('Y-m-d H:i:s'), $id]
        );
    }

    /**
     * Update contact URL after migration
     */
    public function updateContactUrl(string $cqContactId, string $newUrl, string $newDomain, string $newUsername): void
    {
        $db = $this->getUserDb();
        $db->executeStatement(
            'UPDATE cq_federation_feed SET cq_contact_url = ?, cq_contact_domain = ?, cq_contact_username = ? WHERE cq_contact_id = ?',
            [$newUrl, $newDomain, $newUsername, $cqContactId]
        );
        $db->executeStatement(
            'UPDATE cq_federation_feed_post SET cq_contact_url = ?, cq_contact_domain = ?, cq_contact_username = ? WHERE cq_contact_id = ?',
            [$newUrl, $newDomain, $newUsername, $cqContactId]
        );
    }

    // ========================================
    // Federation Feed Post Cache
    // ========================================

    /**
     * Store a cached federation feed post
     */
    public function cachePost(
        string $feedId,
        string $remotePostId,
        string $cqContactId,
        string $cqContactUrl,
        string $cqContactDomain,
        string $cqContactUsername,
        string $postUrlSlug,
        string $content,
        string $createdAt,
        string $updatedAt
    ): array {
        $db = $this->getUserDb();

        // Check if post already cached (by remote ID)
        $existing = $db->executeQuery(
            'SELECT * FROM cq_federation_feed_post WHERE id = ?',
            [$remotePostId]
        )->fetchAssociative();

        if ($existing) {
            // Update content if changed
            if ($existing['content'] !== $content || $existing['updated_at'] !== $updatedAt) {
                $db->executeStatement(
                    'UPDATE cq_federation_feed_post SET content = ?, updated_at = ? WHERE id = ?',
                    [$content, $updatedAt, $remotePostId]
                );
            }
            return $db->executeQuery('SELECT * FROM cq_federation_feed_post WHERE id = ?', [$remotePostId])->fetchAssociative();
        }

        $db->executeStatement(
            'INSERT INTO cq_federation_feed_post (id, cq_feed_id, cq_contact_id, cq_contact_url, cq_contact_domain, cq_contact_username, post_url_slug, content, is_active, created_at, updated_at, last_visited_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)',
            [$remotePostId, $feedId, $cqContactId, $cqContactUrl, $cqContactDomain, $cqContactUsername, $postUrlSlug, $content, $createdAt, $updatedAt, $createdAt]
        );

        return $db->executeQuery('SELECT * FROM cq_federation_feed_post WHERE id = ?', [$remotePostId])->fetchAssociative();
    }

    /**
     * List cached posts for a subscribed feed (paginated)
     */
    public function listCachedPosts(string $feedId, int $page = 1, int $limit = 20): array
    {
        $db = $this->getUserDb();
        $offset = ($page - 1) * $limit;

        return $db->executeQuery(
            'SELECT * FROM cq_federation_feed_post
             WHERE cq_feed_id = ? AND is_active = 1
             ORDER BY created_at DESC LIMIT ? OFFSET ?',
            [$feedId, $limit, $offset]
        )->fetchAllAssociative();
    }

    /**
     * Get aggregated timeline from all active subscribed feeds (paginated)
     */
    public function getTimeline(int $page = 1, int $limit = 20): array
    {
        $db = $this->getUserDb();
        $offset = ($page - 1) * $limit;

        return $db->executeQuery(
            'SELECT p.*, f.title AS feed_title, f.feed_url_slug AS feed_slug
             FROM cq_federation_feed_post p
             JOIN cq_federation_feed f ON f.id = p.cq_feed_id
             WHERE p.is_active = 1 AND f.is_active = 1
             ORDER BY p.created_at DESC LIMIT ? OFFSET ?',
            [$limit, $offset]
        )->fetchAllAssociative();
    }

    /**
     * Count timeline posts
     */
    public function countTimeline(): int
    {
        $db = $this->getUserDb();
        return (int) $db->executeQuery(
            'SELECT COUNT(*)
             FROM cq_federation_feed_post p
             JOIN cq_federation_feed f ON f.id = p.cq_feed_id
             WHERE p.is_active = 1 AND f.is_active = 1'
        )->fetchOne();
    }

    // ========================================
    // Remote Feed Operations
    // ========================================

    /**
     * Fetch latest posts from a remote feed (incremental sync).
     * Returns the number of new/updated posts.
     */
    public function fetchRemotePosts(string $feedId, ?string $apiKey = null): int
    {
        $feed = $this->findById($feedId);
        if (!$feed) {
            throw new \RuntimeException('Feed not found');
        }

        $baseUrl = 'https://' . $feed['cq_contact_domain'] . '/' . $feed['cq_contact_username'];
        $url = $baseUrl . '/feed/' . $feed['feed_url_slug'];

        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'CitadelQuest HTTP Client',
        ];
        if ($apiKey) {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => $headers,
                'query' => [
                    'since' => $feed['last_visited_at'],
                    'limit' => 50,
                ],
                'timeout' => 15,
                'verify_peer' => false,
            ]);

            $data = $response->toArray(false);
            if (!($data['success'] ?? false)) {
                return 0;
            }

            $count = 0;
            foreach ($data['posts'] ?? [] as $post) {
                $author = $post['author'] ?? [];
                $this->cachePost(
                    $feedId,
                    $post['id'],
                    $author['cq_contact_id'] ?? $feed['cq_contact_id'],
                    $author['url'] ?? $feed['cq_contact_url'],
                    $author['domain'] ?? $feed['cq_contact_domain'],
                    $author['username'] ?? $feed['cq_contact_username'],
                    $post['post_url_slug'] ?? '',
                    $post['content'] ?? '',
                    $post['created_at'] ?? date('Y-m-d H:i:s'),
                    $post['updated_at'] ?? date('Y-m-d H:i:s')
                );
                $count++;
            }

            // Update last_visited_at
            $this->updateLastVisited($feedId);

            // Update parent feed's updated_at
            $db = $this->getUserDb();
            $db->executeStatement(
                'UPDATE cq_federation_feed SET updated_at = ? WHERE id = ?',
                [date('Y-m-d H:i:s'), $feedId]
            );

            return $count;

        } catch (\Exception $e) {
            $this->logger->error('CQFederationFeedService::fetchRemotePosts error', [
                'feed_id' => $feedId,
                'url' => $url ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Subscribe to all feeds from a remote Citadel.
     * Called on Friend Accept / Follow.
     */
    public function subscribeAllFeeds(
        string $cqContactId,
        string $cqContactUrl,
        string $cqContactDomain,
        string $cqContactUsername,
        ?string $apiKey = null
    ): int {
        $url = 'https://' . $cqContactDomain . '/' . $cqContactUsername . '/feeds';

        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'CitadelQuest HTTP Client',
        ];
        if ($apiKey) {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => $headers,
                'timeout' => 15,
                'verify_peer' => false,
            ]);

            $data = $response->toArray(false);
            if (!($data['success'] ?? false)) {
                return 0;
            }

            $count = 0;
            foreach ($data['feeds'] ?? [] as $feed) {
                $this->subscribeFeed(
                    $cqContactId,
                    $cqContactUrl,
                    $cqContactDomain,
                    $cqContactUsername,
                    $feed['feed_url_slug'] ?? '',
                    $feed['title'] ?? 'Untitled',
                    $feed['description'] ?? null
                );
                $count++;
            }

            return $count;

        } catch (\Exception $e) {
            $this->logger->error('CQFederationFeedService::subscribeAllFeeds error', [
                'contact_id' => $cqContactId,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Unsubscribe from all feeds of a specific contact.
     * Called on unfriend/unfollow.
     */
    public function unsubscribeAllFeeds(string $cqContactId): int
    {
        $feeds = $this->listFeedsByContact($cqContactId);
        $count = 0;
        foreach ($feeds as $feed) {
            if ($this->unsubscribeFeed($feed['id'])) {
                $count++;
            }
        }
        return $count;
    }
}
