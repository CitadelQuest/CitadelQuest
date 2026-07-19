<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * AI Tool service for Coolify operations.
 * Wraps CoolifyApiService, resolves credentials from ai_tool_settings.
 *
 * Tool name: coolifyManage
 * Operations: createProject, listServers, createApp, setEnv, deploy,
 *             deploymentStatus, createSshKey, getApp, deleteApp
 */
class AIToolCoolifyService
{
    private const TOOL_NAME = 'coolifyManage';

    public function __construct(
        private readonly AiToolService $aiToolService,
        private readonly AiToolSettingsService $aiToolSettingsService,
        private readonly CoolifyApiService $coolifyApiService,
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
        $url = $this->getSetting('coolify.base_url');
        if (!$url) {
            return null;
        }
        return rtrim($url, '/');
    }

    private function resolveToken(): ?string
    {
        return $this->getSetting('coolify.api_token');
    }

    private function ensureConfig(): array
    {
        $baseUrl = $this->resolveBaseUrl();
        $token = $this->resolveToken();
        if (!$baseUrl || !$token) {
            return [
                'success' => false,
                'error' => 'Coolify is not configured. Set coolify.base_url and coolify.api_token in tool settings first.',
            ];
        }
        return ['baseUrl' => $baseUrl, 'token' => $token];
    }

    public function coolifyManage(array $arguments): array
    {
        $operation = $arguments['operation'] ?? null;
        if (!$operation) {
            return ['success' => false, 'error' => 'Missing required parameter: operation'];
        }

        return match ($operation) {
            'createProject' => $this->handleCreateProject($arguments),
            'listServers' => $this->handleListServers($arguments),
            'createApp' => $this->handleCreateApp($arguments),
            'setEnv' => $this->handleSetEnv($arguments),
            'deploy' => $this->handleDeploy($arguments),
            'deploymentStatus' => $this->handleDeploymentStatus($arguments),
            'createSshKey' => $this->handleCreateSshKey($arguments),
            'getApp' => $this->handleGetApp($arguments),
            'deleteApp' => $this->handleDeleteApp($arguments),
            default => ['success' => false, 'error' => "Unknown Coolify operation: {$operation}"],
        };
    }

    private function handleCreateProject(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$config['baseUrl'] ?? false) {
            return $config;
        }

        $name = $args['projectName'] ?? null;
        if (!$name) {
            return ['success' => false, 'error' => 'Missing required parameter: projectName'];
        }

        $result = $this->coolifyApiService->createProject($config['baseUrl'], $config['token'], $name, $args['description'] ?? null);
        if (!$result['success']) {
            return $result;
        }

        $project = $result['data'];
        return [
            'success' => true,
            'message' => "Project '{$name}' created",
            'project' => ['uuid' => $project['uuid'] ?? null, 'name' => $project['name'] ?? $name],
            '_frontendData' => $this->buildFrontendData('Project created', $name, $project['uuid'] ?? null),
        ];
    }

    private function handleListServers(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$config['baseUrl'] ?? false) {
            return $config;
        }

        $result = $this->coolifyApiService->listServers($config['baseUrl'], $config['token']);
        if (!$result['success']) {
            return $result;
        }

        $servers = $result['data'] ?? [];
        $list = array_map(fn($s) => [
            'uuid' => $s['uuid'] ?? null,
            'name' => $s['name'] ?? null,
            'ip' => $s['ip'] ?? null,
        ], is_array($servers) ? $servers : []);

        return [
            'success' => true,
            'servers' => $list,
            'count' => count($list),
        ];
    }

    private function handleCreateApp(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$config['baseUrl'] ?? false) {
            return $config;
        }

        $projectUuid = $args['projectUuid'] ?? null;
        $serverUuid = $args['serverUuid'] ?? null;
        $gitRepository = $args['gitRepository'] ?? null;
        $branch = $args['branch'] ?? 'main';
        if (!$projectUuid || !$serverUuid || !$gitRepository) {
            return ['success' => false, 'error' => 'Missing required parameters: projectUuid, serverUuid, gitRepository'];
        }

        $configPayload = [
            'project_uuid' => $projectUuid,
            'server_uuid' => $serverUuid,
            'git_repository' => $gitRepository,
            'git_branch' => $branch,
            'private_key_uuid' => $args['privateKeyUuid'] ?? null,
            'build_pack' => $args['buildPack'] ?? 'nixpacks',
            'name' => $args['appName'] ?? null,
            'domains' => $args['domains'] ?? null,
            'port' => $args['port'] ?? null,
        ];

        $result = $this->coolifyApiService->createAppFromPrivateDeployKey($config['baseUrl'], $config['token'], $configPayload);
        if (!$result['success']) {
            return $result;
        }

        $app = $result['data'];
        return [
            'success' => true,
            'message' => 'Application created successfully',
            'app' => [
                'uuid' => $app['uuid'] ?? null,
                'name' => $app['name'] ?? $args['appName'] ?? null,
                'domains' => $app['domains'] ?? null,
            ],
            '_frontendData' => $this->buildFrontendData('App created', $args['appName'] ?? $app['uuid'] ?? 'app', $gitRepository),
        ];
    }

    private function handleSetEnv(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$config['baseUrl'] ?? false) {
            return $config;
        }

        $appUuid = $args['appUuid'] ?? null;
        $envs = $args['envs'] ?? null;
        if (!$appUuid || !$envs) {
            return ['success' => false, 'error' => 'Missing required parameters: appUuid, envs (array of {key, value})'];
        }

        $result = $this->coolifyApiService->bulkSetEnv($config['baseUrl'], $config['token'], $appUuid, $envs);
        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'message' => 'Environment variables set successfully',
            'appUuid' => $appUuid,
            'envCount' => count($envs),
        ];
    }

    private function handleDeploy(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$config['baseUrl'] ?? false) {
            return $config;
        }

        $appUuid = $args['appUuid'] ?? null;
        if (!$appUuid) {
            return ['success' => false, 'error' => 'Missing required parameter: appUuid'];
        }

        $force = $args['force'] ?? false;
        $result = $this->coolifyApiService->deploy($config['baseUrl'], $config['token'], $appUuid, $force);
        if (!$result['success']) {
            return $result;
        }

        $deployment = $result['data'] ?? [];
        return [
            'success' => true,
            'message' => 'Deployment triggered',
            'deploymentUuid' => $deployment['deployment_uuid'] ?? $deployment['uuid'] ?? null,
            'appUuid' => $appUuid,
            '_frontendData' => $this->buildFrontendData('Deploy triggered', $appUuid, $deployment['deployment_uuid'] ?? null),
        ];
    }

    private function handleDeploymentStatus(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$config['baseUrl'] ?? false) {
            return $config;
        }

        $deploymentUuid = $args['deploymentUuid'] ?? null;
        if (!$deploymentUuid) {
            return ['success' => false, 'error' => 'Missing required parameter: deploymentUuid'];
        }

        $result = $this->coolifyApiService->getDeployment($config['baseUrl'], $config['token'], $deploymentUuid);
        if (!$result['success']) {
            return $result;
        }

        $deployment = $result['data'] ?? [];
        return [
            'success' => true,
            'status' => $deployment['status'] ?? null,
            'deploymentUuid' => $deploymentUuid,
            'createdAt' => $deployment['created_at'] ?? null,
            'updatedAt' => $deployment['updated_at'] ?? null,
        ];
    }

    private function handleCreateSshKey(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$config['baseUrl'] ?? false) {
            return $config;
        }

        $name = $args['keyName'] ?? null;
        $privateKey = $args['privateKey'] ?? null;
        if (!$name || !$privateKey) {
            return ['success' => false, 'error' => 'Missing required parameters: keyName, privateKey'];
        }

        $result = $this->coolifyApiService->createSshKey($config['baseUrl'], $config['token'], $name, $privateKey);
        if (!$result['success']) {
            return $result;
        }

        $key = $result['data'] ?? [];
        return [
            'success' => true,
            'message' => "SSH key '{$name}' added to Coolify",
            'keyUuid' => $key['uuid'] ?? null,
            '_frontendData' => $this->buildFrontendData('SSH key added', $name, null),
        ];
    }

    private function handleGetApp(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$config['baseUrl'] ?? false) {
            return $config;
        }

        $appUuid = $args['appUuid'] ?? null;
        if (!$appUuid) {
            return ['success' => false, 'error' => 'Missing required parameter: appUuid'];
        }

        $result = $this->coolifyApiService->getApp($config['baseUrl'], $config['token'], $appUuid);
        if (!$result['success']) {
            return $result;
        }

        $app = $result['data'] ?? [];
        return [
            'success' => true,
            'app' => [
                'uuid' => $app['uuid'] ?? null,
                'name' => $app['name'] ?? null,
                'status' => $app['status'] ?? null,
                'domains' => $app['domains'] ?? null,
                'gitRepository' => $app['git_repository'] ?? null,
                'gitBranch' => $app['git_branch'] ?? null,
            ],
        ];
    }

    private function handleDeleteApp(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$config['baseUrl'] ?? false) {
            return $config;
        }

        $appUuid = $args['appUuid'] ?? null;
        if (!$appUuid) {
            return ['success' => false, 'error' => 'Missing required parameter: appUuid'];
        }

        $result = $this->coolifyApiService->deleteApp($config['baseUrl'], $config['token'], $appUuid);
        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'message' => "App '{$appUuid}' deleted",
            '_frontendData' => $this->buildFrontendData('App deleted', $appUuid, null),
        ];
    }

    private function buildFrontendData(string $action, string $primary, ?string $secondary): string
    {
        $displayPrimary = htmlspecialchars($primary);
        $secondaryLine = $secondary ? '<div class="small text-muted"><i class="mdi mdi-link-variant me-1"></i>' . htmlspecialchars($secondary) . '</div>' : '';
        return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <div class="d-flex align-items-center">
        <i class="mdi mdi-cloud-outline text-cyber me-2"></i>
        <strong>$displayPrimary</strong>
    </div>
    $secondaryLine
</div>
HTML;
    }
}
