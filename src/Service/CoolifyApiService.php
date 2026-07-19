<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Thin HTTP client for the Coolify API.
 * Reads base URL + token from caller (resolved by AIToolCoolifyService from ai_tool_settings).
 *
 * @see https://coolify.io/docs/api-reference/
 */
class CoolifyApiService
{
    private const HTTP_TIMEOUT = 30;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger
    ) {
    }

    private function request(string $method, string $baseUrl, string $path, array $options = [], ?string $token = null): array
    {
        $url = rtrim($baseUrl, '/') . '/api/v1' . $path;

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
                    'error' => 'Coolify API rate limit exceeded. Retry after ' . ($retryAfter ?? '60') . ' seconds.',
                    'statusCode' => 429,
                    'retryAfter' => $retryAfter ? (int) $retryAfter : 60,
                ];
            }

            if ($statusCode >= 400) {
                $body = $response->toArray(false);
                $msg = $body['message'] ?? $response->getContent(false);
                return [
                    'success' => false,
                    'error' => "Coolify API error (HTTP $statusCode): $msg",
                    'statusCode' => $statusCode,
                ];
            }

            if ($statusCode === 204) {
                return ['success' => true, 'statusCode' => 204];
            }

            $data = $response->toArray(false);
            return [
                'success' => true,
                'data' => $data,
                'statusCode' => $statusCode,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Coolify API request failed', [
                'method' => $method,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'error' => 'Coolify API request failed: ' . $e->getMessage(),
            ];
        }
    }

    public function listProjects(string $baseUrl, string $token): array
    {
        return $this->request('GET', $baseUrl, '/projects', [], $token);
    }

    public function createProject(string $baseUrl, string $token, string $name, ?string $description = null): array
    {
        return $this->request('POST', $baseUrl, '/projects', [
            'json' => [
                'name' => $name,
                'description' => $description,
            ],
        ], $token);
    }

    public function getProject(string $baseUrl, string $token, string $uuid): array
    {
        return $this->request('GET', $baseUrl, '/projects/' . urlencode($uuid), [], $token);
    }

    public function listServers(string $baseUrl, string $token): array
    {
        return $this->request('GET', $baseUrl, '/servers', [], $token);
    }

    public function getServer(string $baseUrl, string $token, string $uuid): array
    {
        return $this->request('GET', $baseUrl, '/servers/' . urlencode($uuid), [], $token);
    }

    public function getServerResources(string $baseUrl, string $token, string $uuid): array
    {
        return $this->request('GET', $baseUrl, '/servers/' . urlencode($uuid) . '/resources', [], $token);
    }

    public function getServerDomains(string $baseUrl, string $token, string $uuid): array
    {
        return $this->request('GET', $baseUrl, '/servers/' . urlencode($uuid) . '/domains', [], $token);
    }

    public function createSshKey(string $baseUrl, string $token, string $name, string $privateKey): array
    {
        return $this->request('POST', $baseUrl, '/security/keys', [
            'json' => [
                'name' => $name,
                'private_key' => $privateKey,
            ],
        ], $token);
    }

    public function createAppFromPrivateDeployKey(string $baseUrl, string $token, array $config): array
    {
        return $this->request('POST', $baseUrl, '/applications/private-deploy-key', [
            'json' => $config,
        ], $token);
    }

    public function getApp(string $baseUrl, string $token, string $uuid): array
    {
        return $this->request('GET', $baseUrl, '/applications/' . urlencode($uuid), [], $token);
    }

    public function updateApp(string $baseUrl, string $token, string $uuid, array $data): array
    {
        return $this->request('PATCH', $baseUrl, '/applications/' . urlencode($uuid), [
            'json' => $data,
        ], $token);
    }

    public function deleteApp(string $baseUrl, string $token, string $uuid): array
    {
        return $this->request('DELETE', $baseUrl, '/applications/' . urlencode($uuid), [], $token);
    }

    public function setEnv(string $baseUrl, string $token, string $appUuid, string $key, string $value, bool $buildTime = false): array
    {
        return $this->request('POST', $baseUrl, '/applications/' . urlencode($appUuid) . '/envs', [
            'json' => [
                'key' => $key,
                'value' => $value,
                'is_build_time' => $buildTime,
            ],
        ], $token);
    }

    public function bulkSetEnv(string $baseUrl, string $token, string $appUuid, array $envs): array
    {
        return $this->request('POST', $baseUrl, '/applications/' . urlencode($appUuid) . '/envs/bulk', [
            'json' => ['envs' => $envs],
        ], $token);
    }

    public function deploy(string $baseUrl, string $token, string $appUuid, bool $force = false): array
    {
        return $this->request('POST', $baseUrl, '/deploy', [
            'json' => [
                'application_uuid' => $appUuid,
                'force' => $force,
            ],
        ], $token);
    }

    public function getDeployment(string $baseUrl, string $token, string $deploymentUuid): array
    {
        return $this->request('GET', $baseUrl, '/deployments/' . urlencode($deploymentUuid), [], $token);
    }

    public function listDeployments(string $baseUrl, string $token, string $appUuid): array
    {
        return $this->request('GET', $baseUrl, '/deployments', [
            'query' => ['application_uuid' => $appUuid],
        ], $token);
    }

    public function startApp(string $baseUrl, string $token, string $uuid): array
    {
        return $this->request('POST', $baseUrl, '/applications/' . urlencode($uuid) . '/start', [], $token);
    }

    public function restartApp(string $baseUrl, string $token, string $uuid): array
    {
        return $this->request('POST', $baseUrl, '/applications/' . urlencode($uuid) . '/restart', [], $token);
    }

    public function stopApp(string $baseUrl, string $token, string $uuid): array
    {
        return $this->request('POST', $baseUrl, '/applications/' . urlencode($uuid) . '/stop', [], $token);
    }
}
