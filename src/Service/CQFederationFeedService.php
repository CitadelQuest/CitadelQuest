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
        ?string $description = null,
        ?string $apiKey = null,
        int $scope = 0
    ): array {
        $db = $this->getUserDb();

        // Check if already subscribed
        $existing = $this->findByContactAndSlug($cqContactId, $feedUrlSlug);
        if ($existing) {
            // Update scope if it changed
            if ((int) ($existing['scope'] ?? 0) !== $scope) {
                $db->executeStatement('UPDATE cq_federation_feed SET scope = ? WHERE id = ?', [$scope, $existing['id']]);
            }
            return $this->findById($existing['id']);
        }

        $id = Uuid::v4()->toRfc4122();
        $now = date('Y-m-d H:i:s');
        // Set last_visited_at to epoch so initial fetch grabs existing posts
        $epoch = '2000-01-01 00:00:00';

        $db->executeStatement(
            'INSERT INTO cq_federation_feed (id, cq_contact_id, cq_contact_url, cq_contact_domain, cq_contact_username, feed_url_slug, title, description, scope, is_active, created_at, updated_at, last_visited_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)',
            [$id, $cqContactId, $cqContactUrl, $cqContactDomain, $cqContactUsername, $feedUrlSlug, $title, $description, $scope, $now, $now, $epoch]
        );

        // Fetch existing posts (last 100) immediately after subscribing
        try {
            $this->fetchRemotePosts($id, $apiKey, 100);
        } catch (\Exception $e) {
            $this->logger->warning('CQFederationFeedService::subscribeFeed initial fetch failed', [
                'feed_id' => $id,
                'error' => $e->getMessage(),
            ]);
        }

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
        string $updatedAt,
        ?string $attachmentsJson = null
    ): array {
        $db = $this->getUserDb();

        // Check if post already cached (by remote ID)
        $existing = $db->executeQuery(
            'SELECT * FROM cq_federation_feed_post WHERE id = ?',
            [$remotePostId]
        )->fetchAssociative();

        if ($existing) {
            // Update content/attachments if changed
            if ($existing['content'] !== $content || $existing['updated_at'] !== $updatedAt || ($attachmentsJson && $existing['attachments_json'] !== $attachmentsJson)) {
                $db->executeStatement(
                    'UPDATE cq_federation_feed_post SET content = ?, attachments_json = ?, updated_at = ? WHERE id = ?',
                    [$content, $attachmentsJson, $updatedAt, $remotePostId]
                );
            }
            return $db->executeQuery('SELECT * FROM cq_federation_feed_post WHERE id = ?', [$remotePostId])->fetchAssociative();
        }

        $db->executeStatement(
            'INSERT INTO cq_federation_feed_post (id, cq_feed_id, cq_contact_id, cq_contact_url, cq_contact_domain, cq_contact_username, post_url_slug, content, attachments_json, is_active, created_at, updated_at, last_visited_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)',
            [$remotePostId, $feedId, $cqContactId, $cqContactUrl, $cqContactDomain, $cqContactUsername, $postUrlSlug, $content, $attachmentsJson, $createdAt, $updatedAt, $createdAt]
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
            'SELECT p.*, f.title AS feed_title, f.feed_url_slug AS feed_slug, f.scope AS feed_scope
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
    public function fetchRemotePosts(string $feedId, ?string $apiKey = null, int $limit = 50): int
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
                    'limit' => $limit,
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
                $attJson = !empty($post['attachments']) ? json_encode($post['attachments']) : null;
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
                    $post['updated_at'] ?? date('Y-m-d H:i:s'),
                    $attJson
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
                    $feed['description'] ?? null,
                    $apiKey,
                    (int) ($feed['scope'] ?? 0)
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

    // ========================================
    // Parallel Federation Operations
    // ========================================

    /**
     * Fetch latest posts from multiple feeds in parallel.
     * Fires all HTTP requests concurrently, then processes responses.
     * Returns total number of new/updated posts.
     *
     * @param array<array{feed_id: string, api_key: ?string}> $feedJobs
     */
    public function fetchRemotePostsParallel(array $feedJobs, int $limit = 50): int
    {
        if (empty($feedJobs)) {
            return 0;
        }

        // 1. Collect feed rows and fire all requests (non-blocking)
        $pending = []; // ['feed' => row, 'response' => ResponseInterface, 'api_key' => ?string]
        foreach ($feedJobs as $job) {
            $feed = $this->findById($job['feed_id']);
            if (!$feed) {
                continue;
            }

            $baseUrl = 'https://' . $feed['cq_contact_domain'] . '/' . $feed['cq_contact_username'];
            $url = $baseUrl . '/feed/' . $feed['feed_url_slug'];

            $headers = [
                'Accept' => 'application/json',
                'User-Agent' => 'CitadelQuest HTTP Client',
            ];
            if (!empty($job['api_key'])) {
                $headers['Authorization'] = 'Bearer ' . $job['api_key'];
            }

            // Symfony HttpClient fires the request immediately; response is lazy
            $response = $this->httpClient->request('GET', $url, [
                'headers' => $headers,
                'query' => [
                    'since' => $feed['last_visited_at'],
                    'limit' => $limit,
                ],
                'timeout' => 15,
                'verify_peer' => false,
            ]);

            $pending[] = [
                'feed' => $feed,
                'response' => $response,
            ];
        }

        // 2. Process all responses (network I/O happens here, concurrently)
        $totalCount = 0;
        $db = $this->getUserDb();
        $now = date('Y-m-d H:i:s');

        foreach ($pending as $item) {
            $feed = $item['feed'];
            $feedId = $feed['id'];

            try {
                $statusCode = $item['response']->getStatusCode(false);
                if ($statusCode === 404) {
                    // Feed deleted on source — unsubscribe and remove cached posts
                    $this->logger->info('CQFederationFeedService::fetchRemotePostsParallel feed 404, unsubscribing', [
                        'feed_id' => $feedId,
                    ]);
                    $this->unsubscribeFeed($feedId);
                    continue;
                }

                $data = $item['response']->toArray(false);
                if (!($data['success'] ?? false)) {
                    continue;
                }

                $count = 0;
                foreach ($data['posts'] ?? [] as $post) {
                    $author = $post['author'] ?? [];
                    $attJson = !empty($post['attachments']) ? json_encode($post['attachments']) : null;
                    $this->cachePost(
                        $feedId,
                        $post['id'],
                        $author['cq_contact_id'] ?? $feed['cq_contact_id'],
                        $author['url'] ?? $feed['cq_contact_url'],
                        $author['domain'] ?? $feed['cq_contact_domain'],
                        $author['username'] ?? $feed['cq_contact_username'],
                        $post['post_url_slug'] ?? '',
                        $post['content'] ?? '',
                        $post['created_at'] ?? $now,
                        $post['updated_at'] ?? $now,
                        $attJson
                    );
                    $count++;
                }

                // Update timestamps
                $this->updateLastVisited($feedId);
                $db->executeStatement(
                    'UPDATE cq_federation_feed SET updated_at = ? WHERE id = ?',
                    [$now, $feedId]
                );

                $totalCount += $count;

            } catch (\Exception $e) {
                $this->logger->error('CQFederationFeedService::fetchRemotePostsParallel error', [
                    'feed_id' => $feedId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $totalCount;
    }

    /**
     * Discover and subscribe to feeds from multiple contacts in parallel.
     * Fires all /feeds discovery requests concurrently, then processes results.
     * Returns total number of new subscriptions.
     *
     * @param array<array{cq_contact_id: string, cq_contact_url: string, cq_contact_domain: string, cq_contact_username: string, api_key: ?string}> $contacts
     */
    public function discoverFeedsParallel(array $contacts): int
    {
        if (empty($contacts)) {
            return 0;
        }

        // 1. Fire all discovery requests (non-blocking)
        $pending = [];
        foreach ($contacts as $contact) {
            $url = 'https://' . $contact['cq_contact_domain'] . '/' . $contact['cq_contact_username'] . '/feeds';

            $headers = [
                'Accept' => 'application/json',
                'User-Agent' => 'CitadelQuest HTTP Client',
            ];
            if (!empty($contact['api_key'])) {
                $headers['Authorization'] = 'Bearer ' . $contact['api_key'];
            }

            $response = $this->httpClient->request('GET', $url, [
                'headers' => $headers,
                'timeout' => 15,
                'verify_peer' => false,
            ]);

            $pending[] = [
                'contact' => $contact,
                'response' => $response,
            ];
        }

        // 2. Process all responses concurrently
        $totalCount = 0;

        foreach ($pending as $item) {
            $c = $item['contact'];

            try {
                $statusCode = $item['response']->getStatusCode(false);
                if ($statusCode === 404) {
                    // Contact's /feeds endpoint gone — unsubscribe all feeds from this contact
                    $this->logger->info('CQFederationFeedService::discoverFeedsParallel contact feeds 404, unsubscribing all', [
                        'contact_id' => $c['cq_contact_id'],
                    ]);
                    foreach ($this->listFeedsByContact($c['cq_contact_id']) as $existingFeed) {
                        $this->unsubscribeFeed($existingFeed['id']);
                    }
                    continue;
                }

                $data = $item['response']->toArray(false);
                if (!($data['success'] ?? false)) {
                    continue;
                }

                foreach ($data['feeds'] ?? [] as $feed) {
                    $this->subscribeFeed(
                        $c['cq_contact_id'],
                        $c['cq_contact_url'],
                        $c['cq_contact_domain'],
                        $c['cq_contact_username'],
                        $feed['feed_url_slug'] ?? '',
                        $feed['title'] ?? 'Untitled',
                        $feed['description'] ?? null,
                        $c['api_key'] ?? null,
                        (int) ($feed['scope'] ?? 0)
                    );
                    $totalCount++;
                }

            } catch (\Exception $e) {
                $this->logger->error('CQFederationFeedService::discoverFeedsParallel error', [
                    'contact_id' => $c['cq_contact_id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $totalCount;
    }

    // ========================================
    // Reactions
    // ========================================

    /**
     * Find a cached federation post by ID
     */
    public function findCachedPostById(string $id): ?array
    {
        $db = $this->getUserDb();
        $row = $db->executeQuery(
            'SELECT p.*, f.feed_url_slug AS feed_slug, f.cq_contact_domain AS feed_domain, f.cq_contact_username AS feed_username
             FROM cq_federation_feed_post p
             JOIN cq_federation_feed f ON f.id = p.cq_feed_id
             WHERE p.id = ?',
            [$id]
        )->fetchAssociative();
        return $row ?: null;
    }

    /**
     * Update cached stats (and my_reaction) on a federation feed post.
     */
    public function updateCachedPostStats(string $postId, array $stats, ?int $myReaction): void
    {
        $db = $this->getUserDb();
        $statsWithReaction = $stats;
        $statsWithReaction['my_reaction'] = $myReaction;
        $db->executeStatement(
            'UPDATE cq_federation_feed_post SET stats = ? WHERE id = ?',
            [json_encode($statsWithReaction), $postId]
        );
    }

    /**
     * Fetch fresh stats for a cached post from its source Citadel.
     * Returns ['stats' => [...]] or null if post was deleted (404).
     * On 404: removes cached post from local DB.
     */
    public function proxyStats(string $cachedPostId, ?string $apiKey = null): ?array
    {
        $post = $this->findCachedPostById($cachedPostId);
        if (!$post) {
            return null;
        }

        $url = 'https://' . $post['feed_domain'] . '/' . $post['feed_username']
            . '/feed/' . $post['feed_slug'] . '/post/' . $post['post_url_slug'] . '/stats';

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
                'timeout' => 10,
                'verify_peer' => false,
            ]);

            $statusCode = $response->getStatusCode(false);

            if ($statusCode === 404) {
                // Post deleted on source — remove from local cache
                $this->deleteCachedPost($cachedPostId);
                return null;
            }

            $data = $response->toArray(false);
            if (!($data['success'] ?? false)) {
                return ['stats' => $this->_parseCachedStats($post)];
            }

            $stats = $data['stats'] ?? ['likes' => 0, 'dislikes' => 0, 'comments' => 0];

            // Preserve local my_reaction when updating stats
            $existingStats = $this->_parseCachedStats($post);
            $myReaction = $existingStats['my_reaction'] ?? null;
            $this->updateCachedPostStats($cachedPostId, $stats, $myReaction);

            return ['stats' => $stats];

        } catch (\Exception $e) {
            $this->logger->error('CQFederationFeedService::proxyStats error', [
                'post_id' => $cachedPostId,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            // Return cached stats on error
            return ['stats' => $this->_parseCachedStats($post)];
        }
    }

    /**
     * Delete a cached federation post.
     */
    public function deleteCachedPost(string $postId): void
    {
        $db = $this->getUserDb();
        $db->executeStatement('DELETE FROM cq_federation_feed_post WHERE id = ?', [$postId]);
    }

    /**
     * Parse stats from a cached post row.
     */
    private function _parseCachedStats(array $post): array
    {
        $stats = json_decode($post['stats'] ?? '{}', true) ?: [];
        return [
            'likes' => $stats['likes'] ?? 0,
            'dislikes' => $stats['dislikes'] ?? 0,
            'comments' => $stats['comments'] ?? 0,
            'my_reaction' => $stats['my_reaction'] ?? null,
        ];
    }

    // ========================================
    // Comment proxies
    // ========================================

    /**
     * Build base URL for a cached post's federation endpoint.
     */
    private function _buildPostBaseUrl(array $post): string
    {
        return 'https://' . $post['feed_domain'] . '/' . $post['feed_username']
            . '/feed/' . $post['feed_slug'] . '/post/' . $post['post_url_slug'];
    }

    /**
     * Build common headers for federation requests.
     */
    private function _buildFederationHeaders(?string $apiKey = null, bool $json = true): array
    {
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'CitadelQuest HTTP Client',
        ];
        if ($json) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($apiKey) {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }
        return $headers;
    }

    /**
     * Proxy add comment to remote Citadel.
     */
    public function proxyAddComment(string $cachedPostId, string $content, string $userContactId, string $userContactUrl, ?string $parentId = null, ?string $apiKey = null): array
    {
        $post = $this->findCachedPostById($cachedPostId);
        if (!$post) {
            throw new \RuntimeException('Cached post not found');
        }

        $url = $this->_buildPostBaseUrl($post) . '/comment';

        $response = $this->httpClient->request('POST', $url, [
            'headers' => $this->_buildFederationHeaders($apiKey),
            'json' => [
                'content' => $content,
                'cq_contact_id' => $userContactId,
                'cq_contact_url' => $userContactUrl,
                'parent_id' => $parentId,
            ],
            'timeout' => 15,
            'verify_peer' => false,
        ]);

        $data = $response->toArray(false);
        if (!($data['success'] ?? false)) {
            throw new \RuntimeException('Remote comment failed: ' . ($data['message'] ?? 'Unknown error'));
        }

        // Update cached stats
        if (isset($data['stats'])) {
            $existingStats = $this->_parseCachedStats($post);
            $this->updateCachedPostStats($cachedPostId, $data['stats'], $existingStats['my_reaction'] ?? null);
        }

        return $data;
    }

    /**
     * Proxy list comments from remote Citadel.
     */
    public function proxyListComments(string $cachedPostId, int $page = 1, int $limit = 50, ?string $apiKey = null): array
    {
        $post = $this->findCachedPostById($cachedPostId);
        if (!$post) {
            throw new \RuntimeException('Cached post not found');
        }

        $url = $this->_buildPostBaseUrl($post) . '/comments?page=' . $page . '&limit=' . $limit;

        $response = $this->httpClient->request('GET', $url, [
            'headers' => $this->_buildFederationHeaders($apiKey, false),
            'timeout' => 15,
            'verify_peer' => false,
        ]);

        $data = $response->toArray(false);
        if (!($data['success'] ?? false)) {
            throw new \RuntimeException('Remote comments fetch failed: ' . ($data['message'] ?? 'Unknown error'));
        }

        return $data;
    }

    /**
     * Proxy update comment on remote Citadel.
     */
    public function proxyUpdateComment(string $cachedPostId, string $commentId, string $content, string $userContactId, ?string $apiKey = null): array
    {
        $post = $this->findCachedPostById($cachedPostId);
        if (!$post) {
            throw new \RuntimeException('Cached post not found');
        }

        $url = $this->_buildPostBaseUrl($post) . '/comment/' . $commentId;

        $response = $this->httpClient->request('PUT', $url, [
            'headers' => $this->_buildFederationHeaders($apiKey),
            'json' => [
                'content' => $content,
                'cq_contact_id' => $userContactId,
            ],
            'timeout' => 15,
            'verify_peer' => false,
        ]);

        $data = $response->toArray(false);
        if (!($data['success'] ?? false)) {
            throw new \RuntimeException('Remote comment update failed: ' . ($data['message'] ?? 'Unknown error'));
        }

        return $data;
    }

    /**
     * Proxy delete comment on remote Citadel.
     */
    public function proxyDeleteComment(string $cachedPostId, string $commentId, string $userContactId, ?string $apiKey = null): array
    {
        $post = $this->findCachedPostById($cachedPostId);
        if (!$post) {
            throw new \RuntimeException('Cached post not found');
        }

        $url = $this->_buildPostBaseUrl($post) . '/comment/' . $commentId;

        $response = $this->httpClient->request('DELETE', $url, [
            'headers' => $this->_buildFederationHeaders($apiKey),
            'json' => [
                'cq_contact_id' => $userContactId,
            ],
            'timeout' => 15,
            'verify_peer' => false,
        ]);

        $data = $response->toArray(false);
        if (!($data['success'] ?? false)) {
            throw new \RuntimeException('Remote comment delete failed: ' . ($data['message'] ?? 'Unknown error'));
        }

        // Update cached stats
        if (isset($data['stats'])) {
            $existingStats = $this->_parseCachedStats($post);
            $this->updateCachedPostStats($cachedPostId, $data['stats'], $existingStats['my_reaction'] ?? null);
        }

        return $data;
    }

    // ========================================
    // Reaction proxies
    // ========================================

    /**
     * Proxy a reaction to a remote Citadel and cache the result locally.
     * Returns ['stats' => [...], 'my_reaction' => int|null]
     */
    public function proxyReaction(
        string $cachedPostId,
        int $reaction,
        string $userContactId,
        string $userContactUrl,
        ?string $apiKey = null
    ): array {
        $post = $this->findCachedPostById($cachedPostId);
        if (!$post) {
            throw new \RuntimeException('Cached post not found');
        }

        $url = 'https://' . $post['feed_domain'] . '/' . $post['feed_username']
            . '/feed/' . $post['feed_slug'] . '/post/' . $post['post_url_slug'] . '/react';

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'CitadelQuest HTTP Client',
        ];
        if ($apiKey) {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        $response = $this->httpClient->request('POST', $url, [
            'headers' => $headers,
            'json' => [
                'reaction' => $reaction,
                'cq_contact_id' => $userContactId,
                'cq_contact_url' => $userContactUrl,
            ],
            'timeout' => 15,
            'verify_peer' => false,
        ]);

        $data = $response->toArray(false);
        if (!($data['success'] ?? false)) {
            throw new \RuntimeException('Remote reaction failed: ' . ($data['message'] ?? 'Unknown error'));
        }

        $stats = $data['stats'] ?? ['likes' => 0, 'dislikes' => 0, 'comments' => 0];
        $myReaction = $data['my_reaction'] ?? null;

        // Cache stats locally
        $this->updateCachedPostStats($cachedPostId, $stats, $myReaction);

        return ['stats' => $stats, 'my_reaction' => $myReaction];
    }
}
