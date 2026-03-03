<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\CqContactService;
use App\Service\CQShareService;
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
        private readonly CQShareService $shareService,
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly ParameterBagInterface $params,
        private readonly LoggerInterface $logger
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

        // Set user context for settings service
        $this->settingsService->setUser($user);

        // Check if public page is enabled
        $publicEnabled = $this->settingsService->getSettingValue('profile.public_page_enabled', '0');
        if ($publicEnabled !== '1') {
            throw $this->createNotFoundException();
        }

        // Gather profile data
        $bio = $this->settingsService->getSettingValue('profile.bio');
        $photoFileId = $this->settingsService->getSettingValue('profile.photo_project_file_id');
        $showPhoto = $this->settingsService->getSettingValue('profile.public_page_show_photo', '1') === '1';
        $showShares = $this->settingsService->getSettingValue('profile.public_page_show_shares', '1') === '1';
        $showShareContent = $this->settingsService->getSettingValue('profile.public_page_show_share_content', '1') === '1';
        $spiritMode = (int) $this->settingsService->getSettingValue('profile.public_page_show_spirits', '1');
        $pageTheme = $this->settingsService->getSettingValue('profile.public_page_theme', '');

        // Get public shares (scope=0) if enabled
        $publicShares = [];
        if ($showShares) {
            try {
                $this->shareService->setUser($user);
                $publicShares = $this->shareService->listPublicShares();

                // Enrich shares with content preview data if enabled
                if ($showShareContent && !empty($publicShares)) {
                    $publicShares = $this->enrichSharesWithPreview($user, $username, $publicShares);
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
                    // Primary only (first/oldest spirit, no star)
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

        return $this->render('profile/public.html.twig', [
            'profile_username' => $username,
            'profile_domain' => $request->getHost(),
            'profile_bio' => $bio,
            'profile_has_photo' => $showPhoto && !empty($photoFileId),
            'profile_shares' => $publicShares,
            'profile_show_shares' => $showShares,
            'profile_show_share_content' => $showShareContent,
            'profile_spirits' => $spirits,
            'profile_theme' => $pageTheme,
        ]);
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

        // Check access: public page enabled OR valid federation auth
        $publicEnabled = $this->settingsService->getSettingValue('profile.public_page_enabled', '0') === '1';
        $federationShowPhoto = $this->settingsService->getSettingValue('profile.federation_show_photo', '1') === '1';
        
        $isAuthenticated = false;
        if (!$publicEnabled) {
            // Try federation auth
            $this->cqContactService->setUser($user);
            $isAuthenticated = $this->checkFederationAuth($request);
            if (!$isAuthenticated || !$federationShowPhoto) {
                throw $this->createNotFoundException();
            }
        }

        // Get photo file ID from settings
        $photoFileId = $this->settingsService->getSettingValue('profile.photo_project_file_id');
        if (!$photoFileId) {
            throw $this->createNotFoundException();
        }

        // Look up the file in user's database
        $userDb = $this->userDatabaseManager->getDatabaseConnection($user);
        $file = $userDb->executeQuery(
            'SELECT * FROM project_file WHERE id = ?',
            [$photoFileId]
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
            
            $response = [
                'success' => true,
                'username' => $username,
                'domain' => $domain,
            ];

            // Bio — only include if federation sharing is enabled
            $showBio = $this->settingsService->getSettingValue('profile.federation_show_bio', '1') === '1';
            $response['bio'] = $showBio ? $this->settingsService->getSettingValue('profile.bio') : null;

            // Photo URL — only include if federation sharing is enabled
            $showPhoto = $this->settingsService->getSettingValue('profile.federation_show_photo', '1') === '1';
            $photoFileId = $this->settingsService->getSettingValue('profile.photo_project_file_id');
            $response['photo_url'] = ($showPhoto && $photoFileId) ? ('https://' . $domain . '/' . $username . '/photo') : null;

            // Shared items (always include for authenticated contacts)
            try {
                $this->shareService->setUser($user);
                $response['shared_items'] = $this->shareService->listActiveForFederation();
            } catch (\Exception $e) {
                $response['shared_items'] = [];
            }

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

    /**
     * Enrich share records with content preview data for the public profile page.
     * - Images: adds 'preview_type' => 'image', 'preview_url' for inline display
     * - Text/Markdown: adds 'preview_type' => 'text', 'preview_content' with file content
     * - Memory Packs: adds 'preview_type' => 'graph', 'preview_graph_url' for 3D visualization
     */
    private function enrichSharesWithPreview(User $user, string $username, array $shares): array
    {
        $userDb = $this->userDatabaseManager->getDatabaseConnection($user);
        $projectDir = $this->params->get('kernel.project_dir');

        foreach ($shares as &$share) {
            try {
                if ($share['source_type'] === 'cqmpack') {
                    $share['preview_type'] = 'graph';
                    $share['preview_graph_url'] = '/' . $username . '/share/' . $share['share_url'] . '/graph';
                    continue;
                }

                // Look up source file to get mime type and path
                $file = $userDb->executeQuery(
                    'SELECT * FROM project_file WHERE id = ?',
                    [$share['source_id'] ?? '']
                )->fetchAssociative();

                if (!$file) continue;

                $mimeType = $file['mime_type'] ?? '';
                $fileName = $file['name'] ?? '';
                $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                // Image files
                if (str_starts_with($mimeType, 'image/')) {
                    $share['preview_type'] = 'image';
                    $share['preview_url'] = '/' . $username . '/share/' . $share['share_url'] . '?inline=1';
                    continue;
                }

                // Text/Markdown files
                if (in_array($ext, ['txt', 'md', 'markdown']) || str_starts_with($mimeType, 'text/')) {
                    $basePath = $projectDir . '/var/user_data/' . $user->getId() . '/p/' . $file['project_id'];
                    $relativePath = ltrim($file['path'] ?? '', '/');
                    $filePath = $relativePath
                        ? $basePath . '/' . $relativePath . '/' . $file['name']
                        : $basePath . '/' . $file['name'];

                    if (file_exists($filePath)) {
                        $content = file_get_contents($filePath, false, null, 0, 10000); // Limit to 10KB
                        $share['preview_type'] = 'text';
                        $share['preview_content'] = $content;
                        $share['preview_ext'] = $ext;
                    }
                }
            } catch (\Exception $e) {
                $this->logger->debug('CQProfileController: Failed to enrich share preview', [
                    'share_id' => $share['id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $shares;
    }

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
