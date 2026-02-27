<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\HttpFoundation\RequestStack;
use App\Service\SystemSettingsService;
use App\Service\PasswordResetService;
use App\Service\BackupManager;
use App\Service\StorageService;
use App\CitadelVersion;

#[Route('/administration')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requestStack,
        private readonly SystemSettingsService $systemSettingsService,
        private readonly PasswordResetService $passwordResetService,
        private readonly BackupManager $backupManager,
        private readonly StorageService $storageService
    ) {}

    #[Route('/', name: 'app_admin_dashboard')]
    public function dashboard(): Response
    {
        $users = $this->userRepository->findAll();
        $userStats = [
            'total' => count($users),
            'admins' => count(array_filter($users, fn($user) => in_array('ROLE_ADMIN', $user->getRoles()))),
            'regular' => count(array_filter($users, fn($user) => !in_array('ROLE_ADMIN', $user->getRoles()))),
        ];

        // Get registration enabled status
        $registrationEnabled = $this->systemSettingsService->getBooleanValue('cq_register', true);
        $maxUsersAllowed = $this->systemSettingsService->getSettingValue('cq_register_max_users_allowed', '0');

        return $this->render('admin/dashboard.html.twig', [
            'users' => $users,
            'userStats' => $userStats,
            'registrationEnabled' => $registrationEnabled,
            'maxUsersAllowed' => (int) $maxUsersAllowed,
        ]);
    }

    #[Route('/users', name: 'app_admin_users')]
    public function users(): Response
    {
        $users = $this->userRepository->findBy([], ['username' => 'ASC']);

        // Compute total storage per user
        $userStorage = [];
        foreach ($users as $user) {
            $storageInfo = $this->storageService->getTotalUserStorageSize($user);
            $userStorage[(string) $user->getId()] = $storageInfo['formatted'];
        }
        
        return $this->render('admin/users.html.twig', [
            'users' => $users,
            'userStorage' => $userStorage,
        ]);
    }

    #[Route('/user/{id}/toggle-admin', name: 'app_admin_user_toggle_admin', methods: ['POST'])]
    public function toggleUserAdmin(User $user): JsonResponse
    {
        // Prevent removing admin role from the current user
        if ($user === $this->getUser()) {
            return $this->json([
                'success' => false,
                'message' => $this->translator->trans('admin.error.cannot_modify_self')
            ], 400);
        }

        $roles = $user->getRoles();
        $isAdmin = in_array('ROLE_ADMIN', $roles);

        if ($isAdmin) {
            // Remove admin role
            $roles = array_filter($roles, fn($role) => $role !== 'ROLE_ADMIN');
        } else {
            // Add admin role
            $roles[] = 'ROLE_ADMIN';
        }

        $user->setRoles(array_values($roles));
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'isAdmin' => !$isAdmin,
            'message' => $this->translator->trans(
                $isAdmin ? 'admin.users.admin_removed' : 'admin.users.admin_granted',
                ['%username%' => $user->getUsername()]
            )
        ]);
    }

    #[Route('/user/{id}/delete', name: 'app_admin_user_delete', methods: ['POST'])]
    public function deleteUser(User $user): JsonResponse
    {
        // Prevent deleting the current user
        if ($user === $this->getUser()) {
            return $this->json([
                'success' => false,
                'message' => $this->translator->trans('admin.error.cannot_delete_self')
            ], 400);
        }

        // Check if this is the last admin
        $adminUsers = $this->userRepository->findBy([]);
        $adminCount = count(array_filter($adminUsers, fn($u) => in_array('ROLE_ADMIN', $u->getRoles())));
        
        if ($adminCount <= 1 && in_array('ROLE_ADMIN', $user->getRoles())) {
            return $this->json([
                'success' => false,
                'message' => $this->translator->trans('admin.error.cannot_delete_last_admin')
            ], 400);
        }

        $username = $user->getUsername();
        
        try {
            $this->userRepository->remove($user, true);
            
            return $this->json([
                'success' => true,
                'message' => $this->translator->trans('admin.users.deleted', ['%username%' => $username])
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => $this->translator->trans('admin.error.delete_failed')
            ], 500);
        }
    }

    #[Route('/user/{id}/info', name: 'app_admin_user_info', methods: ['GET'])]
    public function userInfo(User $user): JsonResponse
    {
        return $this->json([
            'id' => $user->getId(),
            'username' => $user->getUsername(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'databasePath' => $user->getDatabasePath(),
            'isAdmin' => in_array('ROLE_ADMIN', $user->getRoles()),
        ]);
    }

    #[Route('/stats', name: 'app_admin_stats', methods: ['GET'])]
    public function stats(): JsonResponse
    {
        $users = $this->userRepository->findAll();
        
        return $this->json([
            'totalUsers' => count($users),
            'adminUsers' => count(array_filter($users, fn($user) => in_array('ROLE_ADMIN', $user->getRoles()))),
            'regularUsers' => count(array_filter($users, fn($user) => !in_array('ROLE_ADMIN', $user->getRoles()))),
            'serverInfo' => [
                'hostname' => $_SERVER['SERVER_NAME'] ?? 'localhost',
                'phpVersion' => PHP_VERSION,
                'symfonyVersion' => \Symfony\Component\HttpKernel\Kernel::VERSION,
            ]
        ]);
    }

    #[Route('/update/check/{step}', name: 'app_admin_update_check', methods: ['GET'])]
    public function updateCheck(int $step): JsonResponse
    {
        // Generate unique update script
        $uuid = Uuid::v4();
        $updateToken = bin2hex(random_bytes(32));
        $scriptName = sprintf('update-%s.php', $uuid->toRfc4122());
        
        // Get paths
        $projectDir = $this->getParameter('kernel.project_dir');
        $templatePath = $projectDir . '/public/.update';
        $scriptPath = $projectDir . '/public/' . $scriptName;
        
        // Check if update template exists
        if (!file_exists($templatePath)) {
            return $this->json([
                'success' => false,
                'message' => 'Update template not found',
            ]);
        }
        
        // Read template and add security token
        $content = file_get_contents($templatePath);
        $content = "<?php\ndefine('CITADEL_UPDATE_TOKEN', '{$updateToken}');\n" .
                  "define('CITADEL_UPDATE_SCRIPT', __FILE__);\n" . 
                  "define('CITADEL_UPDATE_STEP', {$step});\n" . $content;
        
        // Write the unique update script
        file_put_contents($scriptPath, $content);
        chmod($scriptPath, 0644);
        
        // Store token in session for verification
        $this->requestStack->getSession()->set('update_token', $updateToken);
        
        // Return the script URL for redirect
        return $this->json([
            'success' => true,
            'redirect_url' => '/' . $scriptName,
            'message' => 'Update script generated successfully',
        ]);
    }

    #[Route('/settings/registration/toggle', name: 'app_admin_toggle_registration', methods: ['POST'])]
    public function toggleRegistration(): JsonResponse
    {
        $currentValue = $this->systemSettingsService->getBooleanValue('cq_register', true);
        $newValue = !$currentValue;
        
        $this->systemSettingsService->setBooleanValue('cq_register', $newValue);
        
        return $this->json([
            'success' => true,
            'enabled' => $newValue,
            'message' => $this->translator->trans(
                $newValue ? 'admin.settings.registration_enabled' : 'admin.settings.registration_disabled'
            )
        ]);
    }

    #[Route('/settings/registration/max-users', name: 'app_admin_set_max_users', methods: ['POST'])]
    public function setMaxUsers(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $maxUsers = max(0, (int) ($data['maxUsers'] ?? 0));
        
        $this->systemSettingsService->setSetting('cq_register_max_users_allowed', (string) $maxUsers);
        
        return $this->json([
            'success' => true,
            'maxUsers' => $maxUsers,
            'message' => $this->translator->trans(
                'admin.settings.max_users_updated',
                ['%count%' => $maxUsers === 0 ? 'unlimited' : $maxUsers]
            )
        ]);
    }

    #[Route('/user/{id}/reset-password', name: 'app_admin_user_reset_password', methods: ['POST'])]
    public function resetUserPassword(User $user): JsonResponse
    {
        // Prevent resetting own password this way
        if ($user === $this->getUser()) {
            return $this->json([
                'success' => false,
                'message' => $this->translator->trans('admin.error.cannot_reset_own_password')
            ], 400);
        }

        try {
            $admin = $this->getUser();
            $temporaryPassword = $this->passwordResetService->resetUserPassword($user, $admin);
            
            return $this->json([
                'success' => true,
                'temporaryPassword' => $temporaryPassword,
                'message' => $this->translator->trans('admin.users.password_reset_success', [
                    '%username%' => $user->getUsername()
                ])
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => $this->translator->trans('admin.error.password_reset_failed')
            ], 500);
        }
    }

    #[Route('/update-available', name: 'app_admin_update_available', methods: ['GET'])]
    public function updateAvailable(): JsonResponse
    {
        $projectDir = $this->getParameter('kernel.project_dir');
        $cacheFile = $projectDir . '/var/cache/update_check.json';
        $cacheTtl = 3600; // 1 hour

        // Check cache first
        if (file_exists($cacheFile)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if ($cached && isset($cached['checked_at']) && (time() - $cached['checked_at']) < $cacheTtl) {
                return $this->json($cached['data']);
            }
        }

        try {
            $currentVersion = CitadelVersion::VERSION;

            // Same logic as .update checkLatestVersion()
            $apiUrl = 'https://api.github.com/repos/CitadelQuest/CitadelQuest/releases';
            $opts = [
                'http' => [
                    'method' => 'GET',
                    'header' => [
                        'User-Agent: CitadelQuest-Updater',
                        'Accept: application/vnd.github.v3+json'
                    ],
                    'timeout' => 10
                ]
            ];
            $context = stream_context_create($opts);
            $response = @file_get_contents($apiUrl, false, $context);

            if ($response === false) {
                $data = ['updateAvailable' => false, 'error' => 'Failed to connect to GitHub'];
                return $this->json($data);
            }

            $releases = json_decode($response, true);
            if (empty($releases)) {
                $data = ['updateAvailable' => false, 'currentVersion' => $currentVersion];
                $this->cacheUpdateCheck($cacheFile, $data);
                return $this->json($data);
            }

            // Get first non-test release
            $latestVersion = null;
            foreach ($releases as $release) {
                $tag = $release['tag_name'] ?? '';
                if (stripos($tag, 'test') !== false) continue;
                $latestVersion = $tag;
                break;
            }

            if (!$latestVersion) {
                $data = ['updateAvailable' => false, 'currentVersion' => $currentVersion];
                $this->cacheUpdateCheck($cacheFile, $data);
                return $this->json($data);
            }

            $updateAvailable = version_compare(
                ltrim($latestVersion, 'v'),
                ltrim($currentVersion, 'v'),
                '>'
            );

            $data = [
                'updateAvailable' => $updateAvailable,
                'currentVersion' => $currentVersion,
                'latestVersion' => $latestVersion,
            ];

            $this->cacheUpdateCheck($cacheFile, $data);
            return $this->json($data);

        } catch (\Exception $e) {
            return $this->json([
                'updateAvailable' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function cacheUpdateCheck(string $cacheFile, array $data): void
    {
        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($cacheFile, json_encode([
            'checked_at' => time(),
            'data' => $data
        ]));
    }

    #[Route('/system-backups', name: 'app_admin_system_backups')]
    public function systemBackups(): Response
    {
        $backups = $this->backupManager->getSystemBackups();
        
        // Calculate total size
        $totalSize = array_sum(array_column($backups, 'size'));
        
        return $this->render('admin/system-backups.html.twig', [
            'backups' => $backups,
            'totalSize' => $totalSize,
        ]);
    }

    #[Route('/system-backups/delete/{backupName}', name: 'app_admin_system_backup_delete', methods: ['POST'])]
    public function deleteSystemBackup(string $backupName): JsonResponse
    {
        try {
            $this->backupManager->deleteSystemBackup($backupName);
            
            return $this->json([
                'success' => true,
                'message' => $this->translator->trans('admin.system_backups.deleted', ['%name%' => $backupName])
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }
}
