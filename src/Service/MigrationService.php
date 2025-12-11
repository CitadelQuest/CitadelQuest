<?php

namespace App\Service;

use App\Entity\MigrationRequest;
use App\Entity\User;
use App\Entity\CqContact;
use App\Repository\MigrationRequestRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * MigrationService
 * 
 * Handles cross-instance user migration for CitadelQuest.
 * This service manages the complete migration workflow including:
 * - Initiating migration requests
 * - Processing incoming migration requests
 * - Server-to-server backup transfer
 * - Contact notification
 */
class MigrationService
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        private MigrationRequestRepository $migrationRequestRepository,
        private UserRepository $userRepository,
        private UserDatabaseManager $userDatabaseManager,
        private BackupManager $backupManager,
        private CqContactService $cqContactService,
        private HttpClientInterface $httpClient,
        private UserPasswordHasherInterface $passwordHasher,
        private ParameterBagInterface $params,
        private LoggerInterface $logger
    ) {}

    /**
     * Get the current server's domain
     */
    public function getCurrentDomain(): string
    {
        return $_SERVER['HTTP_HOST'] ?? 'localhost';
    }

    /**
     * Initiate a migration request to another CitadelQuest instance
     * 
     * @param User $user The user initiating migration
     * @param CqContact $targetContact The admin contact on the destination server
     * @param string $password User's password for confirmation
     * @param string|null $backupFilename Optional pre-staged backup filename
     * @return MigrationRequest The created migration request
     * @throws \Exception If validation fails
     */
    public function initiateMigration(User $user, CqContact $targetContact, string $password, ?string $backupFilename = null): MigrationRequest
    {
        // Verify password
        if (!$this->passwordHasher->isPasswordValid($user, $password)) {
            throw new \InvalidArgumentException('Invalid password');
        }

        // Check if user already has a pending migration
        $existingMigration = $this->migrationRequestRepository->findPendingOutgoingForUser($user->getId());
        if ($existingMigration) {
            throw new \RuntimeException('You already have a pending migration request');
        }

        // Check if user is already migrated
        if ($user->isMigrated()) {
            throw new \RuntimeException('This account has already been migrated');
        }

        // Get backup size - use pre-staged backup if provided, otherwise estimate
        if ($backupFilename) {
            $backupPath = $this->getBackupPath($user, $backupFilename);
            if (!file_exists($backupPath)) {
                throw new \InvalidArgumentException('Selected backup file not found');
            }
            $backupSize = filesize($backupPath);
        } else {
            $backupSize = $this->estimateBackupSize($user);
        }

        // Create migration request (outgoing)
        $migrationRequest = new MigrationRequest();
        $migrationRequest->setUserId($user->getId());
        $migrationRequest->setUsername($user->getUsername());
        $migrationRequest->setEmail($user->getEmail());
        $migrationRequest->setSourceDomain($this->getCurrentDomain());
        $migrationRequest->setTargetDomain($targetContact->getCqContactDomain());
        $migrationRequest->setBackupSize($backupSize);
        $migrationRequest->setBackupFilename($backupFilename);
        $migrationRequest->setDirection(MigrationRequest::DIRECTION_OUTGOING);
        $migrationRequest->setStatus(MigrationRequest::STATUS_PENDING);

        // Save locally first
        $this->migrationRequestRepository->save($migrationRequest, true);

        // Send request to target server
        try {
            $response = $this->sendMigrationRequest($migrationRequest, $targetContact);
            
            if (!$response['success']) {
                // Update status to failed
                $migrationRequest->setStatus(MigrationRequest::STATUS_FAILED);
                $migrationRequest->setErrorMessage($response['error'] ?? 'Request rejected by destination server');
                $this->entityManager->flush();
                
                throw new \RuntimeException($response['error'] ?? 'Migration request was rejected');
            }

            $this->logger->info('Migration request sent successfully', [
                'request_id' => (string) $migrationRequest->getId(),
                'target_domain' => $targetContact->getCqContactDomain()
            ]);

        } catch (\Exception $e) {
            $migrationRequest->setStatus(MigrationRequest::STATUS_FAILED);
            $migrationRequest->setErrorMessage($e->getMessage());
            $this->entityManager->flush();
            throw $e;
        }

        return $migrationRequest;
    }

    /**
     * Send migration request to target server via Federation API
     */
    private function sendMigrationRequest(MigrationRequest $request, CqContact $targetContact): array
    {
        $url = 'https://' . $targetContact->getCqContactDomain() 
            . '/' . $targetContact->getCqContactUsername() 
            . '/api/federation/migration-request';

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $targetContact->getCqContactApiKey(),
                ],
                'json' => [
                    'user_id' => (string) $request->getUserId(),
                    'username' => $request->getUsername(),
                    'email' => $request->getEmail(),
                    'source_domain' => $request->getSourceDomain(),
                    'backup_size' => $request->getBackupSize(),
                    'requested_at' => $request->getCreatedAt()->format(\DateTimeInterface::ATOM),
                ],
                'timeout' => 30,
            ]);

            return $response->toArray();

        } catch (\Exception $e) {
            $this->logger->error('Failed to send migration request', [
                'error' => $e->getMessage(),
                'target_domain' => $targetContact->getCqContactDomain()
            ]);
            
            return [
                'success' => false,
                'error' => 'Failed to connect to destination server: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Process incoming migration request from another server
     * 
     * @param array $data Request data from source server
     * @param User $targetAdmin The admin user who will handle this request
     * @return array Response data
     */
    public function processIncomingMigrationRequest(array $data, User $targetAdmin): array
    {
        // Verify target user is an admin
        if (!$targetAdmin->hasAdminRole()) {
            return [
                'success' => false,
                'error' => 'Migration requests can only be sent to administrators'
            ];
        }

        // Check if we already have a pending request for this user from this domain
        $existingRequest = $this->migrationRequestRepository->findByUserIdAndSourceDomain(
            $data['user_id'],
            $data['source_domain']
        );

        if ($existingRequest) {
            return [
                'success' => false,
                'error' => 'A migration request for this user is already pending'
            ];
        }

        // Check if user ID already exists on this server
        $existingUser = $this->userRepository->find(Uuid::fromString($data['user_id']));
        if ($existingUser) {
            return [
                'success' => false,
                'error' => 'A user with this ID already exists on this server'
            ];
        }

        // Create incoming migration request
        $migrationRequest = new MigrationRequest();
        $migrationRequest->setUserId(Uuid::fromString($data['user_id']));
        $migrationRequest->setUsername($data['username']);
        $migrationRequest->setEmail($data['email'] ?? null);
        $migrationRequest->setSourceDomain($data['source_domain']);
        $migrationRequest->setTargetDomain($this->getCurrentDomain());
        $migrationRequest->setBackupSize($data['backup_size'] ?? null);
        $migrationRequest->setDirection(MigrationRequest::DIRECTION_INCOMING);
        $migrationRequest->setStatus(MigrationRequest::STATUS_PENDING);
        $migrationRequest->setAdminId($targetAdmin->getId());

        $this->migrationRequestRepository->save($migrationRequest, true);

        $this->logger->info('Incoming migration request received', [
            'request_id' => (string) $migrationRequest->getId(),
            'source_domain' => $data['source_domain'],
            'username' => $data['username']
        ]);

        return [
            'success' => true,
            'request_id' => (string) $migrationRequest->getId(),
            'status' => 'pending',
            'message' => 'Migration request received, awaiting admin approval'
        ];
    }

    /**
     * Accept a migration request (admin action)
     */
    public function acceptMigration(MigrationRequest $request, User $admin): array
    {
        if (!$request->isPending()) {
            throw new \RuntimeException('This migration request is no longer pending');
        }

        if (!$request->isIncoming()) {
            throw new \RuntimeException('Can only accept incoming migration requests');
        }

        // Generate migration token
        $token = $request->generateMigrationToken();
        $request->setStatus(MigrationRequest::STATUS_ACCEPTED);
        $request->setAcceptedAt(new \DateTime());
        $request->setAdminId($admin->getId());

        $this->entityManager->flush();

        // Notify source server that migration was accepted
        $this->notifySourceServerAccepted($request);

        $this->logger->info('Migration request accepted', [
            'request_id' => (string) $request->getId(),
            'admin_id' => (string) $admin->getId()
        ]);

        return [
            'success' => true,
            'request_id' => (string) $request->getId(),
            'migration_token' => $token,
            'expires_at' => $request->getTokenExpiresAt()->format(\DateTimeInterface::ATOM)
        ];
    }

    /**
     * Reject a migration request (admin action)
     */
    public function rejectMigration(MigrationRequest $request, User $admin, ?string $reason = null): array
    {
        if (!$request->isPending()) {
            throw new \RuntimeException('This migration request is no longer pending');
        }

        $request->setStatus(MigrationRequest::STATUS_REJECTED);
        $request->setErrorMessage($reason ?? 'Rejected by administrator');
        $request->setAdminId($admin->getId());

        $this->entityManager->flush();

        // Notify source server that migration was rejected
        $this->notifySourceServerRejected($request, $reason);

        $this->logger->info('Migration request rejected', [
            'request_id' => (string) $request->getId(),
            'admin_id' => (string) $admin->getId(),
            'reason' => $reason
        ]);

        return [
            'success' => true,
            'message' => 'Migration request rejected'
        ];
    }

    /**
     * Get migration status for a user
     */
    public function getMigrationStatus(User $user): ?array
    {
        $migration = $this->migrationRequestRepository->findPendingOutgoingForUser($user->getId());
        
        if (!$migration) {
            return null;
        }

        return $migration->toArray();
    }

    /**
     * Cancel a migration request (pending, accepted, or transferring)
     */
    public function cancelMigration(User $user): bool
    {
        $migration = $this->migrationRequestRepository->findPendingOutgoingForUser($user->getId());
        
        if (!$migration) {
            return false;
        }
        
        // Can only cancel if not already completed/failed/rejected
        if (in_array($migration->getStatus(), [
            MigrationRequest::STATUS_COMPLETED,
            MigrationRequest::STATUS_FAILED,
            MigrationRequest::STATUS_REJECTED
        ])) {
            return false;
        }

        $migration->setStatus(MigrationRequest::STATUS_FAILED);
        $migration->setErrorMessage('Cancelled by user');
        $this->entityManager->flush();

        // TODO: Notify target server about cancellation

        return true;
    }

    /**
     * Get list of admin CQ Contacts that can receive migration requests
     */
    public function getAdminContacts(User $user): array
    {
        // Get all active CQ Contacts
        // Note: We can't know if they're admins on their server, 
        // but we return active contacts and let the destination server validate
        return $this->cqContactService->getActiveContacts();
    }

    /**
     * Estimate backup size for a user
     */
    private function estimateBackupSize(User $user): int
    {
        try {
            $dbPath = $this->userDatabaseManager->getUserDatabaseFullPath($user);
            $size = filesize($dbPath);

            // Add estimate for user_data directory
            $projectDir = $this->params->get('kernel.project_dir');
            $userDataDir = $projectDir . '/var/user_data/' . $user->getId();
            
            if (is_dir($userDataDir)) {
                $size += $this->getDirectorySize($userDataDir);
            }

            return $size;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get directory size recursively
     */
    private function getDirectorySize(string $path): int
    {
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $size += $file->getSize();
        }

        return $size;
    }

    /**
     * Notify source server that migration was accepted
     */
    private function notifySourceServerAccepted(MigrationRequest $request): void
    {
        // This will be called by the destination server to notify the source
        // Implementation will use Federation API
        // Use username from the migration request for the federation route
        $url = 'https://' . $request->getSourceDomain() 
            . '/' . $request->getUsername() 
            . '/api/federation/migration-accept';

        try {
            $this->httpClient->request('POST', $url, [
                'json' => [
                    'request_id' => (string) $request->getId(),
                    'user_id' => (string) $request->getUserId(),
                    'migration_token' => $request->getMigrationToken(),
                    'expires_at' => $request->getTokenExpiresAt()->format(\DateTimeInterface::ATOM),
                ],
                'timeout' => 30,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to notify source server of acceptance', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify source server that migration was rejected
     */
    private function notifySourceServerRejected(MigrationRequest $request, ?string $reason): void
    {
        $url = 'https://' . $request->getSourceDomain() 
            . '/' . $request->getUsername() 
            . '/api/federation/migration-reject';

        try {
            $this->httpClient->request('POST', $url, [
                'json' => [
                    'request_id' => (string) $request->getId(),
                    'user_id' => (string) $request->getUserId(),
                    'reason' => $reason,
                ],
                'timeout' => 30,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to notify source server of rejection', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get all pending incoming migration requests (for admin dashboard)
     */
    public function getPendingIncomingRequests(): array
    {
        return $this->migrationRequestRepository->findPendingIncoming();
    }

    /**
     * Get all incoming migration requests (for admin dashboard)
     */
    public function getAllIncomingRequests(): array
    {
        return $this->migrationRequestRepository->findAllIncoming();
    }

    /**
     * Find migration request by ID
     */
    public function findMigrationRequest(string $id): ?MigrationRequest
    {
        return $this->migrationRequestRepository->find(Uuid::fromString($id));
    }

    /**
     * Find migration request by token
     */
    public function findMigrationRequestByToken(string $token): ?MigrationRequest
    {
        return $this->migrationRequestRepository->findByToken($token);
    }

    /**
     * Find pending outgoing migration request by user ID
     */
    public function findMigrationRequestByUserId(Uuid $userId): ?MigrationRequest
    {
        return $this->migrationRequestRepository->findPendingOutgoingForUser($userId);
    }

    /**
     * Get the full path to a user's backup file
     */
    public function getBackupPath(User $user, string $filename): string
    {
        $backupDir = $this->params->get('app.backup_dir') . '/' . $user->getId();
        return $backupDir . '/' . $filename;
    }
}
