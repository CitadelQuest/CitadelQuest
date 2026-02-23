<?php

namespace App\Api\Controller;

use App\Entity\User;
use App\Service\CQShareService;
use App\Service\CqContactService;
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
 * CQ Share Controller
 * 
 * Handles public/Federation share access (GET binary, POST metadata)
 * and authenticated API endpoints for managing shares.
 * 
 * @see /docs/CQ-SHARE.md
 */
class CQShareController extends AbstractController
{
    public function __construct(
        private readonly CQShareService $shareService,
        private readonly CqContactService $cqContactService,
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly ParameterBagInterface $params,
        private readonly LoggerInterface $logger
    ) {}

    // ========================================
    // Public / Federation share access
    // ========================================

    /**
     * Federation endpoint: list all active shares for an authenticated CQ Contact.
     * Returns share metadata (no binary) for building a contact's shared items view.
     */
    #[Route('/{username}/shares', name: 'cq_share_federation_list', methods: ['GET'], priority: -10)]
    public function federationListShares(Request $request, string $username): JsonResponse
    {
        try {
            $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
            if (!$user) {
                return $this->json(['success' => false, 'message' => 'Not found'], Response::HTTP_NOT_FOUND);
            }

            $this->shareService->setUser($user);
            $this->cqContactService->setUser($user);

            if (!$this->checkFederationAuth($request)) {
                return $this->json(['success' => false, 'message' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
            }

            $shares = $this->shareService->listActiveForFederation();

            return $this->json([
                'success' => true,
                'username' => $username,
                'shares' => $shares,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('CQShareController::federationListShares error', [
                'error' => $e->getMessage(),
                'username' => $username,
            ]);
            return $this->json(['success' => false, 'message' => 'Internal error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/{username}/share/{shareUrl}', name: 'cq_share_access', methods: ['GET', 'POST'], priority: -10)]
    public function accessByUrl(Request $request, string $username, string $shareUrl): Response
    {
        return $this->handleShareAccess($request, $username, 'url', $shareUrl);
    }

    #[Route('/{username}/share/id/{shareId}', name: 'cq_share_access_by_id', methods: ['GET', 'POST'], priority: -10)]
    public function accessById(Request $request, string $username, string $shareId): Response
    {
        return $this->handleShareAccess($request, $username, 'id', $shareId);
    }

    /**
     * Core share access handler for both URL slug and ID lookups.
     */
    private function handleShareAccess(Request $request, string $username, string $lookupType, string $lookupValue): Response
    {
        try {
            // Resolve user from username
            $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
            if (!$user) {
                return $this->json(['success' => false, 'message' => 'Not found'], Response::HTTP_NOT_FOUND);
            }

            // Set user context for share service and contact service
            $this->shareService->setUser($user);
            $this->cqContactService->setUser($user);

            // Find the share
            $share = match ($lookupType) {
                'id' => $this->shareService->findActiveById($lookupValue),
                'url' => $this->shareService->findActiveByShareUrl($lookupValue),
            };

            if (!$share) {
                return $this->json(['success' => false, 'message' => 'Not found'], Response::HTTP_NOT_FOUND);
            }

            // Check scope/auth
            $isAuthenticated = $this->checkFederationAuth($request);
            if (!$this->shareService->isAccessible($share, $isAuthenticated)) {
                return $this->json(['success' => false, 'message' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
            }

            // POST = metadata only
            if ($request->isMethod('POST')) {
                return $this->json([
                    'success' => true,
                    'share' => $this->shareService->getShareMetadata($share),
                ]);
            }

            // GET = binary file download
            return $this->serveShareFile($user, $share);

        } catch (\Exception $e) {
            $this->logger->error('CQShareController::handleShareAccess error', [
                'error' => $e->getMessage(),
                'username' => $username,
                'lookupType' => $lookupType,
                'lookupValue' => $lookupValue
            ]);
            return $this->json(['success' => false, 'message' => 'Internal error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Check if the request has a valid CQ Contact API key for the target user.
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

    /**
     * Serve the shared file as a binary download.
     * Constructs the file path for the sharing user's data directory.
     */
    private function serveShareFile(User $user, array $share): Response
    {
        // Look up the project_file record from the sharing user's DB
        $userDb = $this->userDatabaseManager->getDatabaseConnection($user);
        $file = $userDb->executeQuery(
            'SELECT * FROM project_file WHERE id = ?',
            [$share['source_id']]
        )->fetchAssociative();

        if (!$file) {
            return $this->json(['success' => false, 'message' => 'Source file not found'], Response::HTTP_NOT_FOUND);
        }

        // Construct absolute path: {project_dir}/var/user_data/{userId}/p/{projectId}/{path}/{name}
        $projectDir = $this->params->get('kernel.project_dir');
        $basePath = $projectDir . '/var/user_data/' . $user->getId() . '/p/' . $file['project_id'];
        $relativePath = ltrim($file['path'] ?? '', '/');
        $filePath = $relativePath
            ? $basePath . '/' . $relativePath . '/' . $file['name']
            : $basePath . '/' . $file['name'];

        if (!file_exists($filePath)) {
            return $this->json(['success' => false, 'message' => 'File not found on disk'], Response::HTTP_NOT_FOUND);
        }

        // Increment view counter
        $this->shareService->incrementViews($share['id']);

        // Determine content type
        $mimeType = $file['mime_type'] ?? 'application/octet-stream';
        if ($share['source_type'] === CQShareService::TYPE_CQMPACK) {
            $mimeType = 'application/x-sqlite3';
        }

        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $file['name']
        );
        $response->headers->set('Content-Type', $mimeType);
        $response->headers->set('X-CQ-Share-Id', $share['id']);
        $response->headers->set('X-CQ-Share-Updated', $share['updated_at']);

        return $response;
    }

    // ========================================
    // Authenticated API for managing shares
    // ========================================

    #[Route('/api/share', name: 'api_share_list', methods: ['GET'])]
    public function listShares(): JsonResponse
    {
        try {
            $shares = $this->shareService->listAll();
            return $this->json(['success' => true, 'shares' => $shares]);
        } catch (\Exception $e) {
            $this->logger->error('CQShareController::listShares error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/api/share', name: 'api_share_create', methods: ['POST'])]
    public function createShare(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            if (!$data) {
                return $this->json(['success' => false, 'message' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
            }

            $required = ['source_type', 'source_id', 'title', 'share_url'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return $this->json(['success' => false, 'message' => "Missing required field: {$field}"], Response::HTTP_BAD_REQUEST);
                }
            }

            $share = $this->shareService->create(
                $data['source_type'],
                $data['source_id'],
                $data['title'],
                $data['share_url'],
                (int) ($data['scope'] ?? CQShareService::SCOPE_CQ_CONTACT)
            );

            return $this->json(['success' => true, 'share' => $share]);
        } catch (\Exception $e) {
            $this->logger->error('CQShareController::createShare error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/api/share/{id}', name: 'api_share_update', methods: ['PUT'])]
    public function updateShare(Request $request, string $id): JsonResponse
    {
        try {
            $existing = $this->shareService->findById($id);
            if (!$existing) {
                return $this->json(['success' => false, 'message' => 'Share not found'], Response::HTTP_NOT_FOUND);
            }

            $data = json_decode($request->getContent(), true);
            if (!$data) {
                return $this->json(['success' => false, 'message' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
            }

            $share = $this->shareService->update($id, $data);
            return $this->json(['success' => true, 'share' => $share]);
        } catch (\Exception $e) {
            $this->logger->error('CQShareController::updateShare error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/api/share/{id}', name: 'api_share_delete', methods: ['DELETE'])]
    public function deleteShare(string $id): JsonResponse
    {
        try {
            $existing = $this->shareService->findById($id);
            if (!$existing) {
                return $this->json(['success' => false, 'message' => 'Share not found'], Response::HTTP_NOT_FOUND);
            }

            $this->shareService->delete($id);
            return $this->json(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->error('CQShareController::deleteShare error', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
