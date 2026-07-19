<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Thin HTTP client for the Gitea API.
 * Reads base URL + token from caller (resolved by AIToolGiteaService from ai_tool_settings).
 *
 * @see https://docs.gitea.com/api/
 */
class GiteaApiService
{
    private const HTTP_TIMEOUT = 20;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger
    ) {
    }

    private function request(string $method, string $baseUrl, string $path, array $options = [], ?string $token = null, ?string $basicAuth = null): array
    {
        $url = rtrim($baseUrl, '/') . '/api/v1' . $path;

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
        if ($token) {
            $headers['Authorization'] = 'token ' . $token;
        }
        if ($basicAuth) {
            $headers['Authorization'] = 'Basic ' . base64_encode($basicAuth);
        }

        $options['headers'] = array_merge($headers, $options['headers'] ?? []);
        $options['timeout'] = self::HTTP_TIMEOUT;

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 400) {
                $body = $response->toArray(false);
                $msg = $body['message'] ?? $response->getContent(false);
                return [
                    'success' => false,
                    'error' => "Gitea API error (HTTP $statusCode): $msg",
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
            $this->logger->error('Gitea API request failed', [
                'method' => $method,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'error' => 'Gitea API request failed: ' . $e->getMessage(),
            ];
        }
    }

    public function createUser(string $baseUrl, string $adminToken, array $data): array
    {
        return $this->request('POST', $baseUrl, '/admin/users', [
            'json' => $data,
        ], $adminToken);
    }

    public function getUser(string $baseUrl, string $adminToken, string $username): array
    {
        return $this->request('GET', $baseUrl, '/admin/users/' . urlencode($username), [], $adminToken);
    }

    public function updateUser(string $baseUrl, string $adminToken, string $username, array $data): array
    {
        return $this->request('PATCH', $baseUrl, '/admin/users/' . urlencode($username), [
            'json' => $data,
        ], $adminToken);
    }

    public function deleteUser(string $baseUrl, string $adminToken, string $username): array
    {
        return $this->request('DELETE', $baseUrl, '/admin/users/' . urlencode($username), [], $adminToken);
    }

    public function createUserToken(string $baseUrl, string $username, string $password, string $name, array $scopes = []): array
    {
        $body = ['name' => $name];
        if (!empty($scopes)) {
            $body['scopes'] = $scopes;
        }
        return $this->request('POST', $baseUrl, '/users/' . urlencode($username) . '/tokens', [
            'json' => $body,
        ], null, $username . ':' . $password);
    }

    public function createOrg(string $baseUrl, string $adminToken, array $data): array
    {
        return $this->request('POST', $baseUrl, '/orgs', [
            'json' => $data,
        ], $adminToken);
    }

    public function getOrg(string $baseUrl, string $token, string $org): array
    {
        return $this->request('GET', $baseUrl, '/orgs/' . urlencode($org), [], $token);
    }

    public function createOrgRepo(string $baseUrl, string $token, string $org, array $data): array
    {
        return $this->request('POST', $baseUrl, '/orgs/' . urlencode($org) . '/repos', [
            'json' => $data,
        ], $token);
    }

    public function addOrgMember(string $baseUrl, string $token, string $org, string $username): array
    {
        return $this->request('PUT', $baseUrl, '/orgs/' . urlencode($org) . '/members/' . urlencode($username), [], $token);
    }

    public function createTeam(string $baseUrl, string $token, string $org, array $data): array
    {
        return $this->request('POST', $baseUrl, '/orgs/' . urlencode($org) . '/teams', [
            'json' => $data,
        ], $token);
    }

    public function addTeamMember(string $baseUrl, string $token, int $teamId, string $username): array
    {
        return $this->request('PUT', $baseUrl, '/teams/' . $teamId . '/members/' . urlencode($username), [], $token);
    }

    public function getRepo(string $baseUrl, string $token, string $owner, string $repo): array
    {
        return $this->request('GET', $baseUrl, '/repos/' . urlencode($owner) . '/' . urlencode($repo), [], $token);
    }

    public function deleteRepo(string $baseUrl, string $token, string $owner, string $repo): array
    {
        return $this->request('DELETE', $baseUrl, '/repos/' . urlencode($owner) . '/' . urlencode($repo), [], $token);
    }

    public function searchRepos(string $baseUrl, string $token, string $query): array
    {
        return $this->request('GET', $baseUrl, '/repos/search', [
            'query' => ['q' => $query, 'limit' => 10],
        ], $token);
    }

    public function createWebhook(string $baseUrl, string $token, string $owner, string $repo, string $url, string $secret): array
    {
        return $this->request('POST', $baseUrl, '/repos/' . urlencode($owner) . '/' . urlencode($repo) . '/hooks', [
            'json' => [
                'type' => 'gitea',
                'active' => true,
                'events' => ['push'],
                'config' => [
                    'url' => $url,
                    'content_type' => 'json',
                    'secret' => $secret,
                ],
            ],
        ], $token);
    }

    public function addUserSshKey(string $baseUrl, string $token, string $username, string $title, string $key): array
    {
        return $this->request('POST', $baseUrl, '/users/' . urlencode($username) . '/keys', [
            'json' => [
                'title' => $title,
                'key' => $key,
            ],
        ], $token);
    }
}
