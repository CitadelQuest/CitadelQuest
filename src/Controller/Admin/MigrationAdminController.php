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
            // Download backup from source server using chunked transfer
            $baseUrl = 'https://' . $migrationRequest->getSourceDomain() 
                . '/' . $migrationRequest->getUsername();
            $token = $migrationRequest->getMigrationToken();

            // Update status
            $migrationRequest->setStatus(MigrationRequest::STATUS_TRANSFERRING);
            $this->entityManager->flush();

            // Step 1: Request the source server to prepare chunks
            $prepareUrl = $baseUrl . '/api/federation/migration-backup-prepare';
            
            $ch = curl_init($prepareUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['token' => $token]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 min timeout for preparation
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            
            $prepareResponse = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if (!$prepareResponse || $httpCode !== 200) {
                throw new \RuntimeException('Failed to prepare backup chunks: ' . ($curlError ?: 'HTTP ' . $httpCode));
            }
            
            $manifest = json_decode($prepareResponse, true);
            if (!$manifest || !$manifest['success']) {
                throw new \RuntimeException('Failed to prepare backup: ' . ($manifest['error'] ?? 'Unknown error'));
            }
            
            // Step 2: Download each chunk
            $tempFile = sys_get_temp_dir() . '/migration_' . $migrationRequest->getId() . '.citadel';
            $fp = fopen($tempFile, 'wb');
            if (!$fp) {
                throw new \RuntimeException('Failed to create temp file');
            }
            
            $totalChunks = $manifest['manifest']['total_chunks'];
            $maxRetries = 3;
            
            for ($chunkIndex = 0; $chunkIndex < $totalChunks; $chunkIndex++) {
                $expectedChunk = $manifest['manifest']['chunks'][$chunkIndex];
                $chunkUrl = $baseUrl . '/api/federation/migration-backup-chunk/' . $chunkIndex . '?token=' . urlencode($token);
                
                $retryCount = 0;
                $chunkSuccess = false;
                
                while ($retryCount < $maxRetries && !$chunkSuccess) {
                    // Download chunk to temp buffer
                    $chunkTempFile = sys_get_temp_dir() . '/migration_chunk_' . $migrationRequest->getId() . '_' . $chunkIndex . '.tmp';
                    $chunkFp = fopen($chunkTempFile, 'wb');
                    
                    $ch = curl_init($chunkUrl);
                    curl_setopt($ch, CURLOPT_FILE, $chunkFp);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 min per chunk
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
                    
                    $success = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curlError = curl_error($ch);
                    curl_close($ch);
                    fclose($chunkFp);
                    
                    if (!$success || $httpCode !== 200) {
                        $this->logger->warning('Chunk download failed', [
                            'chunk' => $chunkIndex,
                            'error' => $curlError,
                            'http_code' => $httpCode,
                        ]);
                        @unlink($chunkTempFile);
                        $retryCount++;
                        sleep(2);
                        continue;
                    }
                    
                    // Verify chunk size and hash
                    $downloadedSize = filesize($chunkTempFile);
                    $downloadedHash = md5_file($chunkTempFile);
                    
                    if ($downloadedSize !== $expectedChunk['size']) {
                        $this->logger->warning('Chunk size mismatch', [
                            'chunk' => $chunkIndex,
                            'expected' => $expectedChunk['size'],
                            'actual' => $downloadedSize,
                        ]);
                        @unlink($chunkTempFile);
                        $retryCount++;
                        sleep(2);
                        continue;
                    }
                    
                    if ($downloadedHash !== $expectedChunk['hash']) {
                        $this->logger->warning('Chunk hash mismatch', [
                            'chunk' => $chunkIndex,
                            'expected' => $expectedChunk['hash'],
                            'actual' => $downloadedHash,
                        ]);
                        @unlink($chunkTempFile);
                        $retryCount++;
                        sleep(2);
                        continue;
                    }
                    
                    // Append chunk to final file
                    $chunkData = file_get_contents($chunkTempFile);
                    fwrite($fp, $chunkData);
                    @unlink($chunkTempFile);
                    
                    $chunkSuccess = true;
                }
                
                if (!$chunkSuccess) {
                    fclose($fp);
                    @unlink($tempFile);
                    throw new \RuntimeException("Failed to download chunk {$chunkIndex} after {$maxRetries} retries");
                }
            }
            
            fclose($fp);
            
            // Step 3: Verify final file size
            $finalSize = filesize($tempFile);
            $expectedSize = $manifest['manifest']['total_size'];
            
            if ($finalSize !== $expectedSize) {
                $this->logger->error('Final file size mismatch', [
                    'expected' => $expectedSize,
                    'actual' => $finalSize,
                ]);
                @unlink($tempFile);
                throw new \RuntimeException("Downloaded file size mismatch: got {$finalSize} bytes, expected {$expectedSize}");
            }
            
            // Step 4: Cleanup chunks on source server
            $cleanupUrl = $baseUrl . '/api/federation/migration-backup-cleanup';
            $ch = curl_init($cleanupUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['token' => $token]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_exec($ch);
            curl_close($ch);

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
