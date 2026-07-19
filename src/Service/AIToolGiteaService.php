<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * AI Tool service for Gitea operations.
 * Wraps GiteaApiService, resolves credentials from ai_tool_settings.
 *
 * Tool name: giteaManage
 * Operations: createUser, createUserToken, createOrg, createRepo,
 *             addMember, createWebhook, getRepo, deleteRepo, searchRepos
 */
class AIToolGiteaService
{
    private const TOOL_NAME = 'giteaManage';

    public function __construct(
        private readonly AiToolService $aiToolService,
        private readonly AiToolSettingsService $aiToolSettingsService,
        private readonly GiteaApiService $giteaApiService,
        private readonly LoggerInterface $logger
    ) {
    }

    private function getSetting(string $key): ?string
    {
        $tool = $this->aiToolService->findByName(self::TOOL_NAME);
        if (!$tool) {
            return null;
        }
        return $this->aiToolSettingsService->getSettingValue($tool->getId(), $key);
    }

    private function resolveBaseUrl(): ?string
    {
        $url = $this->getSetting('gitea.base_url');
        if (!$url) {
            return null;
        }
        return rtrim($url, '/');
    }

    private function resolveAdminToken(): ?string
    {
        return $this->getSetting('gitea.admin_token');
    }

    private function ensureConfig(): array
    {
        $baseUrl = $this->resolveBaseUrl();
        $token = $this->resolveAdminToken();
        if (!$baseUrl || !$token) {
            return [
                'success' => false,
                'error' => 'Gitea is not configured. Set gitea.base_url and gitea.admin_token in tool settings first.',
            ];
        }
        return ['baseUrl' => $baseUrl, 'token' => $token];
    }

    public function giteaManage(array $arguments): array
    {
        $operation = $arguments['operation'] ?? null;
        if (!$operation) {
            return ['success' => false, 'error' => 'Missing required parameter: operation'];
        }

        return match ($operation) {
            'createUser' => $this->handleCreateUser($arguments),
            'createUserToken' => $this->handleCreateUserToken($arguments),
            'createOrg' => $this->handleCreateOrg($arguments),
            'createRepo' => $this->handleCreateRepo($arguments),
            'addMember' => $this->handleAddMember($arguments),
            'createWebhook' => $this->handleCreateWebhook($arguments),
            'getRepo' => $this->handleGetRepo($arguments),
            'deleteRepo' => $this->handleDeleteRepo($arguments),
            'searchRepos' => $this->handleSearchRepos($arguments),
            default => ['success' => false, 'error' => "Unknown Gitea operation: {$operation}"],
        };
    }

    private function handleCreateUser(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$config['baseUrl'] ?? false) {
            return $config;
        }

        $username = $args['username'] ?? null;
        $email = $args['email'] ?? null;
        $password = $args['password'] ?? null;
        if (!$username || !$email || !$password) {
            return ['success' => false, 'error' => 'Missing required parameters: username, email, password'];
        }

        $data = [
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'must_change_password' => false,
        ];
        if (isset($args['fullName'])) {
            $data['full_name'] = $args['fullName'];
        }

        $result = $this->giteaApiService->createUser($config['baseUrl'], $config['token'], $data);
        if (!$result['success']) {
            return $result;
        }

        $user = $result['data'];
        return [
            'success' => true,
            'message' => "User '{$username}' created successfully",
            'user' => ['id' => $user['id'] ?? null, 'username' => $user['login'] ?? $username, 'email' => $user['email'] ?? $email],
            '_frontendData' => $this->buildFrontendData('User created', $username, $email),
        ];
    }

    private function handleCreateUserToken(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$config['baseUrl'] ?? false) {
            return $config;
        }

        $username = $args['username'] ?? null;
        $password = $args['password'] ?? null;
        $tokenName = $args['tokenName'] ?? 'cq-spirit';
        if (!$username || !$password) {
            return ['success' => false, 'error' => 'Missing required parameters: username, password'];
        }

        $scopes = $args['scopes'] ?? ['read:repository', 'write:repository', 'read:user', 'write:user'];

        $result = $this->giteaApiService->createUserToken($config['baseUrl'], $username, $password, $tokenName, $scopes);
        if (!$result['success']) {
            return $result;
        }

        $tokenData = $result['data'];
        $token = $tokenData['sha1'] ?? null;
        if (!$token) {
            return ['success' => false, 'error' => 'Token created but no token value returned'];
        }

        return [
            'success' => true,
            'message' => "Token '{$tokenName}' created for user '{$username}'",
            'token' => $token,
            'tokenId' => $tokenData['id'] ?? null,
            'note' => 'Token is only shown once. Store it securely.',
            '_frontendData' => $this->buildFrontendData('Token created', $tokenName, $username),
        ];
    }

    private function handleCreateOrg(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$config['baseUrl'] ?? false) {
            return $config;
        }

        $orgName = $args['orgName'] ?? null;
        if (!$orgName) {
            return ['success' => false, 'error' => 'Missing required parameter: orgName'];
        }

        $data = [
            'username' => $orgName,
            'visibility' => $args['visibility'] ?? 'private',
        ];
        if (isset($args['description'])) {
            $data['description'] = $args['description'];
        }

        $result = $this->giteaApiService->createOrg($config['baseUrl'], $config['token'], $data);
        if (!$result['success']) {
            return $result;
        }

        $org = $result['data'];
        return [
            'success' => true,
            'message' => "Organization '{$orgName}' created successfully",
            'org' => ['id' => $org['id'] ?? null, 'name' => $org['username'] ?? $orgName],
            '_frontendData' => $this->buildFrontendData('Organization created', $orgName, $args['description'] ?? null),
        ];
    }

    private function handleCreateRepo(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$config['baseUrl'] ?? false) {
            return $config;
        }

        $org = $args['orgName'] ?? null;
        $repoName = $args['repoName'] ?? null;
        if (!$org || !$repoName) {
            return ['success' => false, 'error' => 'Missing required parameters: orgName, repoName'];
        }

        $data = [
            'name' => $repoName,
            'private' => $args['private'] ?? true,
            'auto_init' => $args['autoInit'] ?? true,
            'default_branch' => $args['defaultBranch'] ?? 'main',
        ];
        if (isset($args['description'])) {
            $data['description'] = $args['description'];
        }

        $result = $this->giteaApiService->createOrgRepo($config['baseUrl'], $config['token'], $org, $data);
        if (!$result['success']) {
            return $result;
        }

        $repo = $result['data'];
        $cloneUrl = $repo['clone_url'] ?? null;
        return [
            'success' => true,
            'message' => "Repository '{$org}/{$repoName}' created successfully",
            'repo' => [
                'id' => $repo['id'] ?? null,
                'name' => $repo['name'] ?? $repoName,
                'fullName' => $repo['full_name'] ?? "{$org}/{$repoName}",
                'cloneUrl' => $cloneUrl,
                'private' => $repo['private'] ?? true,
            ],
            '_frontendData' => $this->buildFrontendData('Repository created', "{$org}/{$repoName}", $cloneUrl),
        ];
    }

    private function handleAddMember(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$config['baseUrl'] ?? false) {
            return $config;
        }

        $org = $args['orgName'] ?? null;
        $username = $args['username'] ?? null;
        if (!$org || !$username) {
            return ['success' => false, 'error' => 'Missing required parameters: orgName, username'];
        }

        $result = $this->giteaApiService->addOrgMember($config['baseUrl'], $config['token'], $org, $username);
        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'message' => "User '{$username}' added to organization '{$org}'",
            '_frontendData' => $this->buildFrontendData('Member added', $username, $org),
        ];
    }

    private function handleCreateWebhook(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$config['baseUrl'] ?? false) {
            return $config;
        }

        $owner = $args['owner'] ?? null;
        $repo = $args['repoName'] ?? null;
        $url = $args['webhookUrl'] ?? null;
        $secret = $args['webhookSecret'] ?? '';
        if (!$owner || !$repo || !$url) {
            return ['success' => false, 'error' => 'Missing required parameters: owner, repoName, webhookUrl'];
        }

        $result = $this->giteaApiService->createWebhook($config['baseUrl'], $config['token'], $owner, $repo, $url, $secret);
        if (!$result['success']) {
            return $result;
        }

        $hook = $result['data'];
        return [
            'success' => true,
            'message' => "Webhook created for '{$owner}/{$repo}' → {$url}",
            'webhook' => ['id' => $hook['id'] ?? null, 'url' => $url],
            '_frontendData' => $this->buildFrontendData('Webhook created', "{$owner}/{$repo}", $url),
        ];
    }

    private function handleGetRepo(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$config['baseUrl'] ?? false) {
            return $config;
        }

        $owner = $args['owner'] ?? null;
        $repo = $args['repoName'] ?? null;
        if (!$owner || !$repo) {
            return ['success' => false, 'error' => 'Missing required parameters: owner, repoName'];
        }

        $result = $this->giteaApiService->getRepo($config['baseUrl'], $config['token'], $owner, $repo);
        if (!$result['success']) {
            return $result;
        }

        $repoData = $result['data'];
        return [
            'success' => true,
            'repo' => [
                'id' => $repoData['id'] ?? null,
                'name' => $repoData['name'] ?? $repo,
                'fullName' => $repoData['full_name'] ?? "{$owner}/{$repo}",
                'cloneUrl' => $repoData['clone_url'] ?? null,
                'private' => $repoData['private'] ?? null,
                'defaultBranch' => $repoData['default_branch'] ?? null,
            ],
        ];
    }

    private function handleDeleteRepo(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$config['baseUrl'] ?? false) {
            return $config;
        }

        $owner = $args['owner'] ?? null;
        $repo = $args['repoName'] ?? null;
        if (!$owner || !$repo) {
            return ['success' => false, 'error' => 'Missing required parameters: owner, repoName'];
        }

        $result = $this->giteaApiService->deleteRepo($config['baseUrl'], $config['token'], $owner, $repo);
        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'message' => "Repository '{$owner}/{$repo}' deleted",
            '_frontendData' => $this->buildFrontendData('Repository deleted', "{$owner}/{$repo}", null),
        ];
    }

    private function handleSearchRepos(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$config['baseUrl'] ?? false) {
            return $config;
        }

        $query = $args['query'] ?? null;
        if (!$query) {
            return ['success' => false, 'error' => 'Missing required parameter: query'];
        }

        $result = $this->giteaApiService->searchRepos($config['baseUrl'], $config['token'], $query);
        if (!$result['success']) {
            return $result;
        }

        $repos = $result['data']['data'] ?? [];
        $list = array_map(fn($r) => [
            'id' => $r['id'] ?? null,
            'fullName' => $r['full_name'] ?? null,
            'cloneUrl' => $r['clone_url'] ?? null,
            'private' => $r['private'] ?? null,
        ], $repos);

        return [
            'success' => true,
            'repos' => $list,
            'count' => count($list),
        ];
    }

    private function buildFrontendData(string $action, string $primary, ?string $secondary): string
    {
        $displayPrimary = htmlspecialchars($primary);
        $secondaryLine = $secondary ? '<div class="small text-muted"><i class="mdi mdi-link-variant me-1"></i>' . htmlspecialchars($secondary) . '</div>' : '';
        return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <div class="d-flex align-items-center">
        <i class="mdi mdi-git text-cyber me-2"></i>
        <strong>$displayPrimary</strong>
    </div>
    $secondaryLine
</div>
HTML;
    }
}
