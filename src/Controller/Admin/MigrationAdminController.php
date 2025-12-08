<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\MigrationRequest;
use App\Service\MigrationService;
use App\Service\BackupManager;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Psr\Log\LoggerInterface;

/**
 * Admin Migration Controller
 * 
 * Handles admin operations for incoming migration requests.
 */
#[Route('/admin/migrations')]
#[IsGranted('ROLE_ADMIN')]
class MigrationAdminController extends AbstractController
{
    public function __construct(
        private MigrationService $migrationService,
        private BackupManager $backupManager,
        private UserRepository $userRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {}

    /**
     * Admin migration dashboard
     */
    #[Route('', name: 'admin_migrations', methods: ['GET'])]
    public function index(): Response
    {
        $pendingRequests = $this->migrationService->getPendingIncomingRequests();
        $allRequests = $this->migrationService->getAllIncomingRequests();

        return $this->render('admin/migrations/index.html.twig', [
            'pending_requests' => $pendingRequests,
            'all_requests' => $allRequests,
        ]);
    }

    /**
     * Get migration requests as JSON (for AJAX refresh)
     */
    #[Route('/api/list', name: 'admin_migrations_api_list', methods: ['GET'])]
    public function apiList(): JsonResponse
    {
        $pendingRequests = $this->migrationService->getPendingIncomingRequests();
        $allRequests = $this->migrationService->getAllIncomingRequests();

        return $this->json([
            'success' => true,
            'pending' => array_map(fn($r) => $r->toArray(), $pendingRequests),
            'all' => array_map(fn($r) => $r->toArray(), $allRequests),
        ]);
    }

    /**
     * Get details of a specific migration request
     */
    #[Route('/api/{id}', name: 'admin_migrations_api_detail', methods: ['GET'])]
    public function apiDetail(string $id): JsonResponse
    {
        $request = $this->migrationService->findMigrationRequest($id);

        if (!$request) {
            return $this->json([
                'success' => false,
                'error' => 'Migration request not found',
            ], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'migration' => $request->toArray(),
        ]);
    }

    /**
     * Accept a migration request
     */
    #[Route('/api/{id}/accept', name: 'admin_migrations_api_accept', methods: ['POST'])]
    public function apiAccept(string $id): JsonResponse
    {
        /** @var User $admin */
        $admin = $this->getUser();

        $request = $this->migrationService->findMigrationRequest($id);

        if (!$request) {
            return $this->json([
                'success' => false,
                'error' => 'Migration request not found',
            ], Response::HTTP_NOT_FOUND);
        }

        if (!$request->isIncoming()) {
            return $this->json([
                'success' => false,
                'error' => 'Can only accept incoming migration requests',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->migrationService->acceptMigration($request, $admin);

            $this->logger->info('Migration request accepted by admin', [
                'request_id' => $id,
                'admin_id' => (string) $admin->getId(),
            ]);

            return $this->json($result);

        } catch (\Exception $e) {
            $this->logger->error('Failed to accept migration request', [
                'request_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Reject a migration request
     */
    #[Route('/api/{id}/reject', name: 'admin_migrations_api_reject', methods: ['POST'])]
    public function apiReject(string $id, Request $httpRequest): JsonResponse
    {
        /** @var User $admin */
        $admin = $this->getUser();

        $request = $this->migrationService->findMigrationRequest($id);

        if (!$request) {
            return $this->json([
                'success' => false,
                'error' => 'Migration request not found',
            ], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($httpRequest->getContent(), true);
        $reason = $data['reason'] ?? null;

        try {
            $result = $this->migrationService->rejectMigration($request, $admin, $reason);

            $this->logger->info('Migration request rejected by admin', [
                'request_id' => $id,
                'admin_id' => (string) $admin->getId(),
                'reason' => $reason,
            ]);

            return $this->json($result);

        } catch (\Exception $e) {
            $this->logger->error('Failed to reject migration request', [
                'request_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Download backup from source server and restore user
     * 
     * This is called after accepting a migration to complete the transfer.
     */
    #[Route('/api/{id}/transfer', name: 'admin_migrations_api_transfer', methods: ['POST'])]
    public function apiTransfer(string $id): JsonResponse
    {
        /** @var User $admin */
        $admin = $this->getUser();

        $migrationRequest = $this->migrationService->findMigrationRequest($id);

        if (!$migrationRequest) {
            return $this->json([
                'success' => false,
                'error' => 'Migration request not found',
            ], Response::HTTP_NOT_FOUND);
        }

        if (!$migrationRequest->isAccepted()) {
            return $this->json([
                'success' => false,
                'error' => 'Migration must be accepted before transfer',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Download backup from source server
            // Use username from migration request for federation route
            $backupUrl = 'https://' . $migrationRequest->getSourceDomain() 
                . '/' . $migrationRequest->getUsername()
                . '/api/federation/migration-backup?token=' . $migrationRequest->getMigrationToken();

            $this->logger->info('Starting backup download for migration', [
                'request_id' => $id,
                'source_domain' => $migrationRequest->getSourceDomain(),
            ]);

            // Update status
            $migrationRequest->setStatus(MigrationRequest::STATUS_TRANSFERRING);
            $this->entityManager->flush();

            // Download the backup file
            $tempFile = sys_get_temp_dir() . '/migration_' . $migrationRequest->getId() . '.citadel';
            
            $ch = curl_init($backupUrl);
            $fp = fopen($tempFile, 'wb');
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3600); // 1 hour timeout for large files
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For dev environments
            $success = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fp);

            if (!$success || $httpCode !== 200) {
                @unlink($tempFile);
                throw new \RuntimeException('Failed to download backup from source server');
            }

            $this->logger->info('Backup downloaded successfully', [
                'request_id' => $id,
                'file_size' => filesize($tempFile),
            ]);

            // Restore the backup (creates new user with same UUID)
            $restoredUser = $this->restoreMigratedUser($migrationRequest, $tempFile);

            // Clean up temp file
            @unlink($tempFile);

            // Update migration status
            $migrationRequest->setStatus(MigrationRequest::STATUS_COMPLETED);
            $migrationRequest->setCompletedAt(new \DateTime());
            $this->entityManager->flush();

            // Notify source server that migration is complete
            $this->notifySourceServerComplete($migrationRequest);

            $this->logger->info('Migration transfer completed', [
                'request_id' => $id,
                'new_user_id' => (string) $restoredUser->getId(),
            ]);

            return $this->json([
                'success' => true,
                'message' => 'Migration completed successfully',
                'user_id' => (string) $restoredUser->getId(),
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Migration transfer failed', [
                'request_id' => $id,
                'error' => $e->getMessage(),
            ]);

            $migrationRequest->setStatus(MigrationRequest::STATUS_FAILED);
            $migrationRequest->setErrorMessage($e->getMessage());
            $this->entityManager->flush();

            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Restore a migrated user from backup
     */
    private function restoreMigratedUser(MigrationRequest $request, string $backupPath): User
    {
        // Create new user with the same UUID
        $user = new User();
        
        // Use reflection to set the UUID (normally auto-generated)
        $reflection = new \ReflectionClass($user);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($user, $request->getUserId());

        $user->setUsername($request->getUsername());
        $user->setEmail($request->getEmail() ?? $request->getUsername() . '@migrated.local');
        
        // SECURITY: Reset roles to ROLE_USER only
        $user->setRoles(['ROLE_USER']);
        
        // Set a random password - user must reset it
        $user->setPassword(bin2hex(random_bytes(32)));
        $user->setRequirePasswordChange(true);

        // Generate database path
        $databasePath = md5((string) $user->getId()) . '.db';
        $user->setDatabasePath($databasePath);

        // Persist the user
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Restore the user's database from backup
        $this->backupManager->restoreBackup($user, $backupPath);

        $this->logger->info('Migrated user restored', [
            'user_id' => (string) $user->getId(),
            'username' => $user->getUsername(),
        ]);

        return $user;
    }

    /**
     * Notify source server that migration is complete
     */
    private function notifySourceServerComplete(MigrationRequest $request): void
    {
        $url = 'https://' . $request->getSourceDomain() 
            . '/' . $request->getUsername()
            . '/api/federation/migration-complete';

        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'migration_token' => $request->getMigrationToken(),
                'user_id' => (string) $request->getUserId(),
            ]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_exec($ch);
            curl_close($ch);

        } catch (\Exception $e) {
            $this->logger->error('Failed to notify source server of completion', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
