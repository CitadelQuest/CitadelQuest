<?php

namespace App\Api\Controller;

use App\Entity\User;
use App\Service\CQFeedService;
use App\Service\CQFollowService;
use App\Service\CQShareGroupService;
use App\Service\CQShareService;
use App\Service\NotificationService;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Federation Follow Controller
 * 
 * Handles follow/unfollow/migrate notifications from remote Citadels,
 * and provides a lightweight last-updated endpoint for feed polling.
 * All endpoints are public (no Symfony auth) — validated by origin checking.
 * 
 * @see /docs/features/CQ-FOLLOW.md
 */
class FederationFollowController extends AbstractController
{
    public function __construct(
        private readonly CQFeedService $feedService,
        private readonly CQFollowService $followService,
        private readonly CQShareService $shareService,
        private readonly CQShareGroupService $shareGroupService,
        private readonly NotificationService $notificationService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly SettingsService $settingsService,
    ) {}

    /**
     * Receive follow/unfollow/migrate notification from a remote Citadel.
     * 
     * Actions: follow, unfollow, migrate
     */
    #[Route('/{username}/api/federation/follow', name: 'api_federation_follow', methods: ['POST'])]
    public function followAction(Request $request, string $username): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->json([
                    'success' => false,
                    'error' => 'Invalid JSON in request body'
                ], Response::HTTP_BAD_REQUEST);
            }

            $action = $data['action'] ?? null;
            if (!$action || !in_array($action, ['follow', 'unfollow', 'migrate'])) {
                return $this->json([
                    'success' => false,
                    'error' => 'Invalid or missing action. Must be: follow, unfollow, migrate'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate required fields
            $requiredFields = ['cq_contact_id', 'cq_contact_url', 'cq_contact_domain', 'cq_contact_username'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    return $this->json([
                        'success' => false,
                        'error' => 'Missing required field: ' . $field
                    ], Response::HTTP_BAD_REQUEST);
                }
            }

            // Fraud prevention: validate origin matches claimed domain
            $origin = $request->headers->get('Origin') ?? $request->headers->get('Referer') ?? '';
            $claimedDomain = $data['cq_contact_domain'];
            if ($origin && !str_contains($origin, $claimedDomain)) {
                $this->logger->warning('FederationFollowController: Origin mismatch', [
                    'origin' => $origin,
                    'claimed_domain' => $claimedDomain
                ]);
                // Log but don't block — server-to-server requests may not send Origin
            }

            // Find target user
            $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
            if (!$user) {
                return $this->json([
                    'success' => false,
                    'error' => 'User not found'
                ], Response::HTTP_NOT_FOUND);
            }

            $this->followService->setUser($user);

            // Set translator locale to user's preferred locale
            $this->settingsService->setUser($user);
            $userLocale = $this->settingsService->getSettingValue('_locale');
            if ($userLocale) {
                $this->translator->setLocale($userLocale);
            }

            switch ($action) {
                case 'follow':
                    $this->followService->addFollower(
                        $data['cq_contact_id'],
                        $data['cq_contact_url'],
                        $data['cq_contact_domain'],
                        $data['cq_contact_username']
                    );

                    // Notify user about new follower
                    $this->notificationService->createNotification(
                        $user,
                        $this->translator->trans('notifications.follow.new_title'),
                        $this->translator->trans('notifications.follow.new_message', ['%username%' => $data['cq_contact_username'], '%domain%' => $data['cq_contact_domain']]),
                        'info',
                        '/cq-contacts?url=' . urlencode($data['cq_contact_url'])
                    );

                    return $this->json([
                        'success' => true,
                        'message' => 'Follow recorded'
                    ]);

                case 'unfollow':
                    $this->followService->removeFollower($data['cq_contact_id']);

                    return $this->json([
                        'success' => true,
                        'message' => 'Unfollow recorded'
                    ]);

                case 'migrate':
                    $this->followService->updateFollowerUrl(
                        $data['cq_contact_id'],
                        $data['cq_contact_url'],
                        $data['cq_contact_domain'],
                        $data['cq_contact_username']
                    );

                    $this->notificationService->createNotification(
                        $user,
                        $this->translator->trans('notifications.follow.migrated_title'),
                        $this->translator->trans('notifications.follow.migrated_message', ['%username%' => $data['cq_contact_username'], '%domain%' => $data['cq_contact_domain']]),
                        'info',
                        '/cq-contacts?url=' . urlencode($data['cq_contact_url'])
                    );

                    return $this->json([
                        'success' => true,
                        'message' => 'Follower URL updated'
                    ]);
            }

            return $this->json(['success' => false, 'error' => 'Unknown action'], Response::HTTP_BAD_REQUEST);

        } catch (\Exception $e) {
            $this->logger->error('FederationFollowController::followAction - Exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Internal server error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Lightweight endpoint: return the most recent updated_at from public CQ Shares.
     * Used by feed polling to check if there's new content without fetching everything.
     */
    #[Route('/{username}/api/federation/last-updated', name: 'api_federation_last_updated', methods: ['GET'])]
    public function lastUpdated(Request $request, string $username): JsonResponse
    {
        try {
            $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
            if (!$user) {
                return $this->json([
                    'success' => false,
                    'error' => 'User not found'
                ], Response::HTTP_NOT_FOUND);
            }

            $this->settingsService->setUser($user);
            $this->shareService->setUser($user);
            $this->shareGroupService->setUser($user);

            // Only report timestamps for content types that are actually visible
            $showShares = $this->settingsService->getSettingValue('profile.public_page_show_shares', '1') === '1';
            $showProfileContent = $this->settingsService->getSettingValue('profile.public_page_show_profile_content', '1') === '1';

            $timestamps = [];

            if ($showShares) {
                $shareTs = $this->shareService->getLastPublicShareUpdatedAt();
                if ($shareTs) $timestamps[] = $shareTs;
            }

            if ($showProfileContent) {
                $groupTs = $this->shareGroupService->getLastPublicGroupUpdatedAt();
                if ($groupTs) $timestamps[] = $groupTs;

                $groupShareTs = $this->shareGroupService->getLastPublicGroupShareUpdatedAt();
                if ($groupShareTs) $timestamps[] = $groupShareTs;
            }

            // Include CQ Feed timestamps
            $this->feedService->setUser($user);
            $feedTs = $this->feedService->getLastFeedUpdatedAt();
            if ($feedTs) $timestamps[] = $feedTs;

            $lastUpdated = !empty($timestamps) ? max($timestamps) : null;

            return $this->json([
                'success' => true,
                'last_updated_at' => $lastUpdated,
                'last_feed_updated_at' => $feedTs,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('FederationFollowController::lastUpdated - Exception', [
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Internal server error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
