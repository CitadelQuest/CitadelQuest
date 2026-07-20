<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Thin HTTP client for the Cloudflare API (v4).
 * Reads token from caller (resolved by AIToolCloudflareService from ai_tool_settings).
 *
 * IMPORTANT: Cloudflare returns HTTP 200 even on logical failure. Always check the JSON
 * envelope `success` flag and parse `errors[]` — not just the HTTP status.
 *
 * @see https://developers.cloudflare.com/api/
 */
class CloudflareApiService
{
    private const BASE_URL = 'https://api.cloudflare.com/client/v4';
    private const HTTP_TIMEOUT = 20;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Perform a request and unwrap the Cloudflare envelope.
     * Returns normalized ['success' => bool, 'data' => mixed, ...] arrays.
     */
    private function request(string $method, string $path, array $options = [], ?string $token = null): array
    {
        $url = self::BASE_URL . $path;

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $options['headers'] = array_merge($headers, $options['headers'] ?? []);
        $options['timeout'] = self::HTTP_TIMEOUT;

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $statusCode = $response->getStatusCode();

            if ($statusCode === 429) {
                $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? null;
                return [
                    'success' => false,
                    'error' => 'Cloudflare API rate limit exceeded (1200 requests/5min). Retry after ' . ($retryAfter ?? '60') . ' seconds.',
                    'statusCode' => 429,
                    'retryAfter' => $retryAfter ? (int) $retryAfter : 60,
                ];
            }

            // DELETE and some endpoints may return non-JSON on transport errors; parse defensively
            $body = $response->toArray(false);

            // Cloudflare envelope: success flag is authoritative, not HTTP status
            if (!($body['success'] ?? false)) {
                $errors = $body['errors'] ?? [];
                $messages = array_map(
                    fn($e) => ($e['code'] ?? '') . ': ' . ($e['message'] ?? 'unknown error'),
                    is_array($errors) ? $errors : []
                );
                $errorMsg = !empty($messages) ? implode('; ', $messages) : "HTTP $statusCode";
                return [
                    'success' => false,
                    'error' => 'Cloudflare API error: ' . $errorMsg,
                    'statusCode' => $statusCode,
                ];
            }

            return [
                'success' => true,
                'data' => $body['result'] ?? null,
                'resultInfo' => $body['result_info'] ?? null,
                'statusCode' => $statusCode,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Cloudflare API request failed', [
                'method' => $method,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'error' => 'Cloudflare API request failed: ' . $e->getMessage(),
            ];
        }
    }

    public function verifyToken(string $token): array
    {
        return $this->request('GET', '/user/tokens/verify', [], $token);
    }

    public function listZones(string $token, ?string $name = null, int $page = 1, int $perPage = 20): array
    {
        $query = ['page' => $page, 'per_page' => $perPage];
        if ($name) {
            $query['name'] = $name;
        }
        return $this->request('GET', '/zones', ['query' => $query], $token);
    }

    /**
     * Resolve Zone ID for a domain name. Returns null if no zone found.
     */
    public function getZoneIdByName(string $token, string $domain): ?string
    {
        $result = $this->listZones($token, $domain, 1, 1);
        if (!$result['success']) {
            return null;
        }
        $zones = $result['data'] ?? [];
        return $zones[0]['id'] ?? null;
    }

    public function createZone(string $token, string $name, ?string $accountId = null, bool $jumpStart = true): array
    {
        $body = ['name' => $name, 'jump_start' => $jumpStart];
        if ($accountId) {
            $body['account'] = ['id' => $accountId];
        }
        return $this->request('POST', '/zones', ['json' => $body], $token);
    }

    public function listDnsRecords(string $token, string $zoneId, array $filters = []): array
    {
        return $this->request('GET', '/zones/' . urlencode($zoneId) . '/dns_records', [
            'query' => $filters,
        ], $token);
    }

    public function createDnsRecord(string $token, string $zoneId, array $record): array
    {
        return $this->request('POST', '/zones/' . urlencode($zoneId) . '/dns_records', [
            'json' => $record,
        ], $token);
    }

    public function getDnsRecord(string $token, string $zoneId, string $recordId): array
    {
        return $this->request('GET', '/zones/' . urlencode($zoneId) . '/dns_records/' . urlencode($recordId), [], $token);
    }

    /**
     * Full replace (PUT) or partial update (PATCH) of a DNS record.
     */
    public function updateDnsRecord(string $token, string $zoneId, string $recordId, array $data, bool $partial = false): array
    {
        $method = $partial ? 'PATCH' : 'PUT';
        return $this->request($method, '/zones/' . urlencode($zoneId) . '/dns_records/' . urlencode($recordId), [
            'json' => $data,
        ], $token);
    }

    public function deleteDnsRecord(string $token, string $zoneId, string $recordId): array
    {
        return $this->request('DELETE', '/zones/' . urlencode($zoneId) . '/dns_records/' . urlencode($recordId), [], $token);
    }

    /**
     * Batch DNS operations. Max 200 operations per batch.
     * Executed in order: delete → create → update.
     *
     * @param array $operations ['deletes' => [...], 'posts' => [...], 'puts' => [...], 'patches' => [...]]
     */
    public function batchDnsRecords(string $token, string $zoneId, array $operations): array
    {
        return $this->request('POST', '/zones/' . urlencode($zoneId) . '/dns_records/batch', [
            'json' => $operations,
        ], $token);
    }

    /**
     * Convenience wrapper: create A-records for subdomains pointing to a VPS IP via batch.
     * Defaults: ttl=1 (auto), proxied=false (DNS-only, Coolify handles SSL).
     */
    public function addSubdomainARecords(string $token, string $zoneId, string $vpsIp, array $subdomains, bool $proxied = false): array
    {
        $posts = [];
        foreach ($subdomains as $sub) {
            $posts[] = [
                'type' => 'A',
                'name' => $sub,
                'content' => $vpsIp,
                'ttl' => 1,
                'proxied' => $proxied,
            ];
        }
        return $this->batchDnsRecords($token, $zoneId, ['posts' => $posts]);
    }
}
