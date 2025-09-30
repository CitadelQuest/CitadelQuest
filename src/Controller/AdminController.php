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

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requestStack,
        private readonly SystemSettingsService $systemSettingsService
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

        return $this->render('admin/dashboard.html.twig', [
            'users' => $users,
            'userStats' => $userStats,
            'registrationEnabled' => $registrationEnabled,
        ]);
    }

    #[Route('/users', name: 'app_admin_users')]
    public function users(): Response
    {
        $users = $this->userRepository->findBy([], ['username' => 'ASC']);
        
        return $this->render('admin/users.html.twig', [
            'users' => $users,
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
}
