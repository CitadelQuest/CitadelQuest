<?php

namespace App\Api\Controller;

use App\Service\CqContactService;
use App\Service\CQFollowService;
use App\CitadelVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Batch profile icon endpoint.
 * Returns base64 icon data for all contacts/follows/followers in a single response,
 * cached server-side per user to avoid repeated federation requests.
 */
class CQProfileIconsController extends AbstractController
{
    private const CACHE_TTL = 15 * 60; // 30 minutes

    public function __construct(
        private readonly CqContactService $cqContactService,
        private readonly CQFollowService $followService,
        private readonly HttpClientInterface $httpClient,
        private readonly ParameterBagInterface $params,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/api/cq-profile-icons', name: 'api_cq_profile_icons', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function profileIcons(): JsonResponse
    {
        $user = $this->getUser();
        $cacheFile = $this->params->get('kernel.project_dir') . '/var/cache/cq-profile-icons-' . $user->getId() . '.json';

        // Serve cached file if still valid
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < self::CACHE_TTL) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if ($cached !== null) {
                return new JsonResponse($cached);
            }
        }

        // ── Gather all unique cq_contact_url → api_key mappings ──

        $contactUrls = []; // url => api_key|null

        // CQ Contacts (have API keys for federation auth)
        $this->cqContactService->setUser($user);
        foreach ($this->cqContactService->findAll() as $contact) {
            $url = $contact->getCqContactUrl();
            if ($url && !isset($contactUrls[$url])) {
                $contactUrls[$url] = $contact->getCqContactApiKey();
            }
        }

        // Follows (public photo, no auth needed)
        $this->followService->setUser($user);
        foreach ($this->followService->listFollows() as $f) {
            $url = $f['cq_contact_url'] ?? null;
            if ($url && !isset($contactUrls[$url])) {
                $contactUrls[$url] = null;
            }
        }

        // Followers (public photo, no auth needed)
        foreach ($this->followService->listFollowers() as $f) {
            $url = $f['cq_contact_url'] ?? null;
            if ($url && !isset($contactUrls[$url])) {
                $contactUrls[$url] = null;
            }
        }

        if (empty($contactUrls)) {
            $this->_writeCache($cacheFile, []);
            return new JsonResponse([]);
        }

        // Load existing cache (may have icons from previous fetch)
        $cache = [];
        if (file_exists($cacheFile)) {
            $cache = json_decode(file_get_contents($cacheFile), true) ?: [];
        }

        // ── Phase 1: Check last_updated timestamps in parallel ──

        $pending = [];
        foreach ($contactUrls as $url => $apiKey) {
            $photoUrl = rtrim($url, '/') . '/photo?lastUpdated=1';
            $headers = [
                'User-Agent' => 'CitadelQuest ' . CitadelVersion::VERSION . ' HTTP Client',
                'Accept' => 'application/json',
            ];
            if ($apiKey) {
                $headers['Authorization'] = 'Bearer ' . $apiKey;
            }

            $response = $this->httpClient->request('GET', $photoUrl, [
                'headers' => $headers,
                'timeout' => 10,
                'verify_peer' => false,
            ]);

            $pending[] = ['url' => $url, 'api_key' => $apiKey, 'response' => $response];
        }

        // ── Phase 2: Determine which icons are stale ──

        $stale = [];
        foreach ($pending as $item) {
            try {
                $statusCode = $item['response']->getStatusCode(false);
                if ($statusCode !== 200) {
                    // No photo or unreachable — remove from cache
                    unset($cache[$item['url']]);
                    continue;
                }

                $data = $item['response']->toArray(false);
                $remoteUpdated = $data['last_updated'] ?? null;
                $cachedUpdated = $cache[$item['url']]['last_updated'] ?? null;

                if (!$remoteUpdated) {
                    // No photo on remote
                    unset($cache[$item['url']]);
                    continue;
                }

                // Needs refresh if: not cached, no image_data, or timestamp differs
                if (!isset($cache[$item['url']]['image_data']) || $remoteUpdated !== $cachedUpdated) {
                    $stale[] = $item;
                    $cache[$item['url']]['last_updated'] = $remoteUpdated;
                }
            } catch (\Exception $e) {
                // Remote unavailable — keep existing cache entry if present
            }
        }

        // ── Phase 3: Fetch fresh icons for stale entries (parallel) ──

        if (!empty($stale)) {
            $iconPending = [];
            foreach ($stale as $item) {
                $photoUrl = rtrim($item['url'], '/') . '/photo?icon=1';
                $headers = [
                    'User-Agent' => 'CitadelQuest ' . CitadelVersion::VERSION . ' HTTP Client',
                ];
                if ($item['api_key']) {
                    $headers['Authorization'] = 'Bearer ' . $item['api_key'];
                }

                $response = $this->httpClient->request('GET', $photoUrl, [
                    'headers' => $headers,
                    'timeout' => 10,
                    'verify_peer' => false,
                ]);

                $iconPending[] = ['url' => $item['url'], 'response' => $response];
            }

            foreach ($iconPending as $item) {
                try {
                    $statusCode = $item['response']->getStatusCode(false);
                    if ($statusCode === 200) {
                        $contentType = $item['response']->getHeaders(false)['content-type'][0] ?? 'image/jpeg';
                        $content = $item['response']->getContent(false);
                        $cache[$item['url']]['image_data'] = 'data:' . $contentType . ';base64,' . base64_encode($content);
                    }
                } catch (\Exception $e) {
                    $this->logger->warning('CQProfileIconsController: icon fetch failed', [
                        'url' => $item['url'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Remove entries for contacts that no longer exist
        $validUrls = array_keys($contactUrls);
        foreach (array_keys($cache) as $url) {
            if (!in_array($url, $validUrls)) {
                unset($cache[$url]);
            }
        }

        $this->_writeCache($cacheFile, $cache);

        return new JsonResponse($cache);
    }

    private function _writeCache(string $cacheFile, array $data): void
    {
        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($cacheFile, json_encode($data));
    }
}
