<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\MigrationRequest;
use App\Service\MigrationService;
use App\Service\BackupManager;
use App\Service\ContactUpdateService;
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
        private ContactUpdateService $contactUpdateService,
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

            $expectedSize = $migrationRequest->getBackupSize();
            
            $this->logger->info('Starting backup download for migration', [
                'request_id' => $id,
                'source_domain' => $migrationRequest->getSourceDomain(),
                'expected_size' => $expectedSize,
            ]);

            // Update status
            $migrationRequest->setStatus(MigrationRequest::STATUS_TRANSFERRING);
            $this->entityManager->flush();

            // Download the backup file with resumable download support
            $tempFile = sys_get_temp_dir() . '/migration_' . $migrationRequest->getId() . '.citadel';
            
            // Resumable download with retry logic
            $maxRetries = 10;
            $retryCount = 0;
            $chunkSize = 50 * 1024 * 1024; // 50MB chunks per request
            
            // Check if we have a partial download from previous attempt
            $downloadedBytes = file_exists($tempFile) ? filesize($tempFile) : 0;
            
            while ($retryCount < $maxRetries) {
                $this->logger->info('Download attempt', [
                    'retry' => $retryCount,
                    'downloaded_bytes' => $downloadedBytes,
                    'expected_size' => $expectedSize,
                ]);
                
                // Open file in append mode if resuming, otherwise create new
                $fp = fopen($tempFile, $downloadedBytes > 0 ? 'ab' : 'wb');
                if (!$fp) {
                    throw new \RuntimeException('Failed to open temp file for writing');
                }
                
                $ch = curl_init($backupUrl);
                curl_setopt($ch, CURLOPT_FILE, $fp);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
                curl_setopt($ch, CURLOPT_TIMEOUT, 600); // 10 minute timeout per chunk
                curl_setopt($ch, CURLOPT_BUFFERSIZE, 65536); // 64KB buffer
                
                // Request specific byte range if resuming
                if ($downloadedBytes > 0) {
                    $rangeEnd = $downloadedBytes + $chunkSize - 1;
                    curl_setopt($ch, CURLOPT_RANGE, "{$downloadedBytes}-{$rangeEnd}");
                    $this->logger->info('Resuming download from byte', [
                        'start' => $downloadedBytes,
                        'range_end' => $rangeEnd,
                    ]);
                } else {
                    // First request - get first chunk
                    $rangeEnd = $chunkSize - 1;
                    curl_setopt($ch, CURLOPT_RANGE, "0-{$rangeEnd}");
                }
                
                $success = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                $bytesDownloaded = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
                curl_close($ch);
                fclose($fp);
                
                // Check response
                if (!$success) {
                    $this->logger->warning('Download chunk failed', [
                        'error' => $curlError,
                        'http_code' => $httpCode,
                        'retry' => $retryCount,
                    ]);
                    $retryCount++;
                    sleep(2); // Wait before retry
                    continue;
                }
                
                // HTTP 200 = full file, HTTP 206 = partial content (range request)
                if ($httpCode !== 200 && $httpCode !== 206) {
                    $this->logger->warning('Unexpected HTTP code', [
                        'http_code' => $httpCode,
                        'retry' => $retryCount,
                    ]);
                    $retryCount++;
                    sleep(2);
                    continue;
                }
                
                // Update downloaded bytes count
                $downloadedBytes = filesize($tempFile);
                
                $this->logger->info('Chunk downloaded', [
                    'bytes_this_chunk' => $bytesDownloaded,
                    'total_downloaded' => $downloadedBytes,
                    'expected_size' => $expectedSize,
                ]);
                
                // Check if download is complete
                // HTTP 200 means server sent full file (no range support or complete)
                // Or if we've downloaded at least the expected size
                if ($httpCode === 200 || ($expectedSize > 0 && $downloadedBytes >= $expectedSize)) {
                    $this->logger->info('Download complete', [
                        'total_size' => $downloadedBytes,
                    ]);
                    break;
                }
                
                // If we got less than requested, we might be at the end
                if ($bytesDownloaded < $chunkSize) {
                    $this->logger->info('Download appears complete (received less than chunk size)', [
                        'total_size' => $downloadedBytes,
                    ]);
                    break;
                }
                
                // Reset retry count on successful chunk
                $retryCount = 0;
            }
            
            if ($retryCount >= $maxRetries) {
                @unlink($tempFile);
                throw new \RuntimeException('Failed to download backup after ' . $maxRetries . ' retries');
            }
            
            // Verify file size if we know expected size
            $finalSize = filesize($tempFile);
            if ($expectedSize > 0 && $finalSize < $expectedSize) {
                $this->logger->error('Downloaded file size mismatch', [
                    'expected' => $expectedSize,
                    'actual' => $finalSize,
                ]);
                @unlink($tempFile);
                throw new \RuntimeException("Downloaded file incomplete: got {$finalSize} bytes, expected {$expectedSize}");
            }

            $this->logger->info('Backup downloaded successfully', [
                'request_id' => $id,
                'file_size' => $finalSize,
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
            
            // Notify all contacts about the domain change
            $newDomain = $_SERVER['HTTP_HOST'] ?? 'unknown';
            $contactResults = $this->contactUpdateService->notifyAllContacts(
                $restoredUser,
                $migrationRequest->getSourceDomain(),
                $newDomain
            );

            $this->logger->info('Migration transfer completed', [
                'request_id' => $id,
                'new_user_id' => (string) $restoredUser->getId(),
                'contact_notifications' => $contactResults,
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
        // Extract password hash from backup before restoring
        $passwordHash = $this->extractPasswordFromBackup($backupPath);
        
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
        
        // Use original password hash from backup if available
        if ($passwordHash) {
            $user->setPassword($passwordHash);
            $user->setRequirePasswordChange(false);
        } else {
            // Fallback: Set a random password - user must reset it
            $user->setPassword(bin2hex(random_bytes(32)));
            $user->setRequirePasswordChange(true);
        }

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
     * Extract password hash from backup's migration_metadata.json
     */
    private function extractPasswordFromBackup(string $backupPath): ?string
    {
        try {
            $zip = new \ZipArchive();
            if ($zip->open($backupPath) !== true) {
                return null;
            }
            
            // Read migration metadata
            $metadataJson = $zip->getFromName('migration_metadata.json');
            $zip->close();
            
            if (!$metadataJson) {
                $this->logger->warning('No migration_metadata.json found in backup');
                return null;
            }
            
            $metadata = json_decode($metadataJson, true);
            if (!$metadata || !isset($metadata['password_hash'])) {
                $this->logger->warning('No password_hash in migration metadata');
                return null;
            }
            
            return $metadata['password_hash'];
        } catch (\Exception $e) {
            $this->logger->warning('Failed to extract password from backup', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
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
