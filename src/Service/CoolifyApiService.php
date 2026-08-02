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
                $errorDetail = [
                    'success' => false,
                    'error' => "Coolify API error (HTTP $statusCode): $msg",
                    'statusCode' => $statusCode,
                ];
                if (isset($body['errors']) && is_array($body['errors'])) {
                    $errorDetail['validationErrors'] = $body['errors'];
                    $fieldErrors = [];
                    foreach ($body['errors'] as $field => $messages) {
                        if (is_array($messages)) {
                            $fieldErrors[] = $field . ': ' . implode(', ', $messages);
                        } else {
                            $fieldErrors[] = $field . ': ' . $messages;
                        }
                    }
                    if (!empty($fieldErrors)) {
                        $errorDetail['error'] .= ' | Validation: ' . implode('; ', $fieldErrors);
                    }
                }
                return $errorDetail;
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

    public function listDestinations(string $baseUrl, string $token, ?string $serverUuid = null): array
    {
        if ($serverUuid) {
            return $this->request('GET', $baseUrl, '/servers/' . urlencode($serverUuid) . '/destinations', [], $token);
        }
        return $this->request('GET', $baseUrl, '/destinations', [], $token);
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

    public function setEnv(string $baseUrl, string $token, string $appUuid, string $key, string $value, ?bool $buildTime = null): array
    {
        $payload = [
            'key' => $key,
            'value' => $value,
        ];
        if ($buildTime !== null) {
            $payload['is_build_time'] = $buildTime;
        }
        return $this->request('POST', $baseUrl, '/applications/' . urlencode($appUuid) . '/envs', [
            'json' => $payload,
        ], $token);
    }

    public function bulkSetEnv(string $baseUrl, string $token, string $appUuid, array $envs): array
    {
        return $this->request('PATCH', $baseUrl, '/applications/' . urlencode($appUuid) . '/envs/bulk', [
            'json' => ['data' => $envs],
        ], $token);
    }

    public function deploy(string $baseUrl, string $token, string $appUuid, bool $force = false): array
    {
        return $this->request('POST', $baseUrl, '/deploy', [
            'json' => [
                'uuid' => $appUuid,
                'force' => $force,
            ],
        ], $token);
    }

    public function getDeployment(string $baseUrl, string $token, string $deploymentUuid): array
    {
        return $this->request('GET', $baseUrl, '/deployments/' . urlencode($deploymentUuid), [], $token);
    }

    public function listDeployments(string $baseUrl, string $token, string $appUuid = ''): array
    {
        $query = [];
        if ($appUuid !== '') {
            $query['application_uuid'] = $appUuid;
        }
        return $this->request('GET', $baseUrl, '/deployments', [
            'query' => $query,
        ], $token);
    }

    public function listAppDeployments(string $baseUrl, string $token, string $appUuid, int $take = 10): array
    {
        return $this->request('GET', $baseUrl, '/deployments/applications/' . urlencode($appUuid), [
            'query' => ['take' => $take],
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

    // ── Applications: list, create variants, logs, envs ──

    public function listApps(string $baseUrl, string $token): array
    {
        return $this->request('GET', $baseUrl, '/applications', [], $token);
    }

    public function createAppPublic(string $baseUrl, string $token, array $config): array
    {
        return $this->request('POST', $baseUrl, '/applications/public', [
            'json' => $config,
        ], $token);
    }

    public function createAppDockerfile(string $baseUrl, string $token, array $config): array
    {
        return $this->request('POST', $baseUrl, '/applications/dockerfile', [
            'json' => $config,
        ], $token);
    }

    public function createAppDockerImage(string $baseUrl, string $token, array $config): array
    {
        return $this->request('POST', $baseUrl, '/applications/dockerimage', [
            'json' => $config,
        ], $token);
    }

    public function createAppGithubApp(string $baseUrl, string $token, array $config): array
    {
        return $this->request('POST', $baseUrl, '/applications/private-github-app', [
            'json' => $config,
        ], $token);
    }

    public function getAppLogs(string $baseUrl, string $token, string $uuid): array
    {
        return $this->request('GET', $baseUrl, '/applications/' . urlencode($uuid) . '/logs', [], $token);
    }

    public function listEnvs(string $baseUrl, string $token, string $appUuid): array
    {
        return $this->request('GET', $baseUrl, '/applications/' . urlencode($appUuid) . '/envs', [], $token);
    }

    public function updateEnv(string $baseUrl, string $token, string $appUuid, string $envUuid, array $data): array
    {
        return $this->request('PATCH', $baseUrl, '/applications/' . urlencode($appUuid) . '/envs/' . urlencode($envUuid), [
            'json' => $data,
        ], $token);
    }

    public function deleteEnv(string $baseUrl, string $token, string $appUuid, string $envUuid): array
    {
        return $this->request('DELETE', $baseUrl, '/applications/' . urlencode($appUuid) . '/envs/' . urlencode($envUuid), [], $token);
    }

    // ── Projects: update, delete, environments ──

    public function updateProject(string $baseUrl, string $token, string $uuid, array $data): array
    {
        return $this->request('PATCH', $baseUrl, '/projects/' . urlencode($uuid), [
            'json' => $data,
        ], $token);
    }

    public function deleteProject(string $baseUrl, string $token, string $uuid): array
    {
        return $this->request('DELETE', $baseUrl, '/projects/' . urlencode($uuid), [], $token);
    }

    public function listEnvironments(string $baseUrl, string $token, string $projectUuid): array
    {
        return $this->request('GET', $baseUrl, '/projects/' . urlencode($projectUuid) . '/environments', [], $token);
    }

    public function createEnvironment(string $baseUrl, string $token, string $projectUuid, string $name): array
    {
        return $this->request('POST', $baseUrl, '/projects/' . urlencode($projectUuid) . '/environments', [
            'json' => ['name' => $name],
        ], $token);
    }

    public function getEnvironment(string $baseUrl, string $token, string $projectUuid, string $envNameOrUuid): array
    {
        return $this->request('GET', $baseUrl, '/projects/' . urlencode($projectUuid) . '/' . urlencode($envNameOrUuid), [], $token);
    }

    public function deleteEnvironment(string $baseUrl, string $token, string $projectUuid, string $envNameOrUuid): array
    {
        return $this->request('DELETE', $baseUrl, '/projects/' . urlencode($projectUuid) . '/environments/' . urlencode($envNameOrUuid), [], $token);
    }

    // ── Deployments: cancel ──

    public function cancelDeployment(string $baseUrl, string $token, string $deploymentUuid): array
    {
        return $this->request('POST', $baseUrl, '/deployments/' . urlencode($deploymentUuid) . '/cancel', [], $token);
    }

    // ── Applications: persistent storages ──

    public function listStorages(string $baseUrl, string $token, string $appUuid): array
    {
        return $this->request('GET', $baseUrl, '/applications/' . urlencode($appUuid) . '/storages', [], $token);
    }

    public function createStorage(string $baseUrl, string $token, string $appUuid, array $data): array
    {
        return $this->request('POST', $baseUrl, '/applications/' . urlencode($appUuid) . '/storages', [
            'json' => $data,
        ], $token);
    }

    public function updateStorage(string $baseUrl, string $token, string $appUuid, string $storageUuid, array $data): array
    {
        return $this->request('PATCH', $baseUrl, '/applications/' . urlencode($appUuid) . '/storages/' . urlencode($storageUuid), [
            'json' => $data,
        ], $token);
    }

    public function deleteStorage(string $baseUrl, string $token, string $appUuid, string $storageUuid): array
    {
        return $this->request('DELETE', $baseUrl, '/applications/' . urlencode($appUuid) . '/storages/' . urlencode($storageUuid), [], $token);
    }
}
