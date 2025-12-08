<?php

namespace App\Api\Controller;

use App\Entity\User;
use App\Service\MigrationService;
use App\Service\CqContactService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Psr\Log\LoggerInterface;

/**
 * Federation Migration Controller
 * 
 * Handles cross-instance user migration requests via Federation API.
 * All endpoints are public (no Symfony auth) but require CQ Contact API key validation.
 */
class FederationMigrationController extends AbstractController
{
    public function __construct(
        private MigrationService $migrationService,
        private CqContactService $cqContactService,
        private NotificationService $notificationService,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {}

    /**
     * Receive migration request from another CitadelQuest instance
     * 
     * Called by source server when user initiates migration
     */
    #[Route('/{username}/api/federation/migration-request', name: 'api_federation_migration_request', methods: ['POST'])]
    public function migrationRequest(Request $request, string $username): Response
    {
        $ip = $request->headers->get('CF-Connecting-IP') ?? $request->headers->get('X-Forwarded-For') ?? $request->getClientIp();
        
        $this->logger->info('FederationMigrationController::migrationRequest - Incoming migration request', [
            'username' => $username,
            'client_ip' => $ip,
        ]);

        try {
            // Validate Authorization header
            $authHeader = $request->headers->get('Authorization');
            if (!$authHeader) {
                return $this->json([
                    'success' => false,
                    'error' => 'Authorization key required'
                ], Response::HTTP_UNAUTHORIZED);
            }

            $cqContactApiKey = str_replace('Bearer ', '', $authHeader);

            // Parse request body
            $data = json_decode($request->getContent(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->json([
                    'success' => false,
                    'error' => 'Invalid JSON in request body'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate required fields
            $requiredFields = ['user_id', 'username', 'source_domain'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    return $this->json([
                        'success' => false,
                        'error' => 'Missing required field: ' . $field
                    ], Response::HTTP_BAD_REQUEST);
                }
            }

            // Find target user by username
            $targetUser = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
            if (!$targetUser) {
                return $this->json([
                    'success' => false,
                    'error' => 'User not found'
                ], Response::HTTP_NOT_FOUND);
            }

            // SECURITY: Check if target user is an admin
            // If not admin, auto-reject (admin status is not public info)
            if (!$targetUser->hasAdminRole()) {
                $this->logger->info('FederationMigrationController::migrationRequest - Auto-rejecting: target user is not admin', [
                    'username' => $username,
                    'source_domain' => $data['source_domain']
                ]);
                
                return $this->json([
                    'success' => false,
                    'error' => 'Migration request rejected'
                ], Response::HTTP_FORBIDDEN);
            }

            // Validate CQ Contact API key
            $this->cqContactService->setUser($targetUser);
            $cqContact = $this->cqContactService->findByDomainAndApiKey($data['source_domain'], $cqContactApiKey);
            
            if (!$cqContact) {
                $this->logger->warning('FederationMigrationController::migrationRequest - Invalid API key or contact not found', [
                    'source_domain' => $data['source_domain']
                ]);
                return $this->json([
                    'success' => false,
                    'error' => 'Invalid authorization'
                ], Response::HTTP_UNAUTHORIZED);
            }

            // Process the migration request
            $result = $this->migrationService->processIncomingMigrationRequest($data, $targetUser);

            if ($result['success']) {
                // Send notification to admin
                $this->notificationService->createNotification(
                    $targetUser,
                    'New Migration Request',
                    sprintf('User %s from %s wants to migrate to this server', $data['username'], $data['source_domain']),
                    'info'
                );
            }

            $statusCode = $result['success'] ? Response::HTTP_CREATED : Response::HTTP_BAD_REQUEST;
            return $this->json($result, $statusCode);

        } catch (\Exception $e) {
            $this->logger->error('FederationMigrationController::migrationRequest - Exception', [
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
     * Receive migration acceptance notification from destination server
     * 
     * Called by destination server when admin accepts the migration
     */
    #[Route('/api/federation/migration-accept', name: 'api_federation_migration_accept_incoming', methods: ['POST'])]
    public function migrationAcceptIncoming(Request $request): Response
    {
        $this->logger->info('FederationMigrationController::migrationAcceptIncoming - Received acceptance notification');

        try {
            $data = json_decode($request->getContent(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->json([
                    'success' => false,
                    'error' => 'Invalid JSON'
                ], Response::HTTP_BAD_REQUEST);
            }

            $requiredFields = ['user_id', 'migration_token', 'expires_at'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field])) {
                    return $this->json([
                        'success' => false,
                        'error' => 'Missing required field: ' . $field
                    ], Response::HTTP_BAD_REQUEST);
                }
            }

            // Find the user and their outgoing migration request
            $user = $this->entityManager->getRepository(User::class)->find($data['user_id']);
            if (!$user) {
                return $this->json([
                    'success' => false,
                    'error' => 'User not found'
                ], Response::HTTP_NOT_FOUND);
            }

            // Update the migration request with the token
            $migrationRequest = $this->migrationService->findMigrationRequestByUserId($user->getId());
            if (!$migrationRequest || !$migrationRequest->isOutgoing()) {
                return $this->json([
                    'success' => false,
                    'error' => 'Migration request not found'
                ], Response::HTTP_NOT_FOUND);
            }

            $migrationRequest->setMigrationToken($data['migration_token']);
            $migrationRequest->setTokenExpiresAt(new \DateTime($data['expires_at']));
            $migrationRequest->setStatus('accepted');
            $migrationRequest->setAcceptedAt(new \DateTime());
            $this->entityManager->flush();

            // Notify user
            $this->notificationService->createNotification(
                $user,
                'Migration Accepted!',
                'Your migration request has been accepted. The transfer will begin shortly.',
                'success'
            );

            $this->logger->info('FederationMigrationController::migrationAcceptIncoming - Migration accepted', [
                'user_id' => (string) $user->getId()
            ]);

            return $this->json([
                'success' => true,
                'message' => 'Acceptance received'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('FederationMigrationController::migrationAcceptIncoming - Exception', [
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Internal server error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Receive migration rejection notification from destination server
     */
    #[Route('/api/federation/migration-reject', name: 'api_federation_migration_reject_incoming', methods: ['POST'])]
    public function migrationRejectIncoming(Request $request): Response
    {
        $this->logger->info('FederationMigrationController::migrationRejectIncoming - Received rejection notification');

        try {
            $data = json_decode($request->getContent(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->json([
                    'success' => false,
                    'error' => 'Invalid JSON'
                ], Response::HTTP_BAD_REQUEST);
            }

            if (!isset($data['user_id'])) {
                return $this->json([
                    'success' => false,
                    'error' => 'Missing user_id'
                ], Response::HTTP_BAD_REQUEST);
            }

            $user = $this->entityManager->getRepository(User::class)->find($data['user_id']);
            if (!$user) {
                return $this->json([
                    'success' => false,
                    'error' => 'User not found'
                ], Response::HTTP_NOT_FOUND);
            }

            $migrationRequest = $this->migrationService->findMigrationRequestByUserId($user->getId());
            if ($migrationRequest && $migrationRequest->isOutgoing()) {
                $migrationRequest->setStatus('rejected');
                $migrationRequest->setErrorMessage($data['reason'] ?? 'Rejected by administrator');
                $this->entityManager->flush();

                // Notify user
                $this->notificationService->createNotification(
                    $user,
                    'Migration Rejected',
                    'Your migration request was rejected: ' . ($data['reason'] ?? 'No reason provided'),
                    'error'
                );
            }

            return $this->json([
                'success' => true,
                'message' => 'Rejection received'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('FederationMigrationController::migrationRejectIncoming - Exception', [
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Internal server error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Serve backup file for migration transfer
     * 
     * Called by destination server to download the user's backup
     */
    #[Route('/api/federation/migration-backup', name: 'api_federation_migration_backup', methods: ['GET'])]
    public function migrationBackup(Request $request): Response
    {
        $this->logger->info('FederationMigrationController::migrationBackup - Backup download requested');

        try {
            $token = $request->query->get('token');
            if (!$token) {
                return $this->json([
                    'success' => false,
                    'error' => 'Migration token required'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Find migration request by token
            $migrationRequest = $this->migrationService->findMigrationRequestByToken($token);
            if (!$migrationRequest) {
                return $this->json([
                    'success' => false,
                    'error' => 'Invalid migration token'
                ], Response::HTTP_UNAUTHORIZED);
            }

            // Verify token is still valid
            if (!$migrationRequest->isTokenValid()) {
                return $this->json([
                    'success' => false,
                    'error' => 'Migration token expired'
                ], Response::HTTP_UNAUTHORIZED);
            }

            // Verify migration is in accepted state
            if (!$migrationRequest->isAccepted()) {
                return $this->json([
                    'success' => false,
                    'error' => 'Migration not in accepted state'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Get the user
            $user = $this->entityManager->getRepository(User::class)->find($migrationRequest->getUserId());
            if (!$user) {
                return $this->json([
                    'success' => false,
                    'error' => 'User not found'
                ], Response::HTTP_NOT_FOUND);
            }

            // Update status to transferring
            $migrationRequest->setStatus('transferring');
            $this->entityManager->flush();

            // Create backup and stream it
            $backupManager = $this->container->get('App\Service\BackupManager');
            $backupPath = $backupManager->createBackup($user);

            if (!file_exists($backupPath)) {
                return $this->json([
                    'success' => false,
                    'error' => 'Failed to create backup'
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $this->logger->info('FederationMigrationController::migrationBackup - Streaming backup', [
                'user_id' => (string) $user->getId(),
                'backup_size' => filesize($backupPath)
            ]);

            // Stream the backup file
            $response = new StreamedResponse(function() use ($backupPath) {
                $handle = fopen($backupPath, 'rb');
                while (!feof($handle)) {
                    echo fread($handle, 8192);
                    flush();
                }
                fclose($handle);
                
                // Clean up the temporary backup file
                @unlink($backupPath);
            });

            $response->headers->set('Content-Type', 'application/octet-stream');
            $response->headers->set('Content-Length', filesize($backupPath));
            $response->headers->set('Content-Disposition', 'attachment; filename="migration_backup.citadel"');

            return $response;

        } catch (\Exception $e) {
            $this->logger->error('FederationMigrationController::migrationBackup - Exception', [
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Internal server error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Receive contact domain update notification
     * 
     * Called by destination server to notify contacts about user's new domain
     */
    #[Route('/{username}/api/federation/contact-update', name: 'api_federation_contact_update', methods: ['POST'])]
    public function contactUpdate(Request $request, string $username): Response
    {
        $this->logger->info('FederationMigrationController::contactUpdate - Contact update received', [
            'username' => $username
        ]);

        try {
            // Validate Authorization
            $authHeader = $request->headers->get('Authorization');
            if (!$authHeader) {
                return $this->json([
                    'success' => false,
                    'error' => 'Authorization required'
                ], Response::HTTP_UNAUTHORIZED);
            }

            $cqContactApiKey = str_replace('Bearer ', '', $authHeader);

            $data = json_decode($request->getContent(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->json([
                    'success' => false,
                    'error' => 'Invalid JSON'
                ], Response::HTTP_BAD_REQUEST);
            }

            $requiredFields = ['user_id', 'old_domain', 'new_domain'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field])) {
                    return $this->json([
                        'success' => false,
                        'error' => 'Missing required field: ' . $field
                    ], Response::HTTP_BAD_REQUEST);
                }
            }

            // Find the user who owns this contact
            $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
            if (!$user) {
                return $this->json([
                    'success' => false,
                    'error' => 'User not found'
                ], Response::HTTP_NOT_FOUND);
            }

            $this->cqContactService->setUser($user);

            // Find the contact by old domain and API key
            $contact = $this->cqContactService->findByDomainAndApiKey($data['old_domain'], $cqContactApiKey);
            if (!$contact) {
                return $this->json([
                    'success' => false,
                    'error' => 'Contact not found or invalid authorization'
                ], Response::HTTP_NOT_FOUND);
            }

            // Update the contact's domain
            $oldUrl = $contact->getCqContactUrl();
            $newUrl = str_replace($data['old_domain'], $data['new_domain'], $oldUrl);
            
            $contact->setCqContactDomain($data['new_domain']);
            $contact->setCqContactUrl($newUrl);
            $this->cqContactService->updateContact($contact);

            // Notify user about the contact's migration
            $this->notificationService->createNotification(
                $user,
                'Contact Migrated',
                sprintf('%s has migrated to %s', $contact->getCqContactUsername(), $data['new_domain']),
                'info'
            );

            $this->logger->info('FederationMigrationController::contactUpdate - Contact domain updated', [
                'contact_id' => $contact->getId(),
                'old_domain' => $data['old_domain'],
                'new_domain' => $data['new_domain']
            ]);

            return $this->json([
                'success' => true,
                'message' => 'Contact domain updated'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('FederationMigrationController::contactUpdate - Exception', [
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Internal server error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Receive migration completion notification
     * 
     * Called by destination server when migration is fully complete
     */
    #[Route('/api/federation/migration-complete', name: 'api_federation_migration_complete', methods: ['POST'])]
    public function migrationComplete(Request $request): Response
    {
        $this->logger->info('FederationMigrationController::migrationComplete - Migration complete notification');

        try {
            $data = json_decode($request->getContent(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->json([
                    'success' => false,
                    'error' => 'Invalid JSON'
                ], Response::HTTP_BAD_REQUEST);
            }

            $token = $data['migration_token'] ?? null;
            if (!$token) {
                return $this->json([
                    'success' => false,
                    'error' => 'Migration token required'
                ], Response::HTTP_BAD_REQUEST);
            }

            $migrationRequest = $this->migrationService->findMigrationRequestByToken($token);
            if (!$migrationRequest) {
                return $this->json([
                    'success' => false,
                    'error' => 'Invalid migration token'
                ], Response::HTTP_UNAUTHORIZED);
            }

            // Get the user
            $user = $this->entityManager->getRepository(User::class)->find($migrationRequest->getUserId());
            if (!$user) {
                return $this->json([
                    'success' => false,
                    'error' => 'User not found'
                ], Response::HTTP_NOT_FOUND);
            }

            // Mark migration as completed
            $migrationRequest->setStatus('completed');
            $migrationRequest->setCompletedAt(new \DateTime());

            // Mark user as migrated
            $user->setMigratedTo($migrationRequest->getTargetDomain());
            $user->setMigratedAt(new \DateTime());
            $user->setMigrationStatus('migrated');

            $this->entityManager->flush();

            $this->logger->info('FederationMigrationController::migrationComplete - User marked as migrated', [
                'user_id' => (string) $user->getId(),
                'migrated_to' => $migrationRequest->getTargetDomain()
            ]);

            return $this->json([
                'success' => true,
                'message' => 'Migration completed'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('FederationMigrationController::migrationComplete - Exception', [
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Internal server error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
