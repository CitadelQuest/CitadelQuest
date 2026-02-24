<?php

namespace App\Api\Controller;

use App\Entity\CqContact;
use App\Service\CqContactService;
use App\Service\ProjectFileService;
use App\Service\CQMemoryLibraryService;
use App\Service\CQMemoryPackService;
use App\CitadelVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/api/cq-contact')]
#[IsGranted('ROLE_USER')]
class CqContactApiController extends AbstractController
{
    public function __construct(
        private readonly CqContactService $cqContactService,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly ProjectFileService $projectFileService,
        private readonly CQMemoryLibraryService $memoryLibraryService,
        private readonly CQMemoryPackService $memoryPackService,
        private readonly ParameterBagInterface $params,
        private readonly SluggerInterface $slugger
    ) {
    }
    
    /**
     * Get badge counts (pending friend requests, etc.)
     */
    #[Route('/badges', name: 'app_api_cq_contact_badges', methods: ['GET'])]
    public function getBadges(): JsonResponse
    {
        try {
            $pendingRequests = $this->cqContactService->countPendingFriendRequests();
            
            return $this->json([
                'pendingFriendRequests' => $pendingRequests
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('', name: 'app_api_cq_contact_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        try {
            $contacts = $this->cqContactService->findAll(false);
            foreach ($contacts as &$contact) {
                $contact->setCqContactApiKey('***');
            }   
            return $this->json($contacts);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}', name: 'app_api_cq_contact_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        try {
            $contact = $this->cqContactService->findById($id);

            $contact->setCqContactApiKey('***');
            
            if (!$contact) {
                return $this->json(['error' => 'Contact not found'], Response::HTTP_NOT_FOUND);
            }
            
            return $this->json($contact);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('', name: 'app_api_cq_contact_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        try {
            $contact = $this->cqContactService->createContact(
                $data['cqContactUrl'],
                $data['cqContactDomain'],
                $data['cqContactUsername'],
                $data['cqContactId'] ?? null,
                $data['cqContactApiKey'] ?? null,
                $data['friendRequestStatus'] ?? null,
                $data['description'] ?? null,
                $data['profilePhotoProjectFileId'] ?? null,
                $data['isActive'] ?? false
            );
            
            return $this->json($contact, Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}', name: 'app_api_cq_contact_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        try {
            $contact = $this->cqContactService->findById($id);
            
            if (!$contact) {
                return $this->json(['error' => 'Contact not found'], Response::HTTP_NOT_FOUND);
            }
            
            $data = json_decode($request->getContent(), true);
            
            if (isset($data['cqContactUrl'])) {
                $contact->setCqContactUrl($data['cqContactUrl']);
            }
            if (isset($data['cqContactDomain'])) {
                $contact->setCqContactDomain($data['cqContactDomain']);
            }
            if (isset($data['cqContactUsername'])) {
                $contact->setCqContactUsername($data['cqContactUsername']);
            }
            if (isset($data['cqContactId'])) {
                $contact->setCqContactId($data['cqContactId']);
            }
            if (isset($data['cqContactApiKey'])) {
                $contact->setCqContactApiKey($data['cqContactApiKey']);
            }
            if (isset($data['description'])) {
                $contact->setDescription($data['description']);
            }
            if (isset($data['profilePhotoProjectFileId'])) {
                $contact->setProfilePhotoProjectFileId($data['profilePhotoProjectFileId']);
            }
            if (isset($data['isActive'])) {
                $contact->setIsActive($data['isActive']);
            }
            
            $this->cqContactService->updateContact($contact);
            
            return $this->json($contact);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}', name: 'app_api_cq_contact_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        try {
            $success = $this->cqContactService->deleteContact($id);
            
            if (!$success) {
                return $this->json(['error' => 'Contact not found'], Response::HTTP_NOT_FOUND);
            }
            
            return $this->json(['message' => 'Contact deleted successfully']);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}/activate', name: 'app_api_cq_contact_activate', methods: ['POST'])]
    public function activate(string $id): JsonResponse
    {
        try {
            $success = $this->cqContactService->activateContact($id);
            
            if (!$success) {
                return $this->json(['error' => 'Contact not found'], Response::HTTP_NOT_FOUND);
            }
            
            return $this->json(['message' => 'Contact activated successfully']);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}/deactivate', name: 'app_api_cq_contact_deactivate', methods: ['POST'])]
    public function deactivate(string $id): JsonResponse
    {
        try {
            $success = $this->cqContactService->deactivateContact($id);
            
            if (!$success) {
                return $this->json(['error' => 'Contact not found'], Response::HTTP_NOT_FOUND);
            }
            
            return $this->json(['message' => 'Contact deactivated successfully']);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    // ========================================
    // CQ Share — Federation proxy & download
    // ========================================

    /**
     * Proxy: fetch list of shared items from a remote contact's Citadel.
     * Calls GET {contactUrl}/shares with Bearer auth.
     */
    #[Route('/{id}/shares', name: 'app_api_cq_contact_shares', methods: ['GET'])]
    public function getContactShares(string $id): JsonResponse
    {
        try {
            $contact = $this->cqContactService->findById($id);
            if (!$contact) {
                return $this->json(['success' => false, 'message' => 'Contact not found'], Response::HTTP_NOT_FOUND);
            }

            if (!$contact->getCqContactApiKey()) {
                return $this->json(['success' => false, 'message' => 'No API key for this contact'], Response::HTTP_BAD_REQUEST);
            }

            $sharesUrl = rtrim($contact->getCqContactUrl(), '/') . '/shares';

            $response = $this->httpClient->request('GET', $sharesUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $contact->getCqContactApiKey(),
                    'Accept' => 'application/json',
                    'User-Agent' => 'CitadelQuest ' . CitadelVersion::VERSION . ' HTTP Client',
                ],
                'timeout' => 15,
            ]);

            $statusCode = $response->getStatusCode(false);
            if ($statusCode !== 200) {
                $body = json_decode($response->getContent(false), true);
                return $this->json([
                    'success' => false,
                    'message' => $body['message'] ?? 'Remote server returned ' . $statusCode
                ], Response::HTTP_BAD_GATEWAY);
            }

            $data = $response->toArray(false);
            return $this->json([
                'success' => true,
                'shares' => $data['shares'] ?? [],
                'username' => $data['username'] ?? $contact->getCqContactUsername(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('CqContactApiController::getContactShares error', [
                'contactId' => $id,
                'error' => $e->getMessage()
            ]);
            return $this->json(['success' => false, 'message' => 'Connection failed: ' . $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }
    }

    /**
     * Check which shares have already been downloaded locally.
     * Returns a map of share_url → { downloaded: bool, path, fileName } for each share.
     */
    #[Route('/{id}/check-downloads', name: 'app_api_cq_contact_check_downloads', methods: ['POST'])]
    public function checkDownloads(string $id, Request $request): JsonResponse
    {
        try {
            $contact = $this->cqContactService->findById($id);
            if (!$contact) {
                return $this->json(['success' => false, 'message' => 'Contact not found'], Response::HTTP_NOT_FOUND);
            }

            $data = json_decode($request->getContent(), true);
            $shares = $data['shares'] ?? [];
            $projectId = 'general';
            $slug = $this->buildContactSlug($contact);
            $cqmpackDir = '/memory/cq-contact/' . $slug;

            $results = [];
            foreach ($shares as $share) {
                $shareUrl = $share['share_url'] ?? '';
                $sourceType = $share['source_type'] ?? 'file';
                $title = $share['title'] ?? '';

                if ($sourceType === 'cqmpack') {
                    // Check in /memory/cq-contact/{slug}/
                    $fileName = $title;
                    if (!str_ends_with($fileName, '.cqmpack')) {
                        $fileName .= '.cqmpack';
                    }
                    $file = $this->projectFileService->findByPathAndName($projectId, $cqmpackDir, $fileName);
                    $results[$shareUrl] = [
                        'downloaded' => $file !== null,
                        'path' => $cqmpackDir,
                        'fileName' => $fileName,
                    ];
                } else {
                    // Check in /downloads/
                    $file = $this->projectFileService->findByPathAndName($projectId, '/downloads', $title);
                    $results[$shareUrl] = [
                        'downloaded' => $file !== null,
                        'path' => '/downloads',
                        'fileName' => $title,
                    ];
                }
            }

            return $this->json(['success' => true, 'downloads' => $results]);
        } catch (\Exception $e) {
            $this->logger->error('CqContactApiController::checkDownloads error', [
                'contactId' => $id,
                'error' => $e->getMessage()
            ]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Download a shared item from a remote contact's Citadel to the local File Browser.
     * For .cqmpack files: saves to /memory/cq-contact/{slug}/ and creates a library.
     * For other files: saves to /downloads/ directory.
     */
    #[Route('/{id}/download-share', name: 'app_api_cq_contact_download_share', methods: ['POST'])]
    public function downloadShare(string $id, Request $request): JsonResponse
    {
        try {
            $contact = $this->cqContactService->findById($id);
            if (!$contact) {
                return $this->json(['success' => false, 'message' => 'Contact not found'], Response::HTTP_NOT_FOUND);
            }

            $data = json_decode($request->getContent(), true);
            $shareUrl = $data['share_url'] ?? null;
            $sourceType = $data['source_type'] ?? 'file';
            $title = $data['title'] ?? 'Shared item';

            if (!$shareUrl) {
                return $this->json(['success' => false, 'message' => 'Missing share_url'], Response::HTTP_BAD_REQUEST);
            }

            // Build remote download URL: {contactUrl}/share/{shareUrl}
            $downloadUrl = rtrim($contact->getCqContactUrl(), '/') . '/share/' . $shareUrl;

            // GET the binary file from remote Citadel
            $response = $this->httpClient->request('GET', $downloadUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $contact->getCqContactApiKey(),
                    'Accept' => 'application/octet-stream',
                    'User-Agent' => 'CitadelQuest ' . CitadelVersion::VERSION . ' HTTP Client',
                ],
                'timeout' => 120,
            ]);

            $statusCode = $response->getStatusCode(false);
            if ($statusCode !== 200) {
                return $this->json(['success' => false, 'message' => 'Download failed: HTTP ' . $statusCode], Response::HTTP_BAD_GATEWAY);
            }

            $binaryContent = $response->getContent(false);
            if (empty($binaryContent)) {
                return $this->json(['success' => false, 'message' => 'Empty file received'], Response::HTTP_BAD_GATEWAY);
            }

            // Extract filename from Content-Disposition header or use title
            $contentDisposition = $response->getHeaders(false)['content-disposition'][0] ?? '';
            $fileName = $title;
            if (preg_match('/filename="?([^"]+)"?/', $contentDisposition, $m)) {
                $fileName = $m[1];
            }

            $projectId = 'general';

            if ($sourceType === 'cqmpack') {
                return $this->downloadCqmpackToCitadel($contact, $projectId, $fileName, $binaryContent, $downloadUrl);
            } else {
                return $this->downloadFileToCitadel($projectId, $fileName, $binaryContent);
            }

        } catch (\Exception $e) {
            $this->logger->error('CqContactApiController::downloadShare error', [
                'contactId' => $id,
                'error' => $e->getMessage()
            ]);
            return $this->json(['success' => false, 'message' => 'Download failed: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Save a downloaded .cqmpack to a contact-specific memory library directory.
     * Creates /memory/cq-contact/{slug}/ dir, saves the pack, creates/updates a .cqmlib library.
     */
    private function downloadCqmpackToCitadel(CqContact $contact, string $projectId, string $fileName, string $content, string $sourceUrl): JsonResponse
    {
        // Build contact-specific directory slug
        $slug = $this->buildContactSlug($contact);
        $dirPath = '/memory/cq-contact/' . $slug;

        // Ensure filename has .cqmpack extension
        if (!str_ends_with($fileName, '.cqmpack')) {
            $fileName .= '.cqmpack';
        }

        // Save or overwrite the pack file
        $existingFile = $this->projectFileService->findByPathAndName($projectId, $dirPath, $fileName);
        if ($existingFile) {
            $this->projectFileService->updateFile($existingFile->getId(), $content);
        } else {
            $this->projectFileService->createFile($projectId, $dirPath, $fileName, $content, 'application/x-sqlite3');
        }

        // Set source_url and source_cq_contact_id in the pack metadata
        try {
            $this->memoryPackService->open($projectId, $dirPath, $fileName);
            $this->memoryPackService->setSourceUrl($sourceUrl);
            if ($contact->getCqContactId()) {
                $this->memoryPackService->setSourceCqContactId($contact->getCqContactId());
            }
            $this->memoryPackService->touchSyncedAt();
            $this->memoryPackService->close();
        } catch (\Exception $e) {
            $this->logger->warning('Failed to set pack metadata after download', ['error' => $e->getMessage()]);
        }

        // Create or update library for this contact
        $libName = $slug;
        $libFileName = $slug . '.' . CQMemoryLibraryService::FILE_EXTENSION;
        try {
            $existingLib = $this->projectFileService->findByPathAndName($projectId, $dirPath, $libFileName);
            if (!$existingLib) {
                // createLibrary auto-appends .cqmlib extension
                $this->memoryLibraryService->createLibrary($projectId, $dirPath, $libName, [
                    'name' => $contact->getCqContactUsername() . '\'s Shared Packs',
                    'description' => 'Memory Packs shared by ' . $contact->getCqContactUsername() . ' (' . $contact->getCqContactDomain() . ')'
                ]);
            }

            // Add pack to library (if not already present) — use full filename with extension
            try {
                $this->memoryLibraryService->addPackToLibrary($projectId, $dirPath, $libFileName, $dirPath, $fileName);
            } catch (\RuntimeException $e) {
                // Pack already in library — that's fine
                if (!str_contains($e->getMessage(), 'already exists')) {
                    throw $e;
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to manage library for downloaded pack', ['error' => $e->getMessage()]);
        }

        return $this->json([
            'success' => true,
            'message' => 'Memory Pack downloaded to ' . $dirPath . '/' . $fileName,
            'path' => $dirPath,
            'fileName' => $fileName
        ]);
    }

    /**
     * Save a downloaded file to /downloads/ in the general project.
     */
    private function downloadFileToCitadel(string $projectId, string $fileName, string $content): JsonResponse
    {
        $dirPath = '/downloads';

        // Avoid overwriting — append counter if file exists
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        $ext = pathinfo($fileName, PATHINFO_EXTENSION);
        $finalName = $fileName;
        $counter = 1;
        while ($this->projectFileService->findByPathAndName($projectId, $dirPath, $finalName)) {
            $finalName = $baseName . '-' . $counter . ($ext ? '.' . $ext : '');
            $counter++;
        }

        $mimeType = match (strtolower($ext)) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'md' => 'text/markdown',
            'json' => 'application/json',
            default => 'application/octet-stream',
        };

        $this->projectFileService->createFile($projectId, $dirPath, $finalName, $content, $mimeType);

        return $this->json([
            'success' => true,
            'message' => 'File downloaded to ' . $dirPath . '/' . $finalName,
            'path' => $dirPath,
            'fileName' => $finalName
        ]);
    }

    /**
     * Build a URL-safe slug for a contact's memory directory.
     * Format: {username}-{shortCqContactId}
     */
    private function buildContactSlug(CqContact $contact): string
    {
        $username = $this->slugger->slug($contact->getCqContactUsername())->toString();
        // short id = last 12 chars
        $shortId = $contact->getCqContactId() ? substr($contact->getCqContactId(), -12) : substr($contact->getId(), -12);
        return $username . '-' . $shortId;
    }

    #[Route('/{id}/friend-request', name: 'app_api_cq_contact_friend_request', methods: ['POST'])]
    public function sendFriendRequest(string $id, Request $request): JsonResponse
    {
        try {
            $contact = $this->cqContactService->findById($id);
            
            if (!$contact) {
                return $this->json(['error' => 'Contact not found'], Response::HTTP_NOT_FOUND);
            }
            
            $data = json_decode($request->getContent(), true);
            $friendRequestStatus = $data['friendRequestStatus'] ?? 'SENT';
            
            $result = $this->sendFriendRequestToContact($contact, $friendRequestStatus, $request->getHost());
            
            return $this->json($result);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Send friend request to another CitadelQuest instance
     * 
     * @param CqContact $contact
     * @param string $friendRequestStatus [SENT, RECEIVED, ACCEPTED, REJECTED]
     * @return array
     */
    private function sendFriendRequestToContact(CqContact $contact, string $friendRequestStatus, string $currentDomain): array
    {
        $user = $this->getUser();
        $targetUrl = $contact->getCqContactUrl() . '/api/federation/friend-request';
        
        try {
            $requestData = [
                'cq_contact_url' => 'https://' . $currentDomain . '/' . $user->getUsername(),
                'cq_contact_domain' => $currentDomain,
                'cq_contact_username' => $user->getUsername(),
                'cq_contact_id' => $user->getId(),
                'friend_request_status' => $friendRequestStatus,
            ];

            $response = $this->httpClient->request(
                'POST',
                $targetUrl,
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $contact->getCqContactApiKey(),
                        'User-Agent' => 'CitadelQuest ' . CitadelVersion::VERSION . ' HTTP Client',
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $requestData,
                    'timeout' => 30,
                ]
            );
            
            $statusCode = $response->getStatusCode(false);
            
            if ($statusCode !== Response::HTTP_OK) {
                $content = $response->getContent(false);
                $data = json_decode($content, true);
                $this->logger->warning('CqContactApiController::sendFriendRequestToContact - Request failed', [
                    'status_code' => $statusCode,
                    'response' => $data
                ]);
                return [
                    'success' => false,
                    'message' => 'Failed to send friend request. ' . ($data['message'] ?? 'Unknown error')
                ];
            }
            
            if ($friendRequestStatus === 'ACCEPTED') {
                $contact->setIsActive(true);
            } elseif ($friendRequestStatus === 'REJECTED') {
                $contact->setIsActive(false);
            }
            
            // Update contact with new friend request status
            $contact->setFriendRequestStatus($friendRequestStatus);
            $this->cqContactService->updateContact($contact);
            
            return [
                'success' => true,
                'message' => 'Friend request sent successfully'
            ];

        } catch (TransportExceptionInterface $e) {
            $this->logger->error('CqContactApiController::sendFriendRequestToContact - Transport error (timeout/connection)', [
                'exception' => $e->getMessage(),
                'target_url' => $targetUrl
            ]);
            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage() . '. The target server may be unreachable or slow to respond.'
            ];
        } catch (ClientExceptionInterface $e) {
            $this->logger->error('CqContactApiController::sendFriendRequestToContact - Client error', [
                'exception' => $e->getMessage(),
                'target_url' => $targetUrl
            ]);
            return [
                'success' => false,
                'message' => 'Failed to send friend request. ' . $e->getMessage()
            ];
        } catch (\Exception $e) {
            $this->logger->error('CqContactApiController::sendFriendRequestToContact - Unexpected error', [
                'exception' => $e->getMessage(),
                'exception_class' => get_class($e),
                'target_url' => $targetUrl
            ]);
            return [
                'success' => false,
                'message' => 'Unexpected error: ' . $e->getMessage()
            ];
        }
    }
}
