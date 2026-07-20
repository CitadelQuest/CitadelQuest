<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Thin HTTP client for the Hostinger API.
 * Reads token from caller (resolved by AIToolHostingerService from ai_tool_settings).
 *
 * @see https://developers.hostinger.com/
 */
class HostingerApiService
{
    private const BASE_URL = 'https://developers.hostinger.com';
    private const HTTP_TIMEOUT = 20;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger
    ) {
    }

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
                $retryAfter = $response->getHeaders()['retry-after'][0] ?? null;
                return [
                    'success' => false,
                    'error' => 'Hostinger API rate limit exceeded (10 requests/min). Retry after ' . ($retryAfter ?? '60') . ' seconds.',
                    'statusCode' => 429,
                    'retryAfter' => $retryAfter ? (int) $retryAfter : 60,
                ];
            }

            if ($statusCode >= 400) {
                $body = $response->toArray(false);
                $msg = $body['message'] ?? $response->getContent(false);
                $this->logger->error('Hostinger API error response', [
                    'method' => $method,
                    'url' => $url,
                    'statusCode' => $statusCode,
                    'body' => is_array($body) ? json_encode($body) : (string) $body,
                ]);
                return [
                    'success' => false,
                    'error' => "Hostinger API error (HTTP $statusCode): $msg",
                    'statusCode' => $statusCode,
                ];
            }

            if ($statusCode === 204) {
                return ['success' => true, 'statusCode' => 204];
            }

            return [
                'success' => true,
                'data' => $response->toArray(false),
                'statusCode' => $statusCode,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Hostinger API request failed', [
                'method' => $method,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'error' => 'Hostinger API request failed: ' . $e->getMessage(),
            ];
        }
    }

    public function checkDomainAvailability(string $token, string $domain, bool $withAlternatives = false): array
    {
        // Hostinger's availability API expects the SLD and TLD(s) separately,
        // e.g. {"domain": "nabike", "tlds": ["sk"]} — not the full "nabike.sk".
        $domain = strtolower(trim($domain));
        $dotPos = strpos($domain, '.');
        if ($dotPos === false) {
            return [
                'success' => false,
                'error' => "Invalid domain '{$domain}': expected a name with a TLD, e.g. 'example.com'.",
            ];
        }
        $sld = substr($domain, 0, $dotPos);
        $tld = substr($domain, $dotPos + 1);

        return $this->request('POST', '/api/domains/v1/availability', [
            'json' => [
                'domain' => $sld,
                'tlds' => [$tld],
                'with_alternatives' => $withAlternatives,
            ],
        ], $token);
    }

    public function listDomains(string $token): array
    {
        return $this->request('GET', '/api/domains/v1/portfolio', [], $token);
    }

    public function getDomain(string $token, string $domain): array
    {
        return $this->request('GET', '/api/domains/v1/portfolio/' . urlencode($domain), [], $token);
    }

    public function registerDomain(string $token, string $domain, ?int $paymentMethodId = null, ?int $whoisProfileId = null): array
    {
        $body = ['domain' => $domain];
        if ($paymentMethodId) {
            $body['payment_method_id'] = $paymentMethodId;
        }
        if ($whoisProfileId) {
            $body['whois_profile_id'] = $whoisProfileId;
        }
        return $this->request('POST', '/api/domains/v1/portfolio', [
            'json' => $body,
        ], $token);
    }

    public function getDnsRecords(string $token, string $domain): array
    {
        return $this->request('GET', '/api/dns/v1/zones/' . urlencode($domain), [], $token);
    }

    public function setDnsRecords(string $token, string $domain, array $records, bool $overwrite = false): array
    {
        return $this->request('PUT', '/api/dns/v1/zones/' . urlencode($domain), [
            'json' => [
                'zone' => $records,
                'overwrite' => $overwrite,
            ],
        ], $token);
    }

    public function validateDns(string $token, string $domain, array $records): array
    {
        return $this->request('POST', '/api/dns/v1/zones/' . urlencode($domain) . '/validate', [
            'json' => ['zone' => $records],
        ], $token);
    }

    public function updateNameservers(string $token, string $domain, array $nameservers): array
    {
        $body = [
            'ns1' => $nameservers['ns1'] ?? null,
            'ns2' => $nameservers['ns2'] ?? null,
            'ns3' => $nameservers['ns3'] ?? null,
            'ns4' => $nameservers['ns4'] ?? null,
        ];
        return $this->request('PUT', '/api/domains/v1/portfolio/' . urlencode($domain) . '/nameservers', [
            'json' => $body,
        ], $token);
    }

    public function listVps(string $token): array
    {
        return $this->request('GET', '/api/vps/v1/virtual-machines', [], $token);
    }

    public function getVps(string $token, string $vmId): array
    {
        return $this->request('GET', '/api/vps/v1/virtual-machines/' . urlencode($vmId), [], $token);
    }

    /**
     * Convenience wrapper: build A-record zone payload for subdomains pointing to a VPS IP.
     */
    public function addSubdomainARecords(string $token, string $domain, string $vpsIp, array $subdomains): array
    {
        $records = [];
        foreach ($subdomains as $sub) {
            $records[] = [
                'type' => 'A',
                'name' => $sub,
                'content' => $vpsIp,
                'ttl' => 3600,
            ];
        }
        return $this->setDnsRecords($token, $domain, $records, false);
    }
}
