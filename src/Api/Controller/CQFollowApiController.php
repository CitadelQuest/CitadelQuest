<?php

namespace App\Api\Controller;

use App\Entity\User;
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
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Internal API for CQ Follow actions (requires authenticated user).
 * Handles follow/unfollow from the user's browser + feed data.
 * 
 * @see /docs/features/CQ-FOLLOW.md
 */
#[Route('/api/follow')]
#[IsGranted('ROLE_USER')]
class CQFollowApiController extends AbstractController
{
    public function __construct(
        private readonly CQFollowService $followService,
        private readonly CQFederationFeedService $federationFeedService,
        private readonly CqContactService $contactService,
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Follow a CQ Profile.
     * Also sends follow notification to the remote Citadel.
     */
    #[Route('/follow', name: 'api_follow_follow', methods: ['POST'])]
    public function follow(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            if (!$data) {
                return $this->json(['success' => false, 'error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
            }

            $requiredFields = ['cq_contact_id', 'cq_contact_url', 'cq_contact_domain', 'cq_contact_username'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    return $this->json(['success' => false, 'error' => 'Missing field: ' . $field], Response::HTTP_BAD_REQUEST);
                }
            }

            /** @var User $user */
            $user = $this->getUser();

            $this->followService->setUser($user);
            $follow = $this->followService->follow(
                $data['cq_contact_id'],
                $data['cq_contact_url'],
                $data['cq_contact_domain'],
                $data['cq_contact_username']
            );

            // Send follow notification to the remote Citadel
            $this->sendFollowNotification($user, $data, 'follow');

            // Auto-subscribe to all feeds from the followed profile
            try {
                $this->federationFeedService->setUser($user);
                $this->contactService->setUser($user);
                $contact = $this->contactService->findById($data['cq_contact_id']);
                $apiKey = $contact ? $contact->getCqContactApiKey() : null;
                $this->federationFeedService->subscribeAllFeeds(
                    $data['cq_contact_id'],
                    $data['cq_contact_url'],
                    $data['cq_contact_domain'],
                    $data['cq_contact_username'],
                    $apiKey
                );
            } catch (\Exception $e) {
                $this->logger->warning('CQFollowApiController::follow - Auto-subscribe feeds failed', ['error' => $e->getMessage()]);
            }

            return $this->json([
                'success' => true,
                'follow' => $follow
            ]);

        } catch (\Exception $e) {
            $this->logger->error('CQFollowApiController::follow - Exception', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'error' => 'Internal server error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Unfollow a CQ Profile.
     * Also sends unfollow notification to the remote Citadel.
     */
    #[Route('/unfollow', name: 'api_follow_unfollow', methods: ['POST'])]
    public function unfollow(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            if (empty($data['cq_contact_id'])) {
                return $this->json(['success' => false, 'error' => 'Missing cq_contact_id'], Response::HTTP_BAD_REQUEST);
            }

            /** @var User $user */
            $user = $this->getUser();

            $this->followService->setUser($user);

            // Get follow data before deleting (for notification)
            $follow = $this->followService->getFollowByContactId($data['cq_contact_id']);
            if (!$follow) {
                return $this->json(['success' => false, 'error' => 'Not following this profile'], Response::HTTP_NOT_FOUND);
            }

            $this->followService->unfollow($data['cq_contact_id']);

            // Unsubscribe from all feeds of the unfollowed profile
            try {
                $this->federationFeedService->setUser($user);
                $this->federationFeedService->unsubscribeAllFeeds($data['cq_contact_id']);
            } catch (\Exception $e) {
                $this->logger->warning('CQFollowApiController::unfollow - Unsubscribe feeds failed', ['error' => $e->getMessage()]);
            }

            // Send unfollow notification to the remote Citadel
            $this->sendFollowNotification($user, [
                'cq_contact_id' => $follow['cq_contact_id'],
                'cq_contact_url' => $follow['cq_contact_url'],
                'cq_contact_domain' => $follow['cq_contact_domain'],
                'cq_contact_username' => $follow['cq_contact_username'],
            ], 'unfollow');

            return $this->json(['success' => true]);

        } catch (\Exception $e) {
            $this->logger->error('CQFollowApiController::unfollow - Exception', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'error' => 'Internal server error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * List all follows with their new-content status.
     */
    #[Route('/list', name: 'api_follow_list', methods: ['GET'])]
    public function listFollows(): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $this->getUser();
            $this->followService->setUser($user);

            return $this->json([
                'success' => true,
                'follows' => $this->followService->listFollows(),
                'follow_count' => $this->followService->getFollowCount(),
            ]);

        } catch (\Exception $e) {
            $this->logger->error('CQFollowApiController::listFollows - Exception', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'error' => 'Internal server error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * List all followers.
     */
    #[Route('/followers', name: 'api_follow_followers', methods: ['GET'])]
    public function listFollowers(): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $this->getUser();
            $this->followService->setUser($user);

            return $this->json([
                'success' => true,
                'followers' => $this->followService->listFollowers(),
                'follower_count' => $this->followService->getFollowerCount(),
            ]);

        } catch (\Exception $e) {
            $this->logger->error('CQFollowApiController::listFollowers - Exception', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'error' => 'Internal server error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Check follow status for a specific cq_contact_id.
     */
    #[Route('/status/{cqContactId}', name: 'api_follow_status', methods: ['GET'])]
    public function status(string $cqContactId): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $this->getUser();
            $this->followService->setUser($user);

            return $this->json([
                'success' => true,
                'is_following' => $this->followService->isFollowing($cqContactId),
            ]);

        } catch (\Exception $e) {
            $this->logger->error('CQFollowApiController::status - Exception', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'error' => 'Internal server error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Mark a followed profile as visited (reset last_visited_at).
     */
    #[Route('/visited', name: 'api_follow_visited', methods: ['POST'])]
    public function markVisited(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            if (empty($data['cq_contact_id'])) {
                return $this->json(['success' => false, 'error' => 'Missing cq_contact_id'], Response::HTTP_BAD_REQUEST);
            }

            /** @var User $user */
            $user = $this->getUser();
            $this->followService->setUser($user);
            $this->followService->updateLastVisited($data['cq_contact_id']);

            return $this->json(['success' => true]);

        } catch (\Exception $e) {
            $this->logger->error('CQFollowApiController::markVisited - Exception', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'error' => 'Internal server error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get feed updates: check last-updated for all followed profiles (server-side).
     * Returns each follow with its remote last_updated_at + hasNew flag.
     */
    #[Route('/feed-updates', name: 'api_follow_feed_updates', methods: ['GET'])]
    public function feedUpdates(): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $this->getUser();
            $this->followService->setUser($user);
            $follows = $this->followService->listFollows();

            $results = [];
            foreach ($follows as $follow) {
                $lastUpdated = null;
                $error = false;
                $lastFeedUpdated = null;
                try {
                    $url = 'https://' . $follow['cq_contact_domain'] . '/' . $follow['cq_contact_username'] . '/api/federation/last-updated';
                    $resp = $this->httpClient->request('GET', $url, [
                        'timeout' => 8,
                        'verify_peer' => false,
                    ]);
                    $data = $resp->toArray(false);
                    $lastUpdated = $data['last_updated_at'] ?? null;
                    $lastFeedUpdated = $data['last_feed_updated_at'] ?? null;
                } catch (\Exception $e) {
                    $error = true;
                }

                $visited = $follow['last_visited_at'];
                $hasNew = $lastUpdated && $visited && $lastUpdated > $visited;
                $hasNewFeed = $lastFeedUpdated && $visited && $lastFeedUpdated > $visited;
                // Content-only: new content exists but not solely from feed
                $hasNewContent = $hasNew && (!$hasNewFeed || ($lastUpdated !== $lastFeedUpdated));

                $results[] = [
                    'cq_contact_id' => $follow['cq_contact_id'],
                    'cq_contact_url' => $follow['cq_contact_url'],
                    'cq_contact_domain' => $follow['cq_contact_domain'],
                    'cq_contact_username' => $follow['cq_contact_username'],
                    'last_visited_at' => $follow['last_visited_at'],
                    'last_updated_at' => $lastUpdated,
                    'last_feed_updated_at' => $lastFeedUpdated,
                    'has_new' => $hasNew,
                    'has_new_content' => $hasNewContent,
                    'has_new_feed' => $hasNewFeed,
                    'error' => $error,
                    'created_at' => $follow['created_at'],
                ];
            }

            return $this->json(['success' => true, 'items' => $results]);

        } catch (\Exception $e) {
            $this->logger->error('CQFollowApiController::feedUpdates - Exception', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'error' => 'Internal server error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get followers feed updates: check last-updated for all followers (server-side).
     */
    #[Route('/followers-feed-updates', name: 'api_follow_followers_feed_updates', methods: ['GET'])]
    public function followersFeedUpdates(): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $this->getUser();
            $this->followService->setUser($user);
            $followers = $this->followService->listFollowers();

            $results = [];
            foreach ($followers as $follower) {
                $lastUpdated = null;
                try {
                    $url = 'https://' . $follower['cq_contact_domain'] . '/' . $follower['cq_contact_username'] . '/api/federation/last-updated';
                    $resp = $this->httpClient->request('GET', $url, [
                        'timeout' => 8,
                        'verify_peer' => false,
                    ]);
                    $data = $resp->toArray(false);
                    $lastUpdated = $data['last_updated_at'] ?? null;
                } catch (\Exception $e) {}

                $results[] = [
                    'cq_contact_id' => $follower['cq_contact_id'],
                    'cq_contact_url' => $follower['cq_contact_url'],
                    'cq_contact_domain' => $follower['cq_contact_domain'],
                    'cq_contact_username' => $follower['cq_contact_username'],
                    'last_updated_at' => $lastUpdated,
                    'created_at' => $follower['created_at'],
                ];
            }

            return $this->json(['success' => true, 'items' => $results]);

        } catch (\Exception $e) {
            $this->logger->error('CQFollowApiController::followersFeedUpdates - Exception', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'error' => 'Internal server error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Send follow/unfollow notification to the remote Citadel.
     */
    private function sendFollowNotification(User $user, array $data, string $action): void
    {
        try {
            // Build the target user's federation follow endpoint
            $targetUrl = $data['cq_contact_url'];
            // Extract username from URL (last path segment)
            $urlParts = explode('/', rtrim($targetUrl, '/'));
            $targetUsername = end($urlParts);
            $federationUrl = 'https://' . $data['cq_contact_domain'] . '/' . $targetUsername . '/api/federation/follow';

            // Build our own contact info
            $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';

            // Get our cq_contact_id — use the user's ID as a stable identifier
            $this->contactService->setUser($user);
            $ourContactId = $user->getId()->toRfc4122();

            $this->httpClient->request('POST', $federationUrl, [
                'json' => [
                    'cq_contact_id' => $ourContactId,
                    'cq_contact_url' => 'https://' . $domain . '/' . $user->getUsername(),
                    'cq_contact_domain' => $domain,
                    'cq_contact_username' => $user->getUsername(),
                    'action' => $action,
                ],
                'timeout' => 10,
                'verify_peer' => false,
            ]);
        } catch (\Exception $e) {
            // Don't fail the follow action if notification fails
            $this->logger->warning('CQFollowApiController::sendFollowNotification - Failed', [
                'error' => $e->getMessage(),
                'target_domain' => $data['cq_contact_domain'] ?? 'unknown'
            ]);
        }
    }
}
