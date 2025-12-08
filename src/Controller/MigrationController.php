<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\MigrationService;
use App\Service\CqContactService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * User Migration Controller
 * 
 * Handles user-facing migration operations from the Settings page.
 */
#[IsGranted('ROLE_USER')]
class MigrationController extends AbstractController
{
    public function __construct(
        private MigrationService $migrationService,
        private CqContactService $cqContactService,
    ) {}

    /**
     * Get migration status and available contacts for migration
     */
    #[Route('/api/migration/status', name: 'api_migration_status', methods: ['GET'])]
    public function getStatus(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        // Check if user is already migrated
        if ($user->isMigrated()) {
            return $this->json([
                'success' => true,
                'status' => 'migrated',
                'migrated_to' => $user->getMigratedTo(),
                'migrated_at' => $user->getMigratedAt()?->format(\DateTimeInterface::ATOM),
            ]);
        }

        // Get current migration status
        $migrationStatus = $this->migrationService->getMigrationStatus($user);

        // Get available contacts for migration
        $this->cqContactService->setUser($user);
        $contacts = $this->cqContactService->getActiveContacts();
        
        $contactsData = array_map(fn($contact) => [
            'id' => $contact->getId(),
            'username' => $contact->getCqContactUsername(),
            'domain' => $contact->getCqContactDomain(),
        ], $contacts);

        return $this->json([
            'success' => true,
            'status' => $migrationStatus ? $migrationStatus['status'] : null,
            'migration' => $migrationStatus,
            'available_contacts' => $contactsData,
        ]);
    }

    /**
     * Initiate migration to another CitadelQuest instance
     */
    #[Route('/api/migration/initiate', name: 'api_migration_initiate', methods: ['POST'])]
    public function initiate(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        // Check if user is already migrated
        if ($user->isMigrated()) {
            return $this->json([
                'success' => false,
                'error' => 'This account has already been migrated',
            ], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true);

        // Validate required fields
        if (empty($data['contact_id']) || empty($data['password'])) {
            return $this->json([
                'success' => false,
                'error' => 'Contact ID and password are required',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Find the target contact
        $this->cqContactService->setUser($user);
        $targetContact = $this->cqContactService->findById($data['contact_id']);

        if (!$targetContact) {
            return $this->json([
                'success' => false,
                'error' => 'Contact not found',
            ], Response::HTTP_NOT_FOUND);
        }

        if (!$targetContact->isActive()) {
            return $this->json([
                'success' => false,
                'error' => 'Contact is not active',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $migrationRequest = $this->migrationService->initiateMigration(
                $user,
                $targetContact,
                $data['password']
            );

            return $this->json([
                'success' => true,
                'message' => 'Migration request sent successfully',
                'migration' => $migrationRequest->toArray(),
            ]);

        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);

        } catch (\RuntimeException $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);

        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => 'An error occurred while initiating migration',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Cancel a pending migration request
     */
    #[Route('/api/migration/cancel', name: 'api_migration_cancel', methods: ['POST'])]
    public function cancel(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $cancelled = $this->migrationService->cancelMigration($user);

        if ($cancelled) {
            return $this->json([
                'success' => true,
                'message' => 'Migration request cancelled',
            ]);
        }

        return $this->json([
            'success' => false,
            'error' => 'No pending migration request to cancel',
        ], Response::HTTP_BAD_REQUEST);
    }
}
