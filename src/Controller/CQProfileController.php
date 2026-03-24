<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\CqContactService;
use App\Service\CQFollowService;
use App\Service\CQShareGroupService;
use App\Service\CQShareService;
use App\Service\CQFeedService;
use App\Service\ProjectFileService;
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
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * CQ Profile Controller
 * 
 * Handles public profile page, profile photo serving, and federation user-profile endpoint.
 * All routes use low priority to avoid catching other routes.
 * 
 * @see /docs/features/CQ-PROFILE.md
 */
class CQProfileController extends AbstractController
{
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly CqContactService $cqContactService,
        private readonly CQFollowService $followService,
        private readonly CQShareService $shareService,
        private readonly CQShareGroupService $shareGroupService,
        private readonly CQFeedService $feedService,
        private readonly ProjectFileService $projectFileService,
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly ParameterBagInterface $params,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator
    ) {}

    /**
     * Public profile page: GET /{username}
     * Shows user's public profile if enabled, otherwise 404.
     */
    #[Route('/{username}', name: 'cq_profile_public', methods: ['GET'], priority: -100)]
    public function publicProfile(Request $request, string $username): Response
    {
        $user = $this->resolveUser($username);
        if (!$user) {
            throw $this->createNotFoundException();
        }

        return $this->renderProfilePage($request, $user, $username, null);
    }

    /**
     * Public feed page: GET /{username}/view-feed/{feedSlug}
     * Shows posts from a specific public feed.
     */
    #[Route('/{username}/view-feed/{feedSlug}', name: 'cq_profile_public_feed', methods: ['GET'], priority: -10)]
    public function publicFeed(Request $request, string $username, string $feedSlug): Response
    {
        $user = $this->resolveUser($username);
        if (!$user) {
            throw $this->createNotFoundException();
        }

        $this->settingsService->setUser($user);

        $publicEnabled = $this->settingsService->getSettingValue('profile.public_page_enabled', '1');
        if ($publicEnabled !== '1') {
            throw $this->createNotFoundException();
        }

        // Apply locale
        $locale = $this->settingsService->getSettingValue('profile.public_page_locale', 'en');
        if (in_array($locale, ['en', 'cs', 'sk', 'es', 'hu', 'pl', 'no', 'it'])) {
            $request->setLocale($locale);
            $this->translator->setLocale($locale);
        }

        $this->feedService->setUser($user);
        $feed = $this->feedService->findFeedBySlug($feedSlug);

        if (!$feed || (int) $feed['scope'] !== CQFeedService::SCOPE_PUBLIC) {
            throw $this->createNotFoundException();
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 20;
        $posts = $this->feedService->listPosts($feed['id'], $page, $limit);
        $total = $this->feedService->countPosts($feed['id']);
        $hasMore = ($page * $limit) < $total;

        // Profile data for layout
        $bio = $this->settingsService->getSettingValue('profile.bio');
        $photoFileId = $this->settingsService->getSettingValue('profile.photo_project_file_id');
        $showPhoto = $this->settingsService->getSettingValue('profile.public_page_show_photo', '1') === '1';
        $pageTheme = $this->settingsService->getSettingValue('profile.public_page_theme', '');
        $customBgFileId = $this->settingsService->getSettingValue('profile.public_page_custom_bg_file_id');
        $bgOverlay = $this->settingsService->getSettingValue('profile.public_page_bg_overlay', '1') === '1';

        // All public feeds for nav
        $publicFeeds = $this->feedService->listActiveFeedsByScope([CQFeedService::SCOPE_PUBLIC]);

        return $this->render('profile/feed.html.twig', [
            'profile_username' => $username,
            'profile_domain' => $request->getHost(),
            'profile_bio' => $bio,
            'profile_has_photo' => $showPhoto && !empty($photoFileId),
            'profile_theme' => $pageTheme,
            'profile_custom_bg' => !empty($customBgFileId),
            'profile_bg_overlay' => $bgOverlay,
            'profile_locale' => $locale,
            'profile_feeds' => $publicFeeds,
            'feed' => $feed,
            'posts' => $posts,
            'page' => $page,
            'total' => $total,
            'has_more' => $hasMore,
        ]);
    }

    /**
     * Public profile page with group slug: GET /{username}/{groupSlug}
     * Shows a specific Profile Content group.
     */
    #[Route('/{username}/{groupSlug}', name: 'cq_profile_public_group', methods: ['GET'], priority: -101)]
    public function publicProfileGroup(Request $request, string $username, string $groupSlug): Response
    {
        $user = $this->resolveUser($username);
        if (!$user) {
            throw $this->createNotFoundException();
        }

        return $this->renderProfilePage($request, $user, $username, $groupSlug);
    }

    /**
     * Shared logic for rendering public profile pages (homepage or group-specific).
     */
    private function renderProfilePage(Request $request, $user, string $username, ?string $groupSlug): Response
    {
        // Set user context for settings service
        $this->settingsService->setUser($user);

        // Check if public page is enabled
        $publicEnabled = $this->settingsService->getSettingValue('profile.public_page_enabled', '1');
        if ($publicEnabled !== '1') {
            throw $this->createNotFoundException();
        }

        // Gather profile data
        $bio = $this->settingsService->getSettingValue('profile.bio');
        $photoFileId = $this->settingsService->getSettingValue('profile.photo_project_file_id');
        $showPhoto = $this->settingsService->getSettingValue('profile.public_page_show_photo', '1') === '1';
        $showShares = $this->settingsService->getSettingValue('profile.public_page_show_shares', '0') === '1';
        $showProfileContent = $this->settingsService->getSettingValue('profile.public_page_show_profile_content', '1') === '1';
        $showShareContent = $this->settingsService->getSettingValue('profile.public_page_show_share_content', '1') === '1';
        $spiritMode = (int) $this->settingsService->getSettingValue('profile.public_page_show_spirits', '1');
        $pageTheme = $this->settingsService->getSettingValue('profile.public_page_theme', '');
        $customBgFileId = $this->settingsService->getSettingValue('profile.public_page_custom_bg_file_id');
        $bgOverlay = $this->settingsService->getSettingValue('profile.public_page_bg_overlay', '1') === '1';

        // Apply locale setting for this public profile page
        $locale = $this->settingsService->getSettingValue('profile.public_page_locale', 'en');
        if (in_array($locale, ['en', 'cs', 'sk', 'es', 'hu', 'pl', 'no', 'it'])) {
            $request->setLocale($locale);
            $this->translator->setLocale($locale);
        }

        // Load Profile Content (share groups) — independent from old shares
        $allShareGroups = [];
        $activeGroup = null;
        $activeGroupSlug = null;
        if ($showProfileContent) {
            try {
                $this->shareGroupService->setUser($user);
                $allShareGroups = $this->shareGroupService->listActiveGroupsWithItems([CQShareGroupService::SCOPE_PUBLIC]);

                // Profile Content groups always get content previews (independent from show_share_content toggle)
                if (!empty($allShareGroups)) {
                    $this->shareService->setUser($user);
                    foreach ($allShareGroups as &$group) {
                        if (!empty($group['items'])) {
                            $group['items'] = $this->shareService->enrichSharesWithPreview($user, $username, $group['items']);
                        }
                    }
                    unset($group);
                }

                // Determine which group to display
                if ($groupSlug) {
                    foreach ($allShareGroups as $g) {
                        if (($g['url_slug'] ?? '') === $groupSlug) {
                            $activeGroup = $g;
                            $activeGroupSlug = $groupSlug;
                            break;
                        }
                    }
                    // If slug doesn't match any group, 404
                    if (!$activeGroup) {
                        throw $this->createNotFoundException();
                    }
                } else {
                    // Homepage: show first group if any (but don't set slug — no auto-scroll)
                    if (!empty($allShareGroups)) {
                        $activeGroup = $allShareGroups[0];
                    }
                }
            } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
                throw $e;
            } catch (\Exception $e) {
                $this->logger->warning('CQProfileController: Failed to load profile content groups', [
                    'username' => $username,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Get ungrouped public shares if enabled
        $publicShares = [];
        if ($showShares) {
            try {
                $this->shareService->setUser($user);
                $this->shareGroupService->setUser($user);
                $publicShares = $this->shareService->listPublicShares();

                // Filter out grouped shares from the ungrouped list
                $groupedShareIds = $this->shareGroupService->getGroupedShareIds([CQShareGroupService::SCOPE_PUBLIC]);
                if (!empty($groupedShareIds)) {
                    $publicShares = array_values(array_filter($publicShares, fn($s) => !in_array($s['id'], $groupedShareIds)));
                }

                // Enrich ungrouped shares with content preview data if enabled
                if ($showShareContent && !empty($publicShares)) {
                    $publicShares = $this->shareService->enrichSharesWithPreview($user, $username, $publicShares);
                }
            } catch (\Exception $e) {
                $this->logger->warning('CQProfileController: Failed to load public shares', [
                    'username' => $username,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Get spirits based on mode: 0=off, 1=primary only, 2=all
        $spirits = [];
        if ($spiritMode > 0) {
            try {
                $allSpirits = $this->loadUserSpirits($user);
                if ($spiritMode === 1) {
                    foreach ($allSpirits as $s) {
                        if ($s['isPrimary']) {
                            $spirits[] = $s;
                            break;
                        }
                    }
                } else {
                    $spirits = $allSpirits;
                }
            } catch (\Exception $e) {
                $this->logger->warning('CQProfileController: Failed to load spirits', [
                    'username' => $username,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Load public feeds
        $publicFeeds = [];
        try {
            $this->feedService->setUser($user);
            $publicFeeds = $this->feedService->listActiveFeedsByScope([CQFeedService::SCOPE_PUBLIC]);
        } catch (\Exception $e) {
            $this->logger->warning('CQProfileController: Failed to load public feeds', [
                'username' => $username,
                'error' => $e->getMessage()
            ]);
        }

        return $this->render('profile/public.html.twig', [
            'profile_username' => $username,
            'profile_domain' => $request->getHost(),
            'profile_bio' => $bio,
            'profile_has_photo' => $showPhoto && !empty($photoFileId),
            'profile_shares' => $publicShares,
            'profile_share_groups' => $allShareGroups,
            'profile_active_group' => $activeGroup,
            'profile_active_group_slug' => $activeGroupSlug,
            'profile_show_shares' => $showShares,
            'profile_show_profile_content' => $showProfileContent,
            'profile_show_share_content' => $showShareContent,
            'profile_spirits' => $spirits,
            'profile_theme' => $pageTheme,
            'profile_custom_bg' => !empty($customBgFileId),
            'profile_bg_overlay' => $bgOverlay,
            'profile_locale' => $locale,
            'profile_feeds' => $publicFeeds,
        ]);
    }

    /**
     * Public profile JSON API: GET /{username}/json
     * Returns public profile data as JSON for Citadel Explorer.
     * Only returns data that's already publicly visible on the HTML profile page.
     */
    #[Route('/{username}/json', name: 'cq_profile_public_json', methods: ['GET'], priority: -10)]
    public function publicProfileJson(Request $request, string $username): JsonResponse
    {
        $user = $this->resolveUser($username);
        if (!$user) {
            return $this->json(['success' => false, 'message' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        $this->settingsService->setUser($user);

        $publicEnabled = $this->settingsService->getSettingValue('profile.public_page_enabled', '1');
        if ($publicEnabled !== '1') {
            return $this->json(['success' => false, 'message' => 'Profile not public'], Response::HTTP_NOT_FOUND);
        }

        $domain = $request->getHost();
        $bio = $this->settingsService->getSettingValue('profile.bio');
        $photoFileId = $this->settingsService->getSettingValue('profile.photo_project_file_id');
        $showPhoto = $this->settingsService->getSettingValue('profile.public_page_show_photo', '1') === '1';
        $showShares = $this->settingsService->getSettingValue('profile.public_page_show_shares', '0') === '1';
        $showProfileContent = $this->settingsService->getSettingValue('profile.public_page_show_profile_content', '1') === '1';
        $showShareContent = $this->settingsService->getSettingValue('profile.public_page_show_share_content', '1') === '1';
        $spiritMode = (int) $this->settingsService->getSettingValue('profile.public_page_show_spirits', '1');

        $customBgFileId = $this->settingsService->getSettingValue('profile.public_page_custom_bg_file_id');
        $bgOverlay = $this->settingsService->getSettingValue('profile.public_page_bg_overlay', '1') === '1';

        // Follower count
        $followerCount = 0;
        try {
            $this->followService->setUser($user);
            $followerCount = $this->followService->getFollowerCount();
        } catch (\Exception $e) {}

        $response = [
            'success' => true,
            'cq_contact_id' => $user->getId()->toRfc4122(),
            'username' => $username,
            'domain' => $domain,
            'bio' => $bio,
            'photo_url' => ($showPhoto && $photoFileId) ? ('https://' . $domain . '/' . $username . '/photo') : null,
            'profile_url' => 'https://' . $domain . '/' . $username,
            'background_url' => !empty($customBgFileId) ? ('https://' . $domain . '/' . $username . '/background') : null,
            'bg_overlay' => $bgOverlay,
            'follower_count' => $followerCount,
        ];

        // Profile Content (share groups) — independent from old shares
        $shareGroups = [];
        if ($showProfileContent) {
            try {
                $this->shareGroupService->setUser($user);
                $shareGroups = $this->shareGroupService->listActiveGroupsWithItems([CQShareGroupService::SCOPE_PUBLIC]);

                // Profile Content groups always get content previews (independent from show_share_content toggle)
                if (!empty($shareGroups)) {
                    $this->shareService->setUser($user);
                    foreach ($shareGroups as &$group) {
                        if (!empty($group['items'])) {
                            $group['items'] = $this->shareService->enrichSharesWithPreview($user, $username, $group['items']);
                            $this->shareService->convertPreviewUrlsToAbsolute($group['items'], $domain);
                        }
                    }
                    unset($group);
                }
            } catch (\Exception $e) {
                $this->logger->warning('CQProfileController::publicProfileJson: profile content error', [
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Ungrouped public shares
        $publicShares = [];
        if ($showShares) {
            try {
                $this->shareService->setUser($user);
                $this->shareGroupService->setUser($user);
                $publicShares = $this->shareService->listPublicShares();

                $groupedShareIds = $this->shareGroupService->getGroupedShareIds([CQShareGroupService::SCOPE_PUBLIC]);
                if (!empty($groupedShareIds)) {
                    $publicShares = array_values(array_filter($publicShares, fn($s) => !in_array($s['id'], $groupedShareIds)));
                }

                if ($showShareContent && !empty($publicShares)) {
                    $publicShares = $this->shareService->enrichSharesWithPreview($user, $username, $publicShares);
                    $this->shareService->convertPreviewUrlsToAbsolute($publicShares, $domain);
                }
            } catch (\Exception $e) {
                $this->logger->warning('CQProfileController::publicProfileJson: shares error', [
                    'error' => $e->getMessage()
                ]);
            }
        }
        $response['shares'] = $publicShares;
        $response['share_groups'] = $shareGroups;
        $response['show_share_content'] = $showShareContent;

        // Spirits
        $spirits = [];
        if ($spiritMode > 0) {
            try {
                $allSpirits = $this->loadUserSpirits($user);
                if ($spiritMode === 1) {
                    foreach ($allSpirits as $s) {
                        if ($s['isPrimary']) { $spirits[] = $s; break; }
                    }
                } else {
                    $spirits = $allSpirits;
                }
            } catch (\Exception $e) {}
        }
        $response['spirits'] = $spirits;

        return $this->json($response);
    }

    /**
     * Custom background image endpoint: GET /{username}/background
     * Serves the user's custom background image for their public profile.
     */
    #[Route('/{username}/background', name: 'cq_profile_background', methods: ['GET'], priority: -10)]
    public function profileBackground(Request $request, string $username): Response
    {
        $user = $this->resolveUser($username);
        if (!$user) {
            throw $this->createNotFoundException();
        }

        $this->settingsService->setUser($user);

        // Background is only served if public page is enabled
        $publicEnabled = $this->settingsService->getSettingValue('profile.public_page_enabled', '1') === '1';
        $currentUser = $this->getUser();
        $isOwnUser = $currentUser && $currentUser->getId() === $user->getId();

        if (!$isOwnUser && !$publicEnabled) {
            throw $this->createNotFoundException();
        }

        $bgFileId = $this->settingsService->getSettingValue('profile.public_page_custom_bg_file_id');
        if (!$bgFileId) {
            throw $this->createNotFoundException();
        }

        // Look up the file in user's database
        $userDb = $this->userDatabaseManager->getDatabaseConnection($user);
        $file = $userDb->executeQuery(
            'SELECT * FROM project_file WHERE id = ?',
            [$bgFileId]
        )->fetchAssociative();

        if (!$file) {
            throw $this->createNotFoundException();
        }

        // Construct absolute path
        $projectDir = $this->params->get('kernel.project_dir');
        $basePath = $projectDir . '/var/user_data/' . $user->getId() . '/p/' . $file['project_id'];
        $relativePath = ltrim($file['path'] ?? '', '/');      
        $filePath = $relativePath
            ? $basePath . '/' . $relativePath . '/' . $file['name']
            : $basePath . '/' . $file['name'];

        if (!file_exists($filePath)) {
            throw $this->createNotFoundException();
        }

        $mimeType = $file['mime_type'] ?? 'image/jpeg';

        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $file['name']);
        $response->headers->set('Content-Type', $mimeType);
        $response->headers->set('Cache-Control', 'public, max-age=3600');

        return $response;
    }

    /**
     * Profile photo endpoint: GET /{username}/photo
     * Serves the user's profile photo if available and authorized.
     */
    #[Route('/{username}/photo', name: 'cq_profile_photo', methods: ['GET'], priority: -10)]
    public function profilePhoto(Request $request, string $username): Response
    {
        $user = $this->resolveUser($username);
        if (!$user) {
            throw $this->createNotFoundException();
        }

        $this->settingsService->setUser($user);

        // Check access: own user OR public page enabled OR valid federation auth
        $currentUser = $this->getUser();
        $isOwnUser = $currentUser && $currentUser->getId() === $user->getId();

        if (!$isOwnUser) {
            $publicEnabled = $this->settingsService->getSettingValue('profile.public_page_enabled', '1') === '1' && $this->settingsService->getSettingValue('profile.public_page_show_photo', '0') === '1';
            
            if (!$publicEnabled) {
                // Try federation auth
                $this->cqContactService->setUser($user);
                $isAuthenticated = $this->checkFederationAuth($request);
                if (!$isAuthenticated) {
                    throw $this->createNotFoundException();
                }
            }
        }

        $wantLastUpdated = $request->query->get('lastUpdated') === '1';
        if ($wantLastUpdated) {
            $lastUpdated = $this->settingsService->getSettingValue('profile.public_photo_last_updated');
            if (!$lastUpdated) {
                // Backward compat: resolve from file record if not yet cached
                $photoFileId = $this->settingsService->getSettingValue('profile.photo_project_file_id');
                if ($photoFileId) {
                    $this->projectFileService->setUser($user);
                    $file = $this->projectFileService->findById($photoFileId);
                    if ($file) {
                        $lastUpdated = $file->getUpdatedAt()->format('Y-m-d H:i:s');
                        $this->settingsService->setSetting('profile.public_photo_last_updated', $lastUpdated);
                    }
                }
            }
            return new JsonResponse(['last_updated' => $lastUpdated ?: null]);
        }

        // Serve from pre-computed path settings (stored at upload time)
        $originalPath = $this->settingsService->getSettingValue('profile.public_photo_file_path');
        $thumbIconSet = $this->settingsService->getSettingValue('profile.public_photo_thumb_icon_file_path');

        // Backward compat: resolve + cache paths if not yet stored
        if (!$originalPath || !$thumbIconSet) {
            $photoFileId = $this->settingsService->getSettingValue('profile.photo_project_file_id');
            if (!$photoFileId) {
                throw $this->createNotFoundException();
            }
            $this->projectFileService->setUser($user);
            $originalPath = $this->projectFileService->getFileAbsolutePath($photoFileId);
            if ($originalPath && file_exists($originalPath)) {
                $thumbPath = $this->projectFileService->generateThumbnail($photoFileId);
                $thumbIconPath = $this->projectFileService->generateThumbnailIcon($photoFileId);
                $this->settingsService->setSetting('profile.public_photo_file_path', $originalPath);
                $this->settingsService->setSetting('profile.public_photo_thumb_file_path', $thumbPath ?: $originalPath);
                $this->settingsService->setSetting('profile.public_photo_thumb_icon_file_path', $thumbIconPath ?: $originalPath);
                $this->settingsService->setSetting('profile.public_photo_last_updated', date('Y-m-d H:i:s'));
            }
        }

        $wantFull = $request->query->get('full') === '1';
        $servePath = $wantFull
            ? $originalPath
            : ($this->settingsService->getSettingValue('profile.public_photo_thumb_file_path') ?: $originalPath);
        
        $wantIcon = $request->query->get('icon') === '1';
        if ($wantIcon) {
            $servePath = $this->settingsService->getSettingValue('profile.public_photo_thumb_icon_file_path') ?: $originalPath;
        }
        

        if (!$servePath || !file_exists($servePath)) {
            throw $this->createNotFoundException();
        }

        $mimeType = mime_content_type($servePath) ?: 'image/jpeg';

        $response = new BinaryFileResponse($servePath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, basename($servePath));
        $response->headers->set('Content-Type', $mimeType);
        $response->headers->set('Cache-Control', 'public, max-age=3600');

        return $response;
    }

    /**
     * Federation user-profile endpoint: POST /{username}/api/federation/user-profile
     * Returns rich profile data for CQ Contact detail pages.
     */
    #[Route('/{username}/api/federation/user-profile', name: 'cq_profile_federation', methods: ['POST'], priority: -10)]
    public function federationUserProfile(Request $request, string $username): JsonResponse
    {
        try {
            $user = $this->resolveUser($username);
            if (!$user) {
                return $this->json(['success' => false, 'message' => 'Not found'], Response::HTTP_NOT_FOUND);
            }

            // Set user context
            $this->settingsService->setUser($user);
            $this->cqContactService->setUser($user);

            // Require federation auth
            if (!$this->checkFederationAuth($request)) {
                return $this->json(['success' => false, 'message' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
            }

            // Build response based on user's federation visibility settings
            $domain = $request->getHost();
            
            // Follower count
            $followerCount = 0;
            try {
                $this->followService->setUser($user);
                $followerCount = $this->followService->getFollowerCount();
            } catch (\Exception $e) {}

            $response = [
                'success' => true,
                'cq_contact_id' => $user->getId()->toRfc4122(),
                'username' => $username,
                'domain' => $domain,
                'follower_count' => $followerCount,
            ];

            // Bio
            $response['bio'] = $this->settingsService->getSettingValue('profile.bio');

            // Photo URL — only include if federation sharing is enabled
            $photoFileId = $this->settingsService->getSettingValue('profile.photo_project_file_id');
            $response['photo_url'] = ($photoFileId) ? ('https://' . $domain . '/' . $username . '/photo') : null;

            // Background image + overlay
            $customBgFileId = $this->settingsService->getSettingValue('profile.public_page_custom_bg_file_id');
            $bgOverlay = $this->settingsService->getSettingValue('profile.public_page_bg_overlay', '1') === '1';
            $response['background_url'] = !empty($customBgFileId) ? ('https://' . $domain . '/' . $username . '/background') : null;
            $response['bg_overlay'] = $bgOverlay;

            // Shared items and share groups — respect visibility toggles
            $showShareContent = $this->settingsService->getSettingValue('profile.public_page_show_share_content', '1') === '1';
            $showProfileContent = $this->settingsService->getSettingValue('profile.public_page_show_profile_content', '1') === '1';
            $showShares = $this->settingsService->getSettingValue('profile.public_page_show_shares', '1') === '1';
            try {
                $this->shareService->setUser($user);
                $this->shareGroupService->setUser($user);

                // Load share groups for federation (only if profile content toggle is on)
                $federationScopes = [CQShareGroupService::SCOPE_PUBLIC, CQShareGroupService::SCOPE_CQ_CONTACT];
                $shareGroups = [];
                if ($showProfileContent) {
                    $shareGroups = $this->shareGroupService->listActiveGroupsWithItems($federationScopes);
                }

                // Ungrouped shares (only if shares toggle is on)
                $sharedItems = [];
                if ($showShares) {
                    $sharedItems = $this->shareService->listActiveForFederation();

                    // Filter out grouped shares from ungrouped list
                    $groupedShareIds = $this->shareGroupService->getGroupedShareIds($federationScopes);
                    if (!empty($groupedShareIds)) {
                        $sharedItems = array_values(array_filter($sharedItems, fn($s) => !in_array($s['id'], $groupedShareIds)));
                    }

                    if ($showShareContent && !empty($sharedItems)) {
                        $sharedItems = $this->shareService->enrichSharesWithPreview($user, $username, $sharedItems);
                        $this->shareService->convertPreviewUrlsToAbsolute($sharedItems, $domain);
                    }
                }

                // Profile Content groups always get content previews (independent from show_share_content toggle)
                if (!empty($shareGroups)) {
                    foreach ($shareGroups as &$group) {
                        if (!empty($group['items'])) {
                            $group['items'] = $this->shareService->enrichSharesWithPreview($user, $username, $group['items']);
                            $this->shareService->convertPreviewUrlsToAbsolute($group['items'], $domain);
                        }
                    }
                    unset($group);
                }

                $response['shared_items'] = $sharedItems;
                $response['share_groups'] = $shareGroups;
            } catch (\Exception $e) {
                $response['shared_items'] = [];
                $response['share_groups'] = [];
            }
            $response['show_share_content'] = $showShareContent;

            // Spirits — mode: 0=off, 1=primary only, 2=all
            $spiritMode = (int) $this->settingsService->getSettingValue('profile.federation_show_spirits', '1');
            if ($spiritMode > 0) {
                try {
                    $allSpirits = $this->loadUserSpirits($user);
                    if ($spiritMode === 1) {
                        $response['spirits'] = array_values(array_filter($allSpirits, fn($s) => $s['isPrimary']));
                    } else {
                        $response['spirits'] = $allSpirits;
                    }
                } catch (\Exception $e) {
                    $response['spirits'] = [];
                }
            }

            return $this->json($response);
        } catch (\Exception $e) {
            $this->logger->error('CQProfileController::federationUserProfile error', [
                'error' => $e->getMessage(),
                'username' => $username,
            ]);
            return $this->json(['success' => false, 'message' => 'Internal error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // enrichSharesWithPreview() moved to CQShareService for DRY (used by both public profile and federation endpoints)

    /**
     * Load spirits data directly from user's database (no auth required).
     */
    private function loadUserSpirits(User $user): array
    {
        $userDb = $this->userDatabaseManager->getDatabaseConnection($user);

        // Get all spirits ordered by creation date
        $rows = $userDb->executeQuery('SELECT * FROM spirits ORDER BY created_at ASC')->fetchAllAssociative();
        if (!$rows) {
            return [];
        }

        // The first spirit is the primary one
        $primaryId = $rows[0]['id'] ?? null;

        $spirits = [];
        foreach ($rows as $row) {
            $spiritId = $row['id'];

            // Get level and experience from spirit_settings
            $level = 1;
            $experience = 0;
            $spiritColor = '#95ec86';

            $settings = $userDb->executeQuery(
                'SELECT key, value FROM spirit_settings WHERE spirit_id = ?',
                [$spiritId]
            )->fetchAllAssociative();

            foreach ($settings as $setting) {
                if ($setting['key'] === 'level') {
                    $level = (int) $setting['value'];
                } elseif ($setting['key'] === 'experience') {
                    $experience = (int) $setting['value'];
                } elseif ($setting['key'] === 'visualState') {
                    try {
                        $parsed = json_decode($setting['value'], true);
                        if (!empty($parsed['color'])) {
                            $spiritColor = $parsed['color'];
                        }
                    } catch (\Exception $e) {}
                }
            }

            $spirits[] = [
                'name' => $row['name'],
                'level' => $level,
                'experience' => $experience,
                'color' => $spiritColor,
                'isPrimary' => $spiritId === $primaryId,
            ];
        }

        return $spirits;
    }

    /**
     * Resolve a User entity from username.
     */
    private function resolveUser(string $username): ?User
    {
        return $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
    }

    /**
     * Check if the request has a valid CQ Contact API key for the target user.
     * Same pattern as CQShareController.
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
