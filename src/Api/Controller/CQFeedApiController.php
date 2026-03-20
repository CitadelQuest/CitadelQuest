<?php

namespace App\Api\Controller;

use App\Entity\User;
use App\Service\CQFeedService;
use App\Service\CQFederationFeedService;
use App\Service\CQFollowService;
use App\Service\CqContactService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Psr\Log\LoggerInterface;

/**
 * CQ Feed API Controller — Authenticated endpoints for managing own feeds and posts.
 * 
 */
#[Route('/api/feed')]
#[IsGranted('ROLE_USER')]
class CQFeedApiController extends AbstractController
{
    public function __construct(
        private readonly CQFeedService $feedService,
        private readonly CQFederationFeedService $federationFeedService,
        private readonly CQFollowService $followService,
        private readonly CqContactService $contactService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {}

    // ========================================
    // My Feeds CRUD
    // ========================================

    /**
     * List user's own feeds
     */
    #[Route('/my-feeds', name: 'api_feed_my_feeds_list', methods: ['GET'])]
    public function listMyFeeds(): JsonResponse
    {
        try {
            $feeds = $this->feedService->listFeeds();
            return $this->json(['success' => true, 'feeds' => $feeds]);
        } catch (\Exception $e) {
            $this->logger->error('CQFeedApiController::listMyFeeds error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create a new feed
     */
    #[Route('/my-feeds', name: 'api_feed_my_feeds_create', methods: ['POST'])]
    public function createFeed(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            if (!$data) {
                return $this->json(['success' => false, 'message' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
            }

            $title = $data['title'] ?? '';
            if (empty($title)) {
                return $this->json(['success' => false, 'message' => 'Title is required'], Response::HTTP_BAD_REQUEST);
            }

            $feedUrlSlug = $data['feed_url_slug'] ?? $this->feedService->generateSlug($title);
            $scope = (int) ($data['scope'] ?? CQFeedService::SCOPE_CQ_CONTACT);
            $description = $data['description'] ?? null;
            $imageProjectFileId = $data['image_project_file_id'] ?? null;

            $feed = $this->feedService->createFeed($title, $feedUrlSlug, $scope, $description, $imageProjectFileId);

            return $this->json(['success' => true, 'feed' => $feed]);
        } catch (\Exception $e) {
            $this->logger->error('CQFeedApiController::createFeed error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update a feed
     */
    #[Route('/my-feeds/{id}', name: 'api_feed_my_feeds_update', methods: ['PUT'])]
    public function updateFeed(Request $request, string $id): JsonResponse
    {
        try {
            $existing = $this->feedService->findFeedById($id);
            if (!$existing) {
                return $this->json(['success' => false, 'message' => 'Feed not found'], Response::HTTP_NOT_FOUND);
            }

            $data = json_decode($request->getContent(), true);
            if (!$data) {
                return $this->json(['success' => false, 'message' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
            }

            $feed = $this->feedService->updateFeed($id, $data);
            return $this->json(['success' => true, 'feed' => $feed]);
        } catch (\Exception $e) {
            $this->logger->error('CQFeedApiController::updateFeed error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete a feed and all its posts
     */
    #[Route('/my-feeds/{id}', name: 'api_feed_my_feeds_delete', methods: ['DELETE'])]
    public function deleteFeed(string $id): JsonResponse
    {
        try {
            $existing = $this->feedService->findFeedById($id);
            if (!$existing) {
                return $this->json(['success' => false, 'message' => 'Feed not found'], Response::HTTP_NOT_FOUND);
            }

            $this->feedService->deleteFeed($id);
            return $this->json(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->error('CQFeedApiController::deleteFeed error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // ========================================
    // My Posts CRUD
    // ========================================

    /**
     * List posts in own feed (paginated)
     */
    #[Route('/my-feeds/{id}/posts', name: 'api_feed_my_posts_list', methods: ['GET'])]
    public function listMyPosts(Request $request, string $id): JsonResponse
    {
        try {
            $feed = $this->feedService->findFeedById($id);
            if (!$feed) {
                return $this->json(['success' => false, 'message' => 'Feed not found'], Response::HTTP_NOT_FOUND);
            }

            $page = max(1, (int) $request->query->get('page', 1));
            $limit = min(50, max(1, (int) $request->query->get('limit', 20)));

            $posts = $this->feedService->listPosts($id, $page, $limit);
            $total = $this->feedService->countPosts($id);

            return $this->json([
                'success' => true,
                'posts' => $posts,
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('CQFeedApiController::listMyPosts error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Create a new post in own feed
     */
    #[Route('/my-feeds/{id}/posts', name: 'api_feed_my_posts_create', methods: ['POST'])]
    public function createPost(Request $request, string $id): JsonResponse
    {
        try {
            $feed = $this->feedService->findFeedById($id);
            if (!$feed) {
                return $this->json(['success' => false, 'message' => 'Feed not found'], Response::HTTP_NOT_FOUND);
            }

            $data = json_decode($request->getContent(), true);
            if (!$data) {
                return $this->json(['success' => false, 'message' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
            }

            $content = $data['content'] ?? '';
            if (empty(trim($content))) {
                return $this->json(['success' => false, 'message' => 'Content is required'], Response::HTTP_BAD_REQUEST);
            }

            $postUrlSlug = $data['post_url_slug'] ?? $this->feedService->generatePostSlug($id);

            $post = $this->feedService->createPost($id, $content, $postUrlSlug);

            return $this->json(['success' => true, 'post' => $post]);
        } catch (\Exception $e) {
            $this->logger->error('CQFeedApiController::createPost error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update a post
     */
    #[Route('/posts/{id}', name: 'api_feed_posts_update', methods: ['PUT'])]
    public function updatePost(Request $request, string $id): JsonResponse
    {
        try {
            $existing = $this->feedService->findPostById($id);
            if (!$existing) {
                return $this->json(['success' => false, 'message' => 'Post not found'], Response::HTTP_NOT_FOUND);
            }

            $data = json_decode($request->getContent(), true);
            if (!$data) {
                return $this->json(['success' => false, 'message' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
            }

            $post = $this->feedService->updatePost($id, $data);
            return $this->json(['success' => true, 'post' => $post]);
        } catch (\Exception $e) {
            $this->logger->error('CQFeedApiController::updatePost error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete a post
     */
    #[Route('/posts/{id}', name: 'api_feed_posts_delete', methods: ['DELETE'])]
    public function deletePost(string $id): JsonResponse
    {
        try {
            $existing = $this->feedService->findPostById($id);
            if (!$existing) {
                return $this->json(['success' => false, 'message' => 'Post not found'], Response::HTTP_NOT_FOUND);
            }

            $this->feedService->deletePost($id);
            return $this->json(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->error('CQFeedApiController::deletePost error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // ========================================
    // Subscribed Federation Feeds
    // ========================================

    /**
     * Sync feed subscriptions: discover and subscribe to feeds from all followed contacts.
     * Ensures users who created feeds after being followed are picked up.
     */
    #[Route('/sync-subscriptions', name: 'api_feed_sync_subscriptions', methods: ['POST'])]
    public function syncSubscriptions(): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $this->getUser();
            $this->followService->setUser($user);
            $follows = $this->followService->listFollows();

            $this->contactService->setUser($user);

            $newSubs = 0;
            foreach ($follows as $follow) {
                try {
                    // Look up contact API key for authenticated feed access
                    $contact = $this->contactService->findById($follow['cq_contact_id']);
                    $apiKey = $contact ? $contact->getCqContactApiKey() : null;

                    $count = $this->federationFeedService->subscribeAllFeeds(
                        $follow['cq_contact_id'],
                        $follow['cq_contact_url'],
                        $follow['cq_contact_domain'],
                        $follow['cq_contact_username'],
                        $apiKey
                    );
                    $newSubs += $count;
                } catch (\Exception $e) {
                    // Skip contacts that are unreachable
                }
            }

            return $this->json(['success' => true, 'new_subscriptions' => $newSubs]);
        } catch (\Exception $e) {
            $this->logger->error('CQFeedApiController::syncSubscriptions error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * List subscribed federation feeds
     */
    #[Route('/subscribed', name: 'api_feed_subscribed_list', methods: ['GET'])]
    public function listSubscribed(): JsonResponse
    {
        try {
            $feeds = $this->federationFeedService->listSubscribedFeeds();
            return $this->json(['success' => true, 'feeds' => $feeds]);
        } catch (\Exception $e) {
            $this->logger->error('CQFeedApiController::listSubscribed error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * List subscribed feeds for a specific contact
     */
    #[Route('/subscribed/by-contact/{contactId}', name: 'api_feed_subscribed_by_contact', methods: ['GET'])]
    public function listSubscribedByContact(string $contactId): JsonResponse
    {
        try {
            $feeds = $this->federationFeedService->listFeedsByContact($contactId);
            return $this->json(['success' => true, 'feeds' => $feeds]);
        } catch (\Exception $e) {
            $this->logger->error('CQFeedApiController::listSubscribedByContact error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Fetch latest posts from a subscribed federation feed (incremental sync)
     */
    #[Route('/subscribed/{id}/fetch', name: 'api_feed_subscribed_fetch', methods: ['POST'])]
    public function fetchSubscribed(string $id): JsonResponse
    {
        try {
            $feed = $this->federationFeedService->findById($id);
            if (!$feed) {
                return $this->json(['success' => false, 'message' => 'Feed not found'], Response::HTTP_NOT_FOUND);
            }

            // Look up contact API key for authenticated feed access (required for CQ_CONTACT scope)
            /** @var User $user */
            $user = $this->getUser();
            $this->contactService->setUser($user);
            $contact = $this->contactService->findById($feed['cq_contact_id']);
            $apiKey = $contact ? $contact->getCqContactApiKey() : null;

            $count = $this->federationFeedService->fetchRemotePosts($id, $apiKey);

            return $this->json([
                'success' => true,
                'new_posts' => $count,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('CQFeedApiController::fetchSubscribed error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Unsubscribe from a federation feed
     */
    #[Route('/subscribed/{id}', name: 'api_feed_subscribed_delete', methods: ['DELETE'])]
    public function unsubscribe(string $id): JsonResponse
    {
        try {
            $feed = $this->federationFeedService->findById($id);
            if (!$feed) {
                return $this->json(['success' => false, 'message' => 'Feed not found'], Response::HTTP_NOT_FOUND);
            }

            $this->federationFeedService->unsubscribeFeed($id);
            return $this->json(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->error('CQFeedApiController::unsubscribe error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Toggle active status of a subscribed feed
     */
    #[Route('/subscribed/{id}/toggle', name: 'api_feed_subscribed_toggle', methods: ['POST'])]
    public function toggleSubscribed(string $id): JsonResponse
    {
        try {
            $feed = $this->federationFeedService->toggleActive($id);
            if (!$feed) {
                return $this->json(['success' => false, 'message' => 'Feed not found'], Response::HTTP_NOT_FOUND);
            }

            return $this->json(['success' => true, 'feed' => $feed]);
        } catch (\Exception $e) {
            $this->logger->error('CQFeedApiController::toggleSubscribed error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get fresh stats for a timeline post from its source Citadel.
     * Returns 404 if the post was deleted on the source (also removes local cache).
     */
    #[Route('/timeline/{postId}/stats', name: 'api_feed_timeline_post_stats', methods: ['GET'])]
    public function timelinePostStats(string $postId): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $this->getUser();
            $this->contactService->setUser($user);

            $cachedPost = $this->federationFeedService->findCachedPostById($postId);
            if (!$cachedPost) {
                return $this->json(['success' => false, 'message' => 'Post not found'], Response::HTTP_NOT_FOUND);
            }

            // Get API key for authenticated feeds
            $contact = $this->contactService->findById($cachedPost['cq_contact_id']);
            $apiKey = $contact ? $contact->getCqContactApiKey() : null;

            $result = $this->federationFeedService->proxyStats($postId, $apiKey);

            if ($result === null) {
                // Post deleted on source — already removed from local cache
                return $this->json(['success' => false, 'deleted' => true, 'message' => 'Post deleted'], Response::HTTP_NOT_FOUND);
            }

            return $this->json([
                'success' => true,
                'stats' => $result['stats'],
            ]);

        } catch (\Exception $e) {
            $this->logger->error('CQFeedApiController::timelinePostStats error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * React to a timeline post (like/dislike).
     * Handles both local (own Citadel) and remote (federation) posts.
     */
    #[Route('/timeline/react', name: 'api_feed_timeline_react', methods: ['POST'])]
    public function timelineReact(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $postId = $data['post_id'] ?? null;
            $reaction = $data['reaction'] ?? null;

            if (!$postId || $reaction === null) {
                return $this->json(['success' => false, 'message' => 'Missing post_id or reaction'], Response::HTTP_BAD_REQUEST);
            }

            if (!in_array((int) $reaction, [CQFeedService::REACTION_LIKE, CQFeedService::REACTION_DISLIKE], true)) {
                return $this->json(['success' => false, 'message' => 'Invalid reaction value'], Response::HTTP_BAD_REQUEST);
            }

            /** @var User $user */
            $user = $this->getUser();
            $domain = $request->getHost();
            $userContactId = $user->getId()->toRfc4122();
            $userContactUrl = 'https://' . $domain . '/' . $user->getUsername();

            // Look up the cached post
            $cachedPost = $this->federationFeedService->findCachedPostById($postId);
            if (!$cachedPost) {
                return $this->json(['success' => false, 'message' => 'Post not found'], Response::HTTP_NOT_FOUND);
            }

            // Determine if this is a local post (same domain) or remote
            $isLocal = ($cachedPost['feed_domain'] === $domain);

            if ($isLocal) {
                // React directly on the local cq_user_feed_post
                $postOwner = $this->entityManager->getRepository(User::class)
                    ->findOneBy(['username' => $cachedPost['feed_username']]);
                $this->feedService->setUser($postOwner ?? $user);
                $localPost = $this->feedService->findPostByFeedSlugAndPostSlug(
                    $cachedPost['feed_slug'],
                    $cachedPost['post_url_slug']
                );

                if (!$localPost) {
                    return $this->json(['success' => false, 'message' => 'Local post not found'], Response::HTTP_NOT_FOUND);
                }

                $result = $this->feedService->reactToPost($localPost['id'], $userContactId, $userContactUrl, (int) $reaction);

                // Cache stats locally on the federation post too
                $this->feedService->setUser($user);
                $this->federationFeedService->updateCachedPostStats($postId, $result['stats'], $result['my_reaction']);

                return $this->json([
                    'success' => true,
                    'stats' => $result['stats'],
                    'my_reaction' => $result['my_reaction'],
                ]);
            } else {
                // Proxy to remote Citadel
                $this->contactService->setUser($user);
                $contact = $this->contactService->findById($cachedPost['cq_contact_id']);
                $apiKey = $contact ? $contact->getCqContactApiKey() : null;

                $result = $this->federationFeedService->proxyReaction(
                    $postId,
                    (int) $reaction,
                    $userContactId,
                    $userContactUrl,
                    $apiKey
                );

                return $this->json([
                    'success' => true,
                    'stats' => $result['stats'],
                    'my_reaction' => $result['my_reaction'],
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('CQFeedApiController::timelineReact error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // ========================================
    // Comments
    // ========================================

    /**
     * Resolve a post from federation cache or own posts table.
     * Returns ['type' => 'own'|'local'|'remote', 'post' => [...], 'cachedPost' => [...] | null]
     */
    private function resolvePostForComment(string $postId, User $user, string $domain): ?array
    {
        // 1. Try federation cache first
        $cachedPost = $this->federationFeedService->findCachedPostById($postId);
        if ($cachedPost) {
            $isLocal = ($cachedPost['feed_domain'] === $domain);
            if ($isLocal) {
                $postOwner = $this->entityManager->getRepository(User::class)
                    ->findOneBy(['username' => $cachedPost['feed_username']]);
                $this->feedService->setUser($postOwner ?? $user);
                $localPost = $this->feedService->findPostByFeedSlugAndPostSlug(
                    $cachedPost['feed_slug'], $cachedPost['post_url_slug']
                );
                return $localPost
                    ? ['type' => 'local', 'post' => $localPost, 'cachedPost' => $cachedPost, 'postOwner' => $postOwner ?? $user]
                    : null;
            }
            return ['type' => 'remote', 'post' => null, 'cachedPost' => $cachedPost, 'postOwner' => null];
        }

        // 2. Try own posts (not in federation cache)
        $ownPost = $this->feedService->findPostById($postId);
        if ($ownPost) {
            return ['type' => 'own', 'post' => $ownPost, 'cachedPost' => null, 'postOwner' => $user];
        }

        return null;
    }

    /**
     * Add a comment to a timeline post.
     * Handles own posts, local (other user on same Citadel), and remote (federation proxy) posts.
     */
    #[Route('/timeline/comment', name: 'api_feed_timeline_comment_add', methods: ['POST'])]
    public function timelineCommentAdd(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $postId = $data['post_id'] ?? null;
            $content = $data['content'] ?? '';
            $parentId = $data['parent_id'] ?? null;

            if (!$postId || empty(trim($content))) {
                return $this->json(['success' => false, 'message' => 'Missing post_id or content'], Response::HTTP_BAD_REQUEST);
            }

            /** @var User $user */
            $user = $this->getUser();
            $domain = $request->getHost();
            $userContactId = $user->getId()->toRfc4122();
            $userContactUrl = 'https://' . $domain . '/' . $user->getUsername();

            $resolved = $this->resolvePostForComment($postId, $user, $domain);
            if (!$resolved) {
                return $this->json(['success' => false, 'message' => 'Post not found'], Response::HTTP_NOT_FOUND);
            }

            if ($resolved['type'] === 'own' || $resolved['type'] === 'local') {
                $this->feedService->setUser($resolved['postOwner']);
                $result = $this->feedService->addComment($resolved['post']['id'], $userContactId, $userContactUrl, $content, $parentId);

                // Update cached stats on federation post if it exists in cache
                if ($resolved['cachedPost']) {
                    $this->feedService->setUser($user);
                    $existingStats = json_decode($resolved['cachedPost']['stats'] ?? '{}', true) ?: [];
                    $this->federationFeedService->updateCachedPostStats($postId, $result['stats'], $existingStats['my_reaction'] ?? null);
                }

                return $this->json([
                    'success' => true,
                    'comment' => $result['comment'],
                    'stats' => $result['stats'],
                ]);
            } else {
                $this->contactService->setUser($user);
                $contact = $this->contactService->findById($resolved['cachedPost']['cq_contact_id']);
                $apiKey = $contact ? $contact->getCqContactApiKey() : null;

                $result = $this->federationFeedService->proxyAddComment(
                    $postId, $content, $userContactId, $userContactUrl, $parentId, $apiKey
                );

                return $this->json($result);
            }

        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('CQFeedApiController::timelineCommentAdd error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * List comments for a timeline post (lazy-loaded from source).
     */
    #[Route('/timeline/{postId}/comments', name: 'api_feed_timeline_comments_list', methods: ['GET'])]
    public function timelineCommentsList(Request $request, string $postId): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $this->getUser();
            $domain = $request->getHost();

            $resolved = $this->resolvePostForComment($postId, $user, $domain);
            if (!$resolved) {
                return $this->json(['success' => false, 'message' => 'Post not found'], Response::HTTP_NOT_FOUND);
            }

            $page = max(1, (int) $request->query->get('page', 1));
            $limit = min(100, max(1, (int) $request->query->get('limit', 50)));

            if ($resolved['type'] === 'own' || $resolved['type'] === 'local') {
                $this->feedService->setUser($resolved['postOwner']);
                $result = $this->feedService->listComments($resolved['post']['id'], $page, $limit);
                return $this->json([
                    'success' => true,
                    'comments' => $result['comments'],
                    'total' => $result['total'],
                ]);
            } else {
                $this->contactService->setUser($user);
                $contact = $this->contactService->findById($resolved['cachedPost']['cq_contact_id']);
                $apiKey = $contact ? $contact->getCqContactApiKey() : null;

                $result = $this->federationFeedService->proxyListComments($postId, $page, $limit, $apiKey);
                return $this->json($result);
            }

        } catch (\Exception $e) {
            $this->logger->error('CQFeedApiController::timelineCommentsList error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update own comment on a timeline post.
     */
    #[Route('/timeline/comment/{commentId}', name: 'api_feed_timeline_comment_update', methods: ['PUT'])]
    public function timelineCommentUpdate(Request $request, string $commentId): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $postId = $data['post_id'] ?? null;
            $content = $data['content'] ?? '';

            if (!$postId || empty(trim($content))) {
                return $this->json(['success' => false, 'message' => 'Missing post_id or content'], Response::HTTP_BAD_REQUEST);
            }

            /** @var User $user */
            $user = $this->getUser();
            $domain = $request->getHost();
            $userContactId = $user->getId()->toRfc4122();

            $resolved = $this->resolvePostForComment($postId, $user, $domain);
            if (!$resolved) {
                return $this->json(['success' => false, 'message' => 'Post not found'], Response::HTTP_NOT_FOUND);
            }

            if ($resolved['type'] === 'own' || $resolved['type'] === 'local') {
                $this->feedService->setUser($resolved['postOwner']);
                $comment = $this->feedService->updateComment($commentId, $userContactId, $content);
                return $this->json(['success' => true, 'comment' => $comment]);
            } else {
                $this->contactService->setUser($user);
                $contact = $this->contactService->findById($resolved['cachedPost']['cq_contact_id']);
                $apiKey = $contact ? $contact->getCqContactApiKey() : null;

                $result = $this->federationFeedService->proxyUpdateComment($postId, $commentId, $content, $userContactId, $apiKey);
                return $this->json($result);
            }

        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('CQFeedApiController::timelineCommentUpdate error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete own comment on a timeline post.
     */
    #[Route('/timeline/comment/{commentId}', name: 'api_feed_timeline_comment_delete', methods: ['DELETE'])]
    public function timelineCommentDelete(Request $request, string $commentId): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $postId = $data['post_id'] ?? null;

            if (!$postId) {
                return $this->json(['success' => false, 'message' => 'Missing post_id'], Response::HTTP_BAD_REQUEST);
            }

            /** @var User $user */
            $user = $this->getUser();
            $domain = $request->getHost();
            $userContactId = $user->getId()->toRfc4122();

            $resolved = $this->resolvePostForComment($postId, $user, $domain);
            if (!$resolved) {
                return $this->json(['success' => false, 'message' => 'Post not found'], Response::HTTP_NOT_FOUND);
            }

            if ($resolved['type'] === 'own' || $resolved['type'] === 'local') {
                $this->feedService->setUser($resolved['postOwner']);
                $result = $this->feedService->deleteComment($commentId, $userContactId);

                // Update cached stats if in federation cache
                if ($resolved['cachedPost']) {
                    $existingStats = json_decode($resolved['cachedPost']['stats'] ?? '{}', true) ?: [];
                    $this->federationFeedService->updateCachedPostStats($postId, $result['stats'], $existingStats['my_reaction'] ?? null);
                }

                return $this->json(['success' => true, 'stats' => $result['stats']]);
            } else {
                $this->contactService->setUser($user);
                $contact = $this->contactService->findById($resolved['cachedPost']['cq_contact_id']);
                $apiKey = $contact ? $contact->getCqContactApiKey() : null;

                $result = $this->federationFeedService->proxyDeleteComment($postId, $commentId, $userContactId, $apiKey);
                return $this->json($result);
            }

        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('CQFeedApiController::timelineCommentDelete error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Toggle comment visibility (post owner moderation — only for own posts).
     */
    #[Route('/timeline/comment/{commentId}/toggle', name: 'api_feed_timeline_comment_toggle', methods: ['PATCH'])]
    public function timelineCommentToggle(Request $request, string $commentId): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $postId = $data['post_id'] ?? null;

            if (!$postId) {
                return $this->json(['success' => false, 'message' => 'Missing post_id'], Response::HTTP_BAD_REQUEST);
            }

            /** @var User $user */
            $user = $this->getUser();
            $domain = $request->getHost();

            $resolved = $this->resolvePostForComment($postId, $user, $domain);
            if (!$resolved) {
                return $this->json(['success' => false, 'message' => 'Post not found'], Response::HTTP_NOT_FOUND);
            }

            // Toggle visibility only allowed for own posts or local posts owned by this user
            if ($resolved['type'] === 'remote') {
                return $this->json(['success' => false, 'message' => 'Only the post owner can toggle comment visibility'], Response::HTTP_FORBIDDEN);
            }
            if ($resolved['type'] === 'local' && $resolved['cachedPost']['feed_username'] !== $user->getUsername()) {
                return $this->json(['success' => false, 'message' => 'Only the post owner can toggle comment visibility'], Response::HTTP_FORBIDDEN);
            }

            $this->feedService->setUser($user);
            $result = $this->feedService->toggleCommentVisibility($commentId);

            // Update cached stats if in federation cache
            if ($resolved['cachedPost']) {
                $existingStats = json_decode($resolved['cachedPost']['stats'] ?? '{}', true) ?: [];
                $this->federationFeedService->updateCachedPostStats($postId, $result['stats'], $existingStats['my_reaction'] ?? null);
            }

            return $this->json([
                'success' => true,
                'is_active' => $result['is_active'],
                'stats' => $result['stats'],
            ]);

        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('CQFeedApiController::timelineCommentToggle error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // ========================================
    // Timeline
    // ========================================

    /**
     * Aggregated timeline from all active subscribed feeds (paginated)
     */
    #[Route('/timeline', name: 'api_feed_timeline', methods: ['GET'])]
    public function timeline(Request $request): JsonResponse
    {
        try {
            $page = max(1, (int) $request->query->get('page', 1));
            $limit = min(50, max(1, (int) $request->query->get('limit', 20)));

            $posts = $this->federationFeedService->getTimeline($page, $limit);
            $total = $this->federationFeedService->countTimeline();

            return $this->json([
                'success' => true,
                'posts' => $posts,
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('CQFeedApiController::timeline error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
