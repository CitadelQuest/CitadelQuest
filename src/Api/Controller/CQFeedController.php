<?php

namespace App\Api\Controller;

use App\CitadelVersion;
use App\Entity\User;
use App\Service\CQFeedService;
use App\Service\CQShareService;
use App\Service\CqContactService;
use App\Service\SettingsService;
use App\Service\UserDatabaseManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Psr\Log\LoggerInterface;

/**
 * CQ Feed Controller — Federation (public) endpoints for CQ Feed.
 * 
 * Serves feed listings and posts to remote Citadels and public visitors.
 * Authentication via CQ Contact API key (Bearer token) unlocks CQ_CONTACT scope.
 * 
 */
class CQFeedController extends AbstractController
{
    public function __construct(
        private readonly CQFeedService $feedService,
        private readonly CqContactService $cqContactService,
        private readonly SettingsService $settingsService,
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly ParameterBagInterface $params,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * List active feeds for a user, filtered by scope based on authentication.
     * 
     * GET /{username}/feeds
     */
    #[Route('/{username}/feeds', name: 'cq_feed_federation_list', methods: ['GET'], priority: -10)]
    public function listFeeds(Request $request, string $username): JsonResponse
    {
        try {
            $user = $this->resolveUser($username);
            if (!$user) {
                return $this->json(['success' => false, 'message' => 'Not found'], Response::HTTP_NOT_FOUND);
            }

            $this->feedService->setUser($user);
            $this->cqContactService->setUser($user);

            // Determine allowed scopes based on auth
            $isAuthenticated = $this->checkFederationAuth($request);
            $allowedScopes = $isAuthenticated
                ? [CQShareService::SCOPE_PUBLIC, CQShareService::SCOPE_CQ_CONTACT]
                : [CQShareService::SCOPE_PUBLIC];

            $feeds = $this->feedService->listActiveFeedsByScope($allowedScopes);

            // Strip internal fields, add metadata
            $domain = $request->getHost();
            $result = array_map(function ($feed) use ($username, $domain) {
                return [
                    'id' => $feed['id'],
                    'title' => $feed['title'],
                    'description' => $feed['description'],
                    'feed_url_slug' => $feed['feed_url_slug'],
                    'scope' => (int) $feed['scope'],
                    'has_image' => !empty($feed['image_project_file_id']),
                    'image_url' => !empty($feed['image_project_file_id'])
                        ? 'https://' . $domain . '/' . $username . '/feed/' . $feed['feed_url_slug'] . '/image'
                        : null,
                    'created_at' => $feed['created_at'],
                    'updated_at' => $feed['updated_at'],
                ];
            }, $feeds);

            return $this->json([
                'success' => true,
                'feeds' => $result,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('CQFeedController::listFeeds error', [
                'error' => $e->getMessage(),
                'username' => $username,
            ]);
            return $this->json(['success' => false, 'message' => 'Internal error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get posts for a specific feed (paginated).
     * 
     * GET /{username}/feed/{feedUrlSlug}?page=1&limit=20&since=...
     */
    #[Route('/{username}/feed/{feedUrlSlug}', name: 'cq_feed_federation_posts', methods: ['GET'], priority: -10)]
    public function feedPosts(Request $request, string $username, string $feedUrlSlug): JsonResponse
    {
        try {
            $user = $this->resolveUser($username);
            if (!$user) {
                return $this->json(['success' => false, 'message' => 'Not found'], Response::HTTP_NOT_FOUND);
            }

            $this->feedService->setUser($user);
            $this->cqContactService->setUser($user);

            $feed = $this->feedService->findFeedBySlug($feedUrlSlug);
            if (!$feed) {
                return $this->json(['success' => false, 'message' => 'Feed not found'], Response::HTTP_NOT_FOUND);
            }

            // Check scope access
            $isAuthenticated = $this->checkFederationAuth($request);
            $scope = (int) $feed['scope'];
            if ($scope === CQShareService::SCOPE_CQ_CONTACT && !$isAuthenticated) {
                return $this->json(['success' => false, 'message' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
            }

            // Pagination params
            $page = max(1, (int) $request->query->get('page', 1));
            $limit = min(50, max(1, (int) $request->query->get('limit', 20)));
            $since = $request->query->get('since');

            $posts = $this->feedService->listPosts($feed['id'], $page, $limit, $since);
            $total = $this->feedService->countPosts($feed['id']);

            // Build author info
            $domain = $request->getHost();
            $this->settingsService->setUser($user);
            $photoFileId = $this->settingsService->getSettingValue('profile.photo_project_file_id');

            $author = [
                'cq_contact_id' => $user->getId()->toRfc4122(),
                'username' => $username,
                'domain' => $domain,
                'url' => 'https://' . $domain . '/' . $username,
                'photo_url' => ($photoFileId) ? ('https://' . $domain . '/' . $username . '/photo') : null,
            ];

            $result = array_map(function ($post) use ($author) {
                $stats = json_decode($post['stats'] ?? '{}', true) ?: ['likes' => 0, 'dislikes' => 0, 'comments' => 0];
                unset($stats['my_reaction']);
                return [
                    'id' => $post['id'],
                    'post_url_slug' => $post['post_url_slug'],
                    'content' => $post['content'],
                    'stats' => $stats,
                    'author' => $author,
                    'created_at' => $post['created_at'],
                    'updated_at' => $post['updated_at'],
                ];
            }, $posts);

            return $this->json([
                'success' => true,
                'feed' => [
                    'id' => $feed['id'],
                    'title' => $feed['title'],
                    'description' => $feed['description'],
                    'feed_url_slug' => $feed['feed_url_slug'],
                ],
                'posts' => $result,
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('CQFeedController::feedPosts error', [
                'error' => $e->getMessage(),
                'username' => $username,
                'feedUrlSlug' => $feedUrlSlug,
            ]);
            return $this->json(['success' => false, 'message' => 'Internal error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Lightweight last-updated timestamp for a specific feed.
     * 
     * GET /{username}/feed/{feedUrlSlug}/last-updated
     */
    #[Route('/{username}/feed/{feedUrlSlug}/last-updated', name: 'cq_feed_federation_last_updated', methods: ['GET'], priority: -10)]
    public function feedLastUpdated(Request $request, string $username, string $feedUrlSlug): JsonResponse
    {
        try {
            $user = $this->resolveUser($username);
            if (!$user) {
                return $this->json(['success' => false, 'message' => 'Not found'], Response::HTTP_NOT_FOUND);
            }

            $this->feedService->setUser($user);
            $this->cqContactService->setUser($user);

            $feed = $this->feedService->findFeedBySlug($feedUrlSlug);
            if (!$feed) {
                return $this->json(['success' => false, 'message' => 'Feed not found'], Response::HTTP_NOT_FOUND);
            }

            // Check scope access
            $isAuthenticated = $this->checkFederationAuth($request);
            $scope = (int) $feed['scope'];
            if ($scope === CQShareService::SCOPE_CQ_CONTACT && !$isAuthenticated) {
                return $this->json(['success' => false, 'message' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
            }

            $lastUpdated = $this->feedService->getLastPostUpdatedAt($feed['id']);

            return $this->json([
                'success' => true,
                'last_updated_at' => $lastUpdated ?? $feed['updated_at'],
            ]);

        } catch (\Exception $e) {
            $this->logger->error('CQFeedController::feedLastUpdated error', [
                'error' => $e->getMessage(),
                'username' => $username,
                'feedUrlSlug' => $feedUrlSlug,
            ]);
            return $this->json(['success' => false, 'message' => 'Internal error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Serve feed cover image from File Browser.
     * 
     * GET /{username}/feed/{feedUrlSlug}/image
     */
    #[Route('/{username}/feed/{feedUrlSlug}/image', name: 'cq_feed_federation_image', methods: ['GET'], priority: -10)]
    public function feedImage(Request $request, string $username, string $feedUrlSlug): Response
    {
        try {
            $user = $this->resolveUser($username);
            if (!$user) {
                return $this->json(['success' => false, 'message' => 'Not found'], Response::HTTP_NOT_FOUND);
            }

            $this->feedService->setUser($user);

            $feed = $this->feedService->findFeedBySlug($feedUrlSlug);
            if (!$feed || empty($feed['image_project_file_id'])) {
                return $this->json(['success' => false, 'message' => 'No image'], Response::HTTP_NOT_FOUND);
            }

            // Look up the project_file record
            $userDb = $this->userDatabaseManager->getDatabaseConnection($user);
            $file = $userDb->executeQuery(
                'SELECT * FROM project_file WHERE id = ?',
                [$feed['image_project_file_id']]
            )->fetchAssociative();

            if (!$file) {
                return $this->json(['success' => false, 'message' => 'File not found'], Response::HTTP_NOT_FOUND);
            }

            // Construct file path
            $projectDir = $this->params->get('kernel.project_dir');
            $basePath = $projectDir . '/var/user_data/' . $user->getId() . '/p/' . $file['project_id'];
            $relativePath = ltrim($file['path'] ?? '', '/');
            $filePath = $relativePath
                ? $basePath . '/' . $relativePath . '/' . $file['name']
                : $basePath . '/' . $file['name'];

            if (!file_exists($filePath)) {
                return $this->json(['success' => false, 'message' => 'Image file not found on disk'], Response::HTTP_NOT_FOUND);
            }

            $response = new BinaryFileResponse($filePath);
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $file['name']);
            $response->headers->set('Content-Type', $file['mime_type'] ?? 'image/png');

            return $response;

        } catch (\Exception $e) {
            $this->logger->error('CQFeedController::feedImage error', [
                'error' => $e->getMessage(),
                'username' => $username,
                'feedUrlSlug' => $feedUrlSlug,
            ]);
            return $this->json(['success' => false, 'message' => 'Internal error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * React to a specific post in a feed (federation endpoint).
     * 
     * POST /{username}/feed/{feedUrlSlug}/post/{postSlug}/react
     * Body: {"reaction": 0, "cq_contact_id": "...", "cq_contact_url": "..."}
     */
    #[Route('/{username}/feed/{feedUrlSlug}/post/{postSlug}/react', name: 'cq_feed_federation_react', methods: ['POST'], priority: -10)]
    public function feedPostReact(Request $request, string $username, string $feedUrlSlug, string $postSlug): JsonResponse
    {
        try {
            $user = $this->resolveUser($username);
            if (!$user) {
                return $this->json(['success' => false, 'message' => 'Not found'], Response::HTTP_NOT_FOUND);
            }

            $this->feedService->setUser($user);
            $this->cqContactService->setUser($user);

            // Find feed and check scope access
            $feed = $this->feedService->findFeedBySlug($feedUrlSlug);
            if (!$feed) {
                return $this->json(['success' => false, 'message' => 'Feed not found'], Response::HTTP_NOT_FOUND);
            }

            $isAuthenticated = $this->checkFederationAuth($request);
            $scope = (int) $feed['scope'];
            if ($scope === CQShareService::SCOPE_CQ_CONTACT && !$isAuthenticated) {
                return $this->json(['success' => false, 'message' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
            }

            // Find the post
            $post = $this->feedService->findPostByFeedSlugAndPostSlug($feedUrlSlug, $postSlug);
            if (!$post) {
                return $this->json(['success' => false, 'message' => 'Post not found'], Response::HTTP_NOT_FOUND);
            }

            // Parse body
            $data = json_decode($request->getContent(), true);
            $reaction = $data['reaction'] ?? null;
            $cqContactId = $data['cq_contact_id'] ?? null;
            $cqContactUrl = $data['cq_contact_url'] ?? null;

            if ($reaction === null || !$cqContactId || !$cqContactUrl) {
                return $this->json(['success' => false, 'message' => 'Missing fields: reaction, cq_contact_id, cq_contact_url'], Response::HTTP_BAD_REQUEST);
            }

            if (!in_array((int) $reaction, [CQFeedService::REACTION_LIKE, CQFeedService::REACTION_DISLIKE], true)) {
                return $this->json(['success' => false, 'message' => 'Invalid reaction value'], Response::HTTP_BAD_REQUEST);
            }

            $result = $this->feedService->reactToPost($post['id'], $cqContactId, $cqContactUrl, (int) $reaction);

            return $this->json([
                'success' => true,
                'stats' => $result['stats'],
                'my_reaction' => $result['my_reaction'],
            ]);

        } catch (\Exception $e) {
            $this->logger->error('CQFeedController::feedPostReact error', [
                'error' => $e->getMessage(),
                'username' => $username,
                'feedUrlSlug' => $feedUrlSlug,
                'postSlug' => $postSlug,
            ]);
            return $this->json(['success' => false, 'message' => 'Internal error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get stats for a specific post (federation endpoint).
     * Returns 404 if post or feed no longer exists (enables stale cache cleanup).
     * 
     * GET /{username}/feed/{feedUrlSlug}/post/{postSlug}/stats
     */
    #[Route('/{username}/feed/{feedUrlSlug}/post/{postSlug}/stats', name: 'cq_feed_federation_post_stats', methods: ['GET'], priority: -10)]
    public function feedPostStats(Request $request, string $username, string $feedUrlSlug, string $postSlug): JsonResponse
    {
        try {
            $user = $this->resolveUser($username);
            if (!$user) {
                return $this->json(['success' => false, 'message' => 'Not found'], Response::HTTP_NOT_FOUND);
            }

            $this->feedService->setUser($user);
            $this->cqContactService->setUser($user);

            $feed = $this->feedService->findFeedBySlug($feedUrlSlug);
            if (!$feed) {
                return $this->json(['success' => false, 'message' => 'Feed not found'], Response::HTTP_NOT_FOUND);
            }

            $isAuthenticated = $this->checkFederationAuth($request);
            $scope = (int) $feed['scope'];
            if ($scope === CQShareService::SCOPE_CQ_CONTACT && !$isAuthenticated) {
                return $this->json(['success' => false, 'message' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
            }

            $post = $this->feedService->findPostByFeedSlugAndPostSlug($feedUrlSlug, $postSlug);
            if (!$post) {
                return $this->json(['success' => false, 'message' => 'Post not found'], Response::HTTP_NOT_FOUND);
            }

            $stats = json_decode($post['stats'] ?? '{}', true) ?: ['likes' => 0, 'dislikes' => 0, 'comments' => 0];
            unset($stats['my_reaction']);

            return $this->json([
                'success' => true,
                'stats' => $stats,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('CQFeedController::feedPostStats error', [
                'error' => $e->getMessage(),
                'username' => $username,
                'feedUrlSlug' => $feedUrlSlug,
                'postSlug' => $postSlug,
            ]);
            return $this->json(['success' => false, 'message' => 'Internal error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // ========================================
    // Comments (federation endpoints)
    // ========================================

    /**
     * Add a comment to a post (federation endpoint).
     * 
     * POST /{username}/feed/{feedUrlSlug}/post/{postSlug}/comment
     * Body: {"content": "...", "cq_contact_id": "...", "cq_contact_url": "...", "parent_id": null}
     */
    #[Route('/{username}/feed/{feedUrlSlug}/post/{postSlug}/comment', name: 'cq_feed_federation_comment_add', methods: ['POST'], priority: -10)]
    public function feedPostCommentAdd(Request $request, string $username, string $feedUrlSlug, string $postSlug): JsonResponse
    {
        try {
            $user = $this->resolveUser($username);
            if (!$user) {
                return $this->json(['success' => false, 'message' => 'Not found'], Response::HTTP_NOT_FOUND);
            }

            $this->feedService->setUser($user);
            $this->cqContactService->setUser($user);

            $feed = $this->feedService->findFeedBySlug($feedUrlSlug);
            if (!$feed) {
                return $this->json(['success' => false, 'message' => 'Feed not found'], Response::HTTP_NOT_FOUND);
            }

            $isAuthenticated = $this->checkFederationAuth($request);
            $scope = (int) $feed['scope'];
            if ($scope === CQShareService::SCOPE_CQ_CONTACT && !$isAuthenticated) {
                return $this->json(['success' => false, 'message' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
            }

            $post = $this->feedService->findPostByFeedSlugAndPostSlug($feedUrlSlug, $postSlug);
            if (!$post) {
                return $this->json(['success' => false, 'message' => 'Post not found'], Response::HTTP_NOT_FOUND);
            }

            $data = json_decode($request->getContent(), true);
            $content = $data['content'] ?? '';
            $cqContactId = $data['cq_contact_id'] ?? null;
            $cqContactUrl = $data['cq_contact_url'] ?? null;
            $parentId = $data['parent_id'] ?? null;

            if (!$cqContactId || !$cqContactUrl || empty(trim($content))) {
                return $this->json(['success' => false, 'message' => 'Missing fields: content, cq_contact_id, cq_contact_url'], Response::HTTP_BAD_REQUEST);
            }

            $result = $this->feedService->addComment($post['id'], $cqContactId, $cqContactUrl, $content, $parentId);

            return $this->json([
                'success' => true,
                'comment' => $result['comment'],
                'stats' => $result['stats'],
            ]);

        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('CQFeedController::feedPostCommentAdd error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => 'Internal error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * List comments for a post (federation endpoint).
     * 
     * GET /{username}/feed/{feedUrlSlug}/post/{postSlug}/comments
     */
    #[Route('/{username}/feed/{feedUrlSlug}/post/{postSlug}/comments', name: 'cq_feed_federation_comments_list', methods: ['GET'], priority: -10)]
    public function feedPostCommentsList(Request $request, string $username, string $feedUrlSlug, string $postSlug): JsonResponse
    {
        try {
            $user = $this->resolveUser($username);
            if (!$user) {
                return $this->json(['success' => false, 'message' => 'Not found'], Response::HTTP_NOT_FOUND);
            }

            $this->feedService->setUser($user);
            $this->cqContactService->setUser($user);

            $feed = $this->feedService->findFeedBySlug($feedUrlSlug);
            if (!$feed) {
                return $this->json(['success' => false, 'message' => 'Feed not found'], Response::HTTP_NOT_FOUND);
            }

            $isAuthenticated = $this->checkFederationAuth($request);
            $scope = (int) $feed['scope'];
            if ($scope === CQShareService::SCOPE_CQ_CONTACT && !$isAuthenticated) {
                return $this->json(['success' => false, 'message' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
            }

            $post = $this->feedService->findPostByFeedSlugAndPostSlug($feedUrlSlug, $postSlug);
            if (!$post) {
                return $this->json(['success' => false, 'message' => 'Post not found'], Response::HTTP_NOT_FOUND);
            }

            $page = max(1, (int) $request->query->get('page', 1));
            $limit = min(100, max(1, (int) $request->query->get('limit', 50)));

            $result = $this->feedService->listComments($post['id'], $page, $limit);

            return $this->json([
                'success' => true,
                'comments' => $result['comments'],
                'total' => $result['total'],
            ]);

        } catch (\Exception $e) {
            $this->logger->error('CQFeedController::feedPostCommentsList error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => 'Internal error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update a comment (federation endpoint — only by original commenter).
     * 
     * PUT /{username}/feed/{feedUrlSlug}/post/{postSlug}/comment/{commentId}
     * Body: {"content": "...", "cq_contact_id": "..."}
     */
    #[Route('/{username}/feed/{feedUrlSlug}/post/{postSlug}/comment/{commentId}', name: 'cq_feed_federation_comment_update', methods: ['PUT'], priority: -10)]
    public function feedPostCommentUpdate(Request $request, string $username, string $feedUrlSlug, string $postSlug, string $commentId): JsonResponse
    {
        try {
            $user = $this->resolveUser($username);
            if (!$user) {
                return $this->json(['success' => false, 'message' => 'Not found'], Response::HTTP_NOT_FOUND);
            }

            $this->feedService->setUser($user);
            $this->cqContactService->setUser($user);

            $feed = $this->feedService->findFeedBySlug($feedUrlSlug);
            if (!$feed) {
                return $this->json(['success' => false, 'message' => 'Feed not found'], Response::HTTP_NOT_FOUND);
            }

            $isAuthenticated = $this->checkFederationAuth($request);
            $scope = (int) $feed['scope'];
            if ($scope === CQShareService::SCOPE_CQ_CONTACT && !$isAuthenticated) {
                return $this->json(['success' => false, 'message' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
            }

            $data = json_decode($request->getContent(), true);
            $content = $data['content'] ?? '';
            $cqContactId = $data['cq_contact_id'] ?? null;

            if (!$cqContactId || empty(trim($content))) {
                return $this->json(['success' => false, 'message' => 'Missing fields: content, cq_contact_id'], Response::HTTP_BAD_REQUEST);
            }

            $comment = $this->feedService->updateComment($commentId, $cqContactId, $content);

            return $this->json([
                'success' => true,
                'comment' => $comment,
            ]);

        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('CQFeedController::feedPostCommentUpdate error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => 'Internal error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete a comment (federation endpoint — only by original commenter).
     * 
     * DELETE /{username}/feed/{feedUrlSlug}/post/{postSlug}/comment/{commentId}
     * Body: {"cq_contact_id": "..."}
     */
    #[Route('/{username}/feed/{feedUrlSlug}/post/{postSlug}/comment/{commentId}', name: 'cq_feed_federation_comment_delete', methods: ['DELETE'], priority: -10)]
    public function feedPostCommentDelete(Request $request, string $username, string $feedUrlSlug, string $postSlug, string $commentId): JsonResponse
    {
        try {
            $user = $this->resolveUser($username);
            if (!$user) {
                return $this->json(['success' => false, 'message' => 'Not found'], Response::HTTP_NOT_FOUND);
            }

            $this->feedService->setUser($user);
            $this->cqContactService->setUser($user);

            $feed = $this->feedService->findFeedBySlug($feedUrlSlug);
            if (!$feed) {
                return $this->json(['success' => false, 'message' => 'Feed not found'], Response::HTTP_NOT_FOUND);
            }

            $isAuthenticated = $this->checkFederationAuth($request);
            $scope = (int) $feed['scope'];
            if ($scope === CQShareService::SCOPE_CQ_CONTACT && !$isAuthenticated) {
                return $this->json(['success' => false, 'message' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
            }

            $data = json_decode($request->getContent(), true);
            $cqContactId = $data['cq_contact_id'] ?? null;

            if (!$cqContactId) {
                return $this->json(['success' => false, 'message' => 'Missing field: cq_contact_id'], Response::HTTP_BAD_REQUEST);
            }

            $result = $this->feedService->deleteComment($commentId, $cqContactId);

            return $this->json([
                'success' => true,
                'stats' => $result['stats'],
            ]);

        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('CQFeedController::feedPostCommentDelete error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => 'Internal error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // ========================================
    // Helpers
    // ========================================

    private function resolveUser(string $username): ?User
    {
        return $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
    }

    /**
     * Check if the request has a valid CQ Contact API key.
     */
    private function checkFederationAuth(Request $request): bool
    {
        $authHeader = $request->headers->get('Authorization');
        if (!$authHeader) {
            return false;
        }

        $apiKey = str_replace('Bearer ', '', $authHeader);
        if (empty($apiKey)) {
            return false;
        }

        $contact = $this->cqContactService->findByApiKey($apiKey);
        return $contact !== null;
    }
}
