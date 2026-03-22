<?php

namespace App\Api\Controller;

use App\Service\CqContactService;
use App\Service\CQFollowService;
use App\Service\ProjectFileService;
use App\Service\CQMemoryLibraryService;
use App\Service\CQMemoryPackService;
use App\CitadelVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Citadel Explorer API Controller
 * 
 * Enables discovering and interacting with any public CitadelQuest profile.
 * Fetches remote profile JSON, proxies photos, downloads public shares.
 */
#[Route('/api/citadel-explorer')]
#[IsGranted('ROLE_USER')]
class CitadelExplorerApiController extends AbstractController
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly ProjectFileService $projectFileService,
        private readonly CQMemoryLibraryService $memoryLibraryService,
        private readonly CQMemoryPackService $memoryPackService,
        private readonly CqContactService $cqContactService,
        private readonly CQFollowService $followService,
        private readonly SluggerInterface $slugger
    ) {}

    /**
     * Fetch a remote Citadel profile for the Explorer.
     * 
     * Two distinct paths:
     * A) Active CQ Contact with API key → fetch via federation (respects CQ Contact visibility)
     * B) Non-contact / unknown URL → fetch public profile JSON
     */
    #[Route('/fetch', name: 'app_api_citadel_explorer_fetch', methods: ['POST'])]
    public function fetch(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $url = $data['url'] ?? '';

        if (empty($url) || !str_starts_with($url, 'https://')) {
            return $this->json(['success' => false, 'message' => 'Invalid URL'], Response::HTTP_BAD_REQUEST);
        }

        // Check if this URL belongs to an existing CQ Contact
        $existingContact = $this->cqContactService->findByUrl($url);

        // Path A: Accepted CQ Contact — use federation endpoint directly
        if ($existingContact && strtolower($existingContact->getFriendRequestStatus() ?? '') === 'accepted' && $existingContact->getCqContactApiKey()) {
            return $this->fetchViaFederation($existingContact, $url);
        }

        // Path B: Not a contact — fetch public profile
        return $this->fetchPublicProfile($url, $existingContact);
    }

    /**
     * Fetch public profile JSON from a remote Citadel.
     * Used for non-contact URLs (discovery / Add Contact flow).
     */
    private function fetchPublicProfile(string $url, ?\App\Entity\CqContact $existingContact): JsonResponse
    {
        try {
            $jsonUrl = rtrim($url, '/') . '/json';

            $response = $this->httpClient->request('GET', $jsonUrl, [
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'CitadelQuest ' . CitadelVersion::VERSION . ' HTTP Client',
                ],
                'timeout' => 15,
            ]);

            $statusCode = $response->getStatusCode(false);
            if ($statusCode !== 200) {
                return $this->json([
                    'success' => false,
                    'message' => 'Remote server returned HTTP ' . $statusCode,
                ], Response::HTTP_BAD_GATEWAY);
            }

            $profile = $response->toArray(false);

            if (!($profile['success'] ?? false)) {
                return $this->json([
                    'success' => false,
                    'message' => $profile['message'] ?? 'Profile not available',
                ]);
            }

            // Enrich with contact info (e.g. pending friend request)
            $profile['is_contact'] = $existingContact !== null;
            $profile['contact_id'] = $existingContact?->getId();
            $profile['contact_status'] = $existingContact?->getFriendRequestStatus();

            // Enrich with follow status
            $cqContactId = $profile['cq_contact_id'] ?? null;
            if ($cqContactId) {
                try {
                    $profile['is_following'] = $this->followService->isFollowing($cqContactId);
                } catch (\Exception $e) {
                    $profile['is_following'] = false;
                }
            } else {
                $profile['is_following'] = false;
            }

            return $this->json($profile);
        } catch (\Exception $e) {
            $this->logger->error('CitadelExplorerApiController::fetchPublicProfile error', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return $this->json([
                'success' => false,
                'message' => 'Failed to connect: ' . $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }
    }

    /**
     * Fetch profile via federation for an active CQ Contact.
     * Returns CQ Contact visibility-scoped data (bio, photo, spirits, shares).
     * Photo URL is returned as local proxy so the browser can display it directly.
     */
    private function fetchViaFederation(\App\Entity\CqContact $contact, string $url): JsonResponse
    {
        try {
            $profileUrl = rtrim($contact->getCqContactUrl(), '/') . '/api/federation/user-profile';

            $response = $this->httpClient->request('POST', $profileUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $contact->getCqContactApiKey(),
                    'User-Agent' => 'CitadelQuest ' . CitadelVersion::VERSION . ' HTTP Client',
                    'Content-Type' => 'application/json',
                ],
                'json' => [],
                'timeout' => 15,
            ]);

            $statusCode = $response->getStatusCode(false);
            if ($statusCode !== 200) {
                return $this->json([
                    'success' => false,
                    'message' => 'Remote server returned HTTP ' . $statusCode,
                ], Response::HTTP_BAD_GATEWAY);
            }

            $fedData = $response->toArray(false);
            if (!($fedData['success'] ?? false)) {
                return $this->json([
                    'success' => false,
                    'message' => $fedData['message'] ?? 'Profile not available',
                ]);
            }

            $contactId = $contact->getId();

            // Sync local contact description from remote bio
            $remoteBio = $fedData['bio'] ?? null;
            if ($remoteBio !== $contact->getDescription()) {
                $contact->setDescription($remoteBio);
                $this->cqContactService->updateContact($contact);
            }

            // Photo: use local proxy URL so browser can display without CORS issues
            $photoUrl = !empty($fedData['photo_url'])
                ? '/api/cq-contact/' . $contactId . '/profile-photo'
                : null;

            // Build profile response compatible with renderProfile() format
            $profile = [
                'success' => true,
                'username' => $fedData['username'] ?? $contact->getCqContactUsername(),
                'domain' => $fedData['domain'] ?? $contact->getCqContactDomain(),
                'bio' => $remoteBio,
                'photo_url' => $photoUrl,
                'profile_url' => $url,
                'shares' => $fedData['shared_items'] ?? [],
                'share_groups' => $fedData['share_groups'] ?? [],
                'show_share_content' => $fedData['show_share_content'] ?? false,
                'spirits' => $fedData['spirits'] ?? [],
                'background_url' => $fedData['background_url'] ?? null,
                'bg_overlay' => $fedData['bg_overlay'] ?? true,
                'is_contact' => true,
                'contact_id' => $contactId,
                'contact_status' => $contact->getFriendRequestStatus(),
            ];

            // Add cq_contact_id from federation response if available
            $cqContactId = $fedData['cq_contact_id'] ?? null;
            if ($cqContactId) {
                $profile['cq_contact_id'] = $cqContactId;
                try {
                    $profile['is_following'] = $this->followService->isFollowing($cqContactId);
                } catch (\Exception $e) {
                    $profile['is_following'] = false;
                }
            } else {
                $profile['is_following'] = false;
            }

            $profile['follower_count'] = $fedData['follower_count'] ?? 0;

            return $this->json($profile);
        } catch (\Exception $e) {
            $this->logger->error('CitadelExplorerApiController::fetchViaFederation error', [
                'contactId' => $contact->getId(),
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return $this->json([
                'success' => false,
                'message' => 'Failed to connect: ' . $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }
    }

    /**
     * Fetch share metadata directly from a remote Citadel.
     * Fallback for when the share isn't included in the profile JSON (e.g. visibility toggles).
     */
    #[Route('/fetch-share', name: 'app_api_citadel_explorer_fetch_share', methods: ['POST'])]
    public function fetchShare(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $url = $data['url'] ?? '';

        if (empty($url) || !str_starts_with($url, 'https://')) {
            return $this->json(['success' => false, 'message' => 'Invalid URL'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'User-Agent' => 'CitadelQuest ' . CitadelVersion::VERSION . ' HTTP Client',
                    'Content-Type' => 'application/json',
                ],
                'timeout' => 15,
            ]);

            $statusCode = $response->getStatusCode(false);
            if ($statusCode !== 200) {
                return $this->json(['success' => false, 'message' => 'Not found'], Response::HTTP_NOT_FOUND);
            }

            $shareData = $response->toArray(false);
            if (!($shareData['success'] ?? false) || empty($shareData['share'])) {
                return $this->json(['success' => false, 'message' => 'Share not found']);
            }

            return $this->json([
                'success' => true,
                'share' => $shareData['share'],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('CitadelExplorerApiController::fetchShare error', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return $this->json(['success' => false, 'message' => 'Failed to fetch share'], Response::HTTP_BAD_GATEWAY);
        }
    }

    /**
     * Proxy a remote profile photo for display in the explorer.
     */
    #[Route('/photo', name: 'app_api_citadel_explorer_photo', methods: ['GET'])]
    public function photo(Request $request): Response
    {
        $photoUrl = $request->query->get('url', '');
        if (empty($photoUrl)) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        // Forward ?full=1 for fullscreen modal
        if ($request->query->get('full') === '1') {
            $photoUrl .= (str_contains($photoUrl, '?') ? '&' : '?') . 'full=1';
        }

        if ($request->query->get('icon') === '1') {
            $photoUrl .= (str_contains($photoUrl, '?') ? '&' : '?') . 'icon=1';
        }

        try {
            $response = $this->httpClient->request('GET', $photoUrl, [
                'headers' => [
                    'User-Agent' => 'CitadelQuest ' . CitadelVersion::VERSION . ' HTTP Client',
                ],
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode(false);
            if ($statusCode !== 200) {
                return new Response('', Response::HTTP_NOT_FOUND);
            }

            $contentType = $response->getHeaders(false)['content-type'][0] ?? 'image/jpeg';
            $content = $response->getContent(false);

            return new Response($content, 200, [
                'Content-Type' => $contentType,
                'Cache-Control' => 'public, max-age=300',
            ]);
        } catch (\Exception $e) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Proxy remote graph data (Memory Pack 3D preview) to avoid CORS issues.
     */
    #[Route('/graph', name: 'app_api_citadel_explorer_graph', methods: ['GET'])]
    public function graph(Request $request): JsonResponse
    {
        $graphUrl = $request->query->get('url', '');
        if (empty($graphUrl) || !str_starts_with($graphUrl, 'https://')) {
            return $this->json(['error' => 'Invalid URL'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $response = $this->httpClient->request('GET', $graphUrl, [
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'CitadelQuest ' . CitadelVersion::VERSION . ' HTTP Client',
                ],
                'timeout' => 15,
            ]);

            $statusCode = $response->getStatusCode(false);
            if ($statusCode !== 200) {
                return $this->json(['error' => 'Remote returned HTTP ' . $statusCode], Response::HTTP_BAD_GATEWAY);
            }

            $data = $response->toArray(false);

            return $this->json($data, 200, [
                'Cache-Control' => 'public, max-age=300',
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('CitadelExplorerApiController::graph error', ['error' => $e->getMessage()]);
            return $this->json(['error' => 'Failed to fetch graph'], Response::HTTP_BAD_GATEWAY);
        }
    }

    /**
     * Proxy remote share content (e.g. PDF) to avoid X-Frame-Options cross-origin blocking.
     * Used by CQ Explorer to embed remote PDFs in iframes.
     */
    #[Route('/share-content', name: 'app_api_citadel_explorer_share_content', methods: ['GET'])]
    public function shareContent(Request $request): Response
    {
        $contentUrl = $request->query->get('url', '');
        if (empty($contentUrl) || !str_starts_with($contentUrl, 'https://')) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        try {
            $response = $this->httpClient->request('GET', $contentUrl, [
                'headers' => [
                    'User-Agent' => 'CitadelQuest ' . CitadelVersion::VERSION . ' HTTP Client',
                ],
                'timeout' => 30,
            ]);

            $statusCode = $response->getStatusCode(false);
            if ($statusCode !== 200) {
                return new Response('', Response::HTTP_NOT_FOUND);
            }

            $contentType = $response->getHeaders(false)['content-type'][0] ?? 'application/octet-stream';
            $content = $response->getContent(false);

            return new Response($content, 200, [
                'Content-Type' => $contentType,
                'Cache-Control' => 'public, max-age=300',
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('CitadelExplorerApiController::shareContent error', ['error' => $e->getMessage()]);
            return new Response('', Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Check which shares from a remote profile have already been downloaded locally.
     * Uses source_url matching in project_file_remote table.
     */
    #[Route('/check-downloads', name: 'app_api_citadel_explorer_check_downloads', methods: ['POST'])]
    public function checkDownloads(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $shares = $data['shares'] ?? [];
            $profileUrl = rtrim($data['profile_url'] ?? '', '/');

            if (empty($profileUrl)) {
                return $this->json(['success' => false, 'message' => 'Missing profile_url'], Response::HTTP_BAD_REQUEST);
            }

            $results = [];
            foreach ($shares as $share) {
                $shareUrl = $share['share_url'] ?? '';
                $sourceUrl = $profileUrl . '/share/' . $shareUrl;
                $remote = $this->projectFileService->findRemoteFileBySourceUrl($sourceUrl);

                $results[$shareUrl] = [
                    'downloaded' => $remote !== null,
                    'path' => $remote['path'] ?? null,
                    'fileName' => $remote['name'] ?? null,
                ];
            }

            return $this->json(['success' => true, 'downloads' => $results]);
        } catch (\Exception $e) {
            $this->logger->error('CitadelExplorerApiController::checkDownloads error', [
                'error' => $e->getMessage(),
            ]);
            return $this->json(['success' => false, 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Download a publicly shared item from a remote Citadel to the local File Browser.
     * No federation auth needed — uses public share URLs.
     */
    #[Route('/download', name: 'app_api_citadel_explorer_download', methods: ['POST'])]
    public function download(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $profileUrl = rtrim($data['profile_url'] ?? '', '/');
            $shareUrl = $data['share_url'] ?? null;
            $sourceType = $data['source_type'] ?? 'file';
            $title = $data['title'] ?? 'Shared item';
            $domain = $data['domain'] ?? 'unknown';
            $username = $data['username'] ?? 'unknown';

            if (!$profileUrl || !$shareUrl) {
                return $this->json(['success' => false, 'message' => 'Missing profile_url or share_url'], Response::HTTP_BAD_REQUEST);
            }

            // Build public download URL
            $downloadUrl = $profileUrl . '/share/' . $shareUrl;

            // GET the file from remote Citadel (public, no auth)
            $response = $this->httpClient->request('GET', $downloadUrl, [
                'headers' => [
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
                return $this->downloadCqmpackToCitadel($projectId, $fileName, $binaryContent, $downloadUrl, $domain, $username);
            } else {
                return $this->downloadFileToCitadel($projectId, $fileName, $binaryContent, $downloadUrl);
            }
        } catch (\Exception $e) {
            $this->logger->error('CitadelExplorerApiController::download error', [
                'error' => $e->getMessage(),
            ]);
            return $this->json(['success' => false, 'message' => 'Download failed: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Save a downloaded .cqmpack to an explorer-specific memory directory.
     */
    private function downloadCqmpackToCitadel(string $projectId, string $fileName, string $content, string $sourceUrl, string $domain, string $username): JsonResponse
    {
        $slug = $this->slugger->slug($domain . '-' . $username)->lower()->toString();
        $dirPath = '/memory/explore/' . $slug;

        if (!str_ends_with($fileName, '.cqmpack')) {
            $fileName .= '.cqmpack';
        }

        $existingFile = $this->projectFileService->findByPathAndName($projectId, $dirPath, $fileName);
        if ($existingFile) {
            $this->projectFileService->updateFile($existingFile->getId(), $content);
            $fileId = $existingFile->getId();
        } else {
            $file = $this->projectFileService->createFile($projectId, $dirPath, $fileName, $content, 'application/x-sqlite3');
            $fileId = $file->getId();
        }

        // Create remote file record for sync tracking
        try {
            $this->projectFileService->createRemoteFileRecord($fileId, $sourceUrl, null);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to create remote file record for explorer cqmpack', ['error' => $e->getMessage()]);
        }

        // Set source metadata in the pack
        try {
            $this->memoryPackService->open($projectId, $dirPath, $fileName);
            $this->memoryPackService->setSourceUrl($sourceUrl);
            $this->memoryPackService->touchSyncedAt();
            $purged = $this->memoryPackService->purgeNonCompletedJobs();
            if ($purged > 0) {
                $this->logger->info('Explorer download: purged {count} foreign jobs', ['count' => $purged]);
            }
            $this->memoryPackService->close();
        } catch (\Exception $e) {
            $this->logger->warning('Failed to set pack metadata after explorer download', ['error' => $e->getMessage()]);
        }

        // Create or update library for this explored profile
        $libFileName = $slug . '.' . CQMemoryLibraryService::FILE_EXTENSION;
        try {
            $existingLib = $this->projectFileService->findByPathAndName($projectId, $dirPath, $libFileName);
            if (!$existingLib) {
                $this->memoryLibraryService->createLibrary($projectId, $dirPath, $slug, [
                    'name' => $username . '\'s Shared Packs (' . $domain . ')',
                    'description' => 'Memory Packs from ' . $username . ' at ' . $domain,
                ]);
            }

            try {
                $this->memoryLibraryService->addPackToLibrary($projectId, $dirPath, $libFileName, $dirPath, $fileName);
            } catch (\RuntimeException $e) {
                if (!str_contains($e->getMessage(), 'already exists')) {
                    throw $e;
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to manage library for explorer download', ['error' => $e->getMessage()]);
        }

        return $this->json([
            'success' => true,
            'message' => 'Memory Pack downloaded to ' . $dirPath . '/' . $fileName,
            'path' => $dirPath,
            'fileName' => $fileName,
        ]);
    }

    /**
     * Save a downloaded file to /downloads/ in the general project.
     */
    private function downloadFileToCitadel(string $projectId, string $fileName, string $content, string $sourceUrl): JsonResponse
    {
        $dirPath = '/downloads';

        $existingFile = $this->projectFileService->findByPathAndName($projectId, $dirPath, $fileName);
        if ($existingFile) {
            $this->projectFileService->updateFile($existingFile->getId(), $content);
            $fileId = $existingFile->getId();
        } else {
            $ext = pathinfo($fileName, PATHINFO_EXTENSION);
            $mimeType = match (strtolower($ext)) {
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'svg' => 'image/svg+xml',
                'bmp' => 'image/bmp',
                'avif' => 'image/avif',
                'tiff', 'tif' => 'image/tiff',
                'ico' => 'image/x-icon',
                'pdf' => 'application/pdf',
                'txt' => 'text/plain',
                'md' => 'text/markdown',
                'html', 'htm' => 'text/html',
                'css' => 'text/css',
                'js' => 'text/javascript',
                'csv' => 'text/csv',
                'json' => 'application/json',
                'xml' => 'application/xml',
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls' => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'ppt' => 'application/vnd.ms-powerpoint',
                'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'zip' => 'application/zip',
                'rar' => 'application/x-rar-compressed',
                'tar' => 'application/x-tar',
                'gz' => 'application/gzip',
                'mp3' => 'audio/mpeg',
                'ogg' => 'audio/ogg',
                'wav' => 'audio/wav',
                'mp4' => 'video/mp4',
                'webm' => 'video/webm',
                'cqmpack' => 'application/x-sqlite3',
                default => 'application/octet-stream',
            };

            $file = $this->projectFileService->createFile($projectId, $dirPath, $fileName, $content, $mimeType);
            $fileId = $file->getId();
        }

        try {
            $this->projectFileService->createRemoteFileRecord($fileId, $sourceUrl, null);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to create remote file record for explorer download', ['error' => $e->getMessage()]);
        }

        return $this->json([
            'success' => true,
            'message' => 'File downloaded to ' . $dirPath . '/' . $fileName,
            'path' => $dirPath,
            'fileName' => $fileName,
        ]);
    }
}
