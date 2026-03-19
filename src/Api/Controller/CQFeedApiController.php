<?php

namespace App\Api\Controller;

use App\Entity\User;
use App\Service\CQFeedService;
use App\Service\CQFederationFeedService;
use App\Service\CQFollowService;
use App\Service\CqContactService;
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

            $count = $this->federationFeedService->fetchRemotePosts($id);

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
