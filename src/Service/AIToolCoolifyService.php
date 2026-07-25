<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * AI Tool service for Coolify operations.
 * Wraps CoolifyApiService, resolves credentials from ai_tool_settings.
 *
 * Tools:
 *   coolifyManage             - listServers, createSshKey
 *   coolifyManageApplications - list, get, create*, update, delete, start, stop, restart, logs, envs
 *   coolifyManageDeployments  - deploy, get, listByApp, listRunning, cancel
 *   coolifyManageProjects     - list, get, create, update, delete, listEnvironments, createEnvironment, getEnvironment, deleteEnvironment
 *
 * All tools share credentials stored under the coolifyManage tool settings.
 */
class AIToolCoolifyService
{
    private const SETTINGS_TOOL_NAME = 'coolifyManage';

    public function __construct(
        private readonly AiToolService $aiToolService,
        private readonly AiToolSettingsService $aiToolSettingsService,
        private readonly CoolifyApiService $coolifyApiService,
        private readonly LoggerInterface $logger
    ) {
    }

    private function getSetting(string $key): ?string
    {
        $tool = $this->aiToolService->findByName(self::SETTINGS_TOOL_NAME);
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
                'error' => 'Coolify is not configured. Set coolify.base_url and coolify.api_token in coolifyManage tool settings first.',
            ];
        }
        return ['baseUrl' => $baseUrl, 'token' => $token];
    }

    private function configOk(array $config): bool
    {
        return isset($config['baseUrl']) && $config['baseUrl'];
    }

    // ════════════════════════════════════════════════════════════════════
    //  coolifyManage — slim: listServers, createSshKey
    // ════════════════════════════════════════════════════════════════════

    public function coolifyManage(array $arguments): array
    {
        $operation = $arguments['operation'] ?? null;
        if (!$operation) {
            return ['success' => false, 'error' => 'Missing required parameter: operation'];
        }

        return match ($operation) {
            'listServers' => $this->handleListServers($arguments),
            'createSshKey' => $this->handleCreateSshKey($arguments),
            default => ['success' => false, 'error' => "Unknown coolifyManage operation: {$operation}"],
        };
    }

    private function handleListServers(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
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

        $items = array_map(fn($s) => [
            'icon' => 'mdi-server',
            'label' => $s['name'] ?? ($s['uuid'] ?? '(unknown)'),
            'meta' => $s['ip'] ?? null,
        ], $list);

        return [
            'success' => true,
            'servers' => $list,
            'count' => count($list),
            '_frontendData' => $this->buildListFrontendData('coolifyManage', 'listServers', count($list) . ' server(s)', $items),
        ];
    }

    private function handleCreateSshKey(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
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
            '_frontendData' => $this->buildFrontendData('coolifyManage', 'SSH key added', $name, null),
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    //  coolifyManageApplications — full application lifecycle
    // ════════════════════════════════════════════════════════════════════

    public function coolifyManageApplications(array $arguments): array
    {
        $operation = $arguments['operation'] ?? null;
        if (!$operation) {
            return ['success' => false, 'error' => 'Missing required parameter: operation'];
        }

        return match ($operation) {
            'list' => $this->handleAppList($arguments),
            'get' => $this->handleAppGet($arguments),
            'createPublic' => $this->handleAppCreatePublic($arguments),
            'createPrivateDeployKey' => $this->handleAppCreatePrivateDeployKey($arguments),
            'createPrivateGithubApp' => $this->handleAppCreatePrivateGithubApp($arguments),
            'createDockerfile' => $this->handleAppCreateDockerfile($arguments),
            'createDockerImage' => $this->handleAppCreateDockerImage($arguments),
            'update' => $this->handleAppUpdate($arguments),
            'delete' => $this->handleAppDelete($arguments),
            'start' => $this->handleAppStart($arguments),
            'stop' => $this->handleAppStop($arguments),
            'restart' => $this->handleAppRestart($arguments),
            'getLogs' => $this->handleAppGetLogs($arguments),
            'listEnvs' => $this->handleAppListEnvs($arguments),
            'createEnv' => $this->handleAppCreateEnv($arguments),
            'updateEnv' => $this->handleAppUpdateEnv($arguments),
            'deleteEnv' => $this->handleAppDeleteEnv($arguments),
            'bulkSetEnvs' => $this->handleAppBulkSetEnvs($arguments),
            default => ['success' => false, 'error' => "Unknown coolifyManageApplications operation: {$operation}"],
        };
    }

    private function handleAppList(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
            return $config;
        }

        $result = $this->coolifyApiService->listApps($config['baseUrl'], $config['token']);
        if (!$result['success']) {
            return $result;
        }

        $apps = $result['data'] ?? [];
        $list = array_map(fn($a) => [
            'uuid' => $a['uuid'] ?? null,
            'name' => $a['name'] ?? null,
            'status' => $a['status'] ?? null,
            'domains' => $a['domains'] ?? null,
        ], is_array($apps) ? $apps : []);

        $items = array_map(fn($a) => [
            'icon' => 'mdi-apps',
            'label' => $a['name'] ?? ($a['uuid'] ?? '(unknown)'),
            'meta' => $a['status'] ?? null,
        ], $list);

        return [
            'success' => true,
            'applications' => $list,
            'count' => count($list),
            '_frontendData' => $this->buildListFrontendData('coolifyManageApplications', 'list', count($list) . ' app(s)', $items),
        ];
    }

    private function handleAppGet(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
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
        $appName = $app['name'] ?? ($app['uuid'] ?? $appUuid);
        $statusLine = trim(($app['status'] ?? '') . ' ' . ($app['domains'] ?? ''));
        return [
            'success' => true,
            'app' => [
                'uuid' => $app['uuid'] ?? null,
                'name' => $app['name'] ?? null,
                'status' => $app['status'] ?? null,
                'domains' => $app['domains'] ?? null,
                'gitRepository' => $app['git_repository'] ?? null,
                'gitBranch' => $app['git_branch'] ?? null,
                'buildPack' => $app['build_pack'] ?? null,
            ],
            '_frontendData' => $this->buildFrontendData('coolifyManageApplications', 'Application', $appName, $statusLine !== '' ? $statusLine : null),
        ];
    }

    private function handleAppCreatePublic(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
            return $config;
        }

        $projectUuid = $args['projectUuid'] ?? null;
        $serverUuid = $args['serverUuid'] ?? null;
        $gitRepository = $args['gitRepository'] ?? null;
        if (!$projectUuid || !$serverUuid || !$gitRepository) {
            return ['success' => false, 'error' => 'Missing required parameters: projectUuid, serverUuid, gitRepository'];
        }

        $payload = [
            'project_uuid' => $projectUuid,
            'server_uuid' => $serverUuid,
            'environment_name' => $args['environmentName'] ?? null,
            'environment_uuid' => $args['environmentUuid'] ?? null,
            'git_repository' => $gitRepository,
            'git_branch' => $args['branch'] ?? 'main',
            'build_pack' => $args['buildPack'] ?? 'nixpacks',
            'name' => $args['appName'] ?? null,
            'domains' => $args['domains'] ?? null,
            'ports_exposes' => isset($args['port']) ? (string) $args['port'] : null,
        ];

        $result = $this->coolifyApiService->createAppPublic($config['baseUrl'], $config['token'], $payload);
        if (!$result['success']) {
            return $result;
        }

        $app = $result['data'];
        return [
            'success' => true,
            'message' => 'Application created from public repo',
            'app' => ['uuid' => $app['uuid'] ?? null, 'name' => $app['name'] ?? $args['appName'] ?? null],
            '_frontendData' => $this->buildFrontendData('coolifyManageApplications', 'App created (public)', $args['appName'] ?? $app['uuid'] ?? 'app', $gitRepository),
        ];
    }

    private function handleAppCreatePrivateDeployKey(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
            return $config;
        }

        $projectUuid = $args['projectUuid'] ?? null;
        $serverUuid = $args['serverUuid'] ?? null;
        $gitRepository = $args['gitRepository'] ?? null;
        $privateKeyUuid = $args['privateKeyUuid'] ?? null;
        if (!$projectUuid || !$serverUuid || !$gitRepository || !$privateKeyUuid) {
            return ['success' => false, 'error' => 'Missing required parameters: projectUuid, serverUuid, gitRepository, privateKeyUuid'];
        }

        $payload = [
            'project_uuid' => $projectUuid,
            'server_uuid' => $serverUuid,
            'environment_name' => $args['environmentName'] ?? null,
            'environment_uuid' => $args['environmentUuid'] ?? null,
            'git_repository' => $gitRepository,
            'git_branch' => $args['branch'] ?? 'main',
            'private_key_uuid' => $privateKeyUuid,
            'build_pack' => $args['buildPack'] ?? 'nixpacks',
            'name' => $args['appName'] ?? null,
            'domains' => $args['domains'] ?? null,
            'ports_exposes' => isset($args['port']) ? (string) $args['port'] : null,
        ];

        $result = $this->coolifyApiService->createAppFromPrivateDeployKey($config['baseUrl'], $config['token'], $payload);
        if (!$result['success']) {
            return $result;
        }

        $app = $result['data'];
        return [
            'success' => true,
            'message' => 'Application created from private repo (deploy key)',
            'app' => ['uuid' => $app['uuid'] ?? null, 'name' => $app['name'] ?? $args['appName'] ?? null],
            '_frontendData' => $this->buildFrontendData('coolifyManageApplications', 'App created (private/deploy key)', $args['appName'] ?? $app['uuid'] ?? 'app', $gitRepository),
        ];
    }

    private function handleAppCreatePrivateGithubApp(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
            return $config;
        }

        $projectUuid = $args['projectUuid'] ?? null;
        $serverUuid = $args['serverUuid'] ?? null;
        $gitRepository = $args['gitRepository'] ?? null;
        $githubAppUuid = $args['githubAppUuid'] ?? null;
        if (!$projectUuid || !$serverUuid || !$gitRepository || !$githubAppUuid) {
            return ['success' => false, 'error' => 'Missing required parameters: projectUuid, serverUuid, gitRepository, githubAppUuid'];
        }

        $payload = [
            'project_uuid' => $projectUuid,
            'server_uuid' => $serverUuid,
            'environment_name' => $args['environmentName'] ?? null,
            'environment_uuid' => $args['environmentUuid'] ?? null,
            'git_repository' => $gitRepository,
            'git_branch' => $args['branch'] ?? 'main',
            'github_app_uuid' => $githubAppUuid,
            'build_pack' => $args['buildPack'] ?? 'nixpacks',
            'name' => $args['appName'] ?? null,
            'domains' => $args['domains'] ?? null,
            'ports_exposes' => isset($args['port']) ? (string) $args['port'] : null,
        ];

        $result = $this->coolifyApiService->createAppGithubApp($config['baseUrl'], $config['token'], $payload);
        if (!$result['success']) {
            return $result;
        }

        $app = $result['data'];
        return [
            'success' => true,
            'message' => 'Application created from private repo (GitHub App)',
            'app' => ['uuid' => $app['uuid'] ?? null, 'name' => $app['name'] ?? $args['appName'] ?? null],
            '_frontendData' => $this->buildFrontendData('coolifyManageApplications', 'App created (GitHub App)', $args['appName'] ?? $app['uuid'] ?? 'app', $gitRepository),
        ];
    }

    private function handleAppCreateDockerfile(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
            return $config;
        }

        $projectUuid = $args['projectUuid'] ?? null;
        $serverUuid = $args['serverUuid'] ?? null;
        $dockerfile = $args['dockerfile'] ?? null;
        if (!$projectUuid || !$serverUuid || !$dockerfile) {
            return ['success' => false, 'error' => 'Missing required parameters: projectUuid, serverUuid, dockerfile'];
        }

        $payload = [
            'project_uuid' => $projectUuid,
            'server_uuid' => $serverUuid,
            'environment_name' => $args['environmentName'] ?? null,
            'environment_uuid' => $args['environmentUuid'] ?? null,
            'dockerfile' => $dockerfile,
            'name' => $args['appName'] ?? null,
            'domains' => $args['domains'] ?? null,
            'ports_exposes' => isset($args['port']) ? (string) $args['port'] : null,
        ];

        $result = $this->coolifyApiService->createAppDockerfile($config['baseUrl'], $config['token'], $payload);
        if (!$result['success']) {
            return $result;
        }

        $app = $result['data'];
        return [
            'success' => true,
            'message' => 'Application created from Dockerfile',
            'app' => ['uuid' => $app['uuid'] ?? null, 'name' => $app['name'] ?? $args['appName'] ?? null],
            '_frontendData' => $this->buildFrontendData('coolifyManageApplications', 'App created (Dockerfile)', $args['appName'] ?? $app['uuid'] ?? 'app', null),
        ];
    }

    private function handleAppCreateDockerImage(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
            return $config;
        }

        $projectUuid = $args['projectUuid'] ?? null;
        $serverUuid = $args['serverUuid'] ?? null;
        $dockerImage = $args['dockerImage'] ?? null;
        if (!$projectUuid || !$serverUuid || !$dockerImage) {
            return ['success' => false, 'error' => 'Missing required parameters: projectUuid, serverUuid, dockerImage'];
        }

        $payload = [
            'project_uuid' => $projectUuid,
            'server_uuid' => $serverUuid,
            'environment_name' => $args['environmentName'] ?? null,
            'environment_uuid' => $args['environmentUuid'] ?? null,
            'image' => $dockerImage,
            'name' => $args['appName'] ?? null,
            'domains' => $args['domains'] ?? null,
            'ports_exposes' => isset($args['port']) ? (string) $args['port'] : null,
        ];

        $result = $this->coolifyApiService->createAppDockerImage($config['baseUrl'], $config['token'], $payload);
        if (!$result['success']) {
            return $result;
        }

        $app = $result['data'];
        return [
            'success' => true,
            'message' => 'Application created from Docker image',
            'app' => ['uuid' => $app['uuid'] ?? null, 'name' => $app['name'] ?? $args['appName'] ?? null],
            '_frontendData' => $this->buildFrontendData('coolifyManageApplications', 'App created (Docker image)', $args['appName'] ?? $app['uuid'] ?? 'app', $dockerImage),
        ];
    }

    private function handleAppUpdate(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
            return $config;
        }

        $appUuid = $args['appUuid'] ?? null;
        if (!$appUuid) {
            return ['success' => false, 'error' => 'Missing required parameter: appUuid'];
        }

        $data = [];
        foreach (['name', 'domains', 'git_repository', 'git_branch', 'build_pack', 'port', 'description'] as $field) {
            $apiField = str_replace('_', '_', $field);
            if (isset($args[$field])) {
                $data[$apiField] = $args[$field];
            }
        }

        $result = $this->coolifyApiService->updateApp($config['baseUrl'], $config['token'], $appUuid, $data);
        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'message' => "Application '{$appUuid}' updated",
            'appUuid' => $appUuid,
            'updatedFields' => array_keys($data),
            '_frontendData' => $this->buildFrontendData('coolifyManageApplications', 'App updated', $appUuid, implode(', ', array_keys($data))),
        ];
    }

    private function handleAppDelete(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
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
            '_frontendData' => $this->buildFrontendData('coolifyManageApplications', 'App deleted', $appUuid, null),
        ];
    }

    private function handleAppStart(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
            return $config;
        }

        $appUuid = $args['appUuid'] ?? null;
        if (!$appUuid) {
            return ['success' => false, 'error' => 'Missing required parameter: appUuid'];
        }

        $result = $this->coolifyApiService->startApp($config['baseUrl'], $config['token'], $appUuid);
        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'message' => "App '{$appUuid}' started",
            '_frontendData' => $this->buildFrontendData('coolifyManageApplications', 'App started', $appUuid, null),
        ];
    }

    private function handleAppStop(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
            return $config;
        }

        $appUuid = $args['appUuid'] ?? null;
        if (!$appUuid) {
            return ['success' => false, 'error' => 'Missing required parameter: appUuid'];
        }

        $result = $this->coolifyApiService->stopApp($config['baseUrl'], $config['token'], $appUuid);
        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'message' => "App '{$appUuid}' stopped",
            '_frontendData' => $this->buildFrontendData('coolifyManageApplications', 'App stopped', $appUuid, null),
        ];
    }

    private function handleAppRestart(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
            return $config;
        }

        $appUuid = $args['appUuid'] ?? null;
        if (!$appUuid) {
            return ['success' => false, 'error' => 'Missing required parameter: appUuid'];
        }

        $result = $this->coolifyApiService->restartApp($config['baseUrl'], $config['token'], $appUuid);
        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'message' => "App '{$appUuid}' restarted",
            '_frontendData' => $this->buildFrontendData('coolifyManageApplications', 'App restarted', $appUuid, null),
        ];
    }

    private function handleAppGetLogs(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
            return $config;
        }

        $appUuid = $args['appUuid'] ?? null;
        if (!$appUuid) {
            return ['success' => false, 'error' => 'Missing required parameter: appUuid'];
        }

        $result = $this->coolifyApiService->getAppLogs($config['baseUrl'], $config['token'], $appUuid);
        if (!$result['success']) {
            return $result;
        }

        $logs = $result['data'] ?? [];
        return [
            'success' => true,
            'appUuid' => $appUuid,
            'logs' => $logs,
            '_frontendData' => $this->buildFrontendData('coolifyManageApplications', 'App logs', $appUuid, null),
        ];
    }

    private function handleAppListEnvs(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
            return $config;
        }

        $appUuid = $args['appUuid'] ?? null;
        if (!$appUuid) {
            return ['success' => false, 'error' => 'Missing required parameter: appUuid'];
        }

        $result = $this->coolifyApiService->listEnvs($config['baseUrl'], $config['token'], $appUuid);
        if (!$result['success']) {
            return $result;
        }

        $envs = $result['data'] ?? [];
        $items = array_map(fn($e) => [
            'icon' => 'mdi-key-variant',
            'label' => $e['key'] ?? ($e['uuid'] ?? '?'),
            'meta' => isset($e['is_build_time']) && $e['is_build_time'] ? 'build-time' : null,
        ], is_array($envs) ? $envs : []);

        return [
            'success' => true,
            'appUuid' => $appUuid,
            'envs' => $envs,
            'count' => count($envs),
            '_frontendData' => $this->buildListFrontendData('coolifyManageApplications', 'listEnvs', count($envs) . ' env var(s)', $items),
        ];
    }

    private function handleAppCreateEnv(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
            return $config;
        }

        $appUuid = $args['appUuid'] ?? null;
        $key = $args['key'] ?? null;
        $value = $args['value'] ?? null;
        if (!$appUuid || !$key || !$value) {
            return ['success' => false, 'error' => 'Missing required parameters: appUuid, key, value'];
        }

        $result = $this->coolifyApiService->setEnv($config['baseUrl'], $config['token'], $appUuid, $key, $value, array_key_exists('isBuildTime', $args) ? (bool) $args['isBuildTime'] : null);
        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'message' => "Env var '{$key}' created",
            'appUuid' => $appUuid,
            '_frontendData' => $this->buildFrontendData('coolifyManageApplications', 'Env created', $key, $appUuid),
        ];
    }

    private function handleAppUpdateEnv(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
            return $config;
        }

        $appUuid = $args['appUuid'] ?? null;
        $envUuid = $args['envUuid'] ?? null;
        if (!$appUuid || !$envUuid) {
            return ['success' => false, 'error' => 'Missing required parameters: appUuid, envUuid'];
        }

        $data = [];
        if (isset($args['key'])) { $data['key'] = $args['key']; }
        if (isset($args['value'])) { $data['value'] = $args['value']; }
        if (isset($args['isBuildTime'])) { $data['is_build_time'] = $args['isBuildTime']; }

        $result = $this->coolifyApiService->updateEnv($config['baseUrl'], $config['token'], $appUuid, $envUuid, $data);
        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'message' => "Env var '{$envUuid}' updated",
            'appUuid' => $appUuid,
            '_frontendData' => $this->buildFrontendData('coolifyManageApplications', 'Env updated', $envUuid, $appUuid),
        ];
    }

    private function handleAppDeleteEnv(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
            return $config;
        }

        $appUuid = $args['appUuid'] ?? null;
        $envUuid = $args['envUuid'] ?? null;
        if (!$appUuid || !$envUuid) {
            return ['success' => false, 'error' => 'Missing required parameters: appUuid, envUuid'];
        }

        $result = $this->coolifyApiService->deleteEnv($config['baseUrl'], $config['token'], $appUuid, $envUuid);
        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'message' => "Env var '{$envUuid}' deleted",
            'appUuid' => $appUuid,
            '_frontendData' => $this->buildFrontendData('coolifyManageApplications', 'Env deleted', $envUuid, $appUuid),
        ];
    }

    private function handleAppBulkSetEnvs(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
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
            '_frontendData' => $this->buildFrontendData('coolifyManageApplications', 'Bulk envs set', count($envs) . ' variable(s)', $appUuid),
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    //  coolifyManageDeployments — deploy, status, list, cancel
    // ════════════════════════════════════════════════════════════════════

    public function coolifyManageDeployments(array $arguments): array
    {
        $operation = $arguments['operation'] ?? null;
        if (!$operation) {
            return ['success' => false, 'error' => 'Missing required parameter: operation'];
        }

        return match ($operation) {
            'deploy' => $this->handleDeploy($arguments),
            'get' => $this->handleDeploymentGet($arguments),
            'listByApp' => $this->handleDeploymentListByApp($arguments),
            'listRunning' => $this->handleDeploymentListRunning($arguments),
            'cancel' => $this->handleDeploymentCancel($arguments),
            default => ['success' => false, 'error' => "Unknown coolifyManageDeployments operation: {$operation}"],
        };
    }

    private function handleDeploy(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
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
            '_frontendData' => $this->buildFrontendData('coolifyManageDeployments', 'Deploy triggered', $appUuid, $deployment['deployment_uuid'] ?? null),
        ];
    }

    private function handleDeploymentGet(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
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
            '_frontendData' => $this->buildFrontendData('coolifyManageDeployments', 'Deployment status', $deployment['status'] ?? 'unknown', $deploymentUuid),
        ];
    }

    private function handleDeploymentListByApp(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
            return $config;
        }

        $appUuid = $args['appUuid'] ?? null;
        if (!$appUuid) {
            return ['success' => false, 'error' => 'Missing required parameter: appUuid'];
        }

        $result = $this->coolifyApiService->listDeployments($config['baseUrl'], $config['token'], $appUuid);
        if (!$result['success']) {
            return $result;
        }

        $deployments = $result['data'] ?? [];
        $items = array_map(fn($d) => [
            'icon' => 'mdi-rocket-launch',
            'label' => $d['deployment_uuid'] ?? ($d['uuid'] ?? '?'),
            'meta' => $d['status'] ?? null,
        ], is_array($deployments) ? $deployments : []);

        return [
            'success' => true,
            'appUuid' => $appUuid,
            'deployments' => $deployments,
            'count' => count($deployments),
            '_frontendData' => $this->buildListFrontendData('coolifyManageDeployments', 'listByApp', count($deployments) . ' deployment(s)', $items),
        ];
    }

    private function handleDeploymentListRunning(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
            return $config;
        }

        $result = $this->coolifyApiService->listDeployments($config['baseUrl'], $config['token'], '');
        if (!$result['success']) {
            return $result;
        }

        $deployments = $result['data'] ?? [];
        $items = array_map(fn($d) => [
            'icon' => 'mdi-rocket-launch',
            'label' => $d['deployment_uuid'] ?? ($d['uuid'] ?? '?'),
            'meta' => $d['status'] ?? null,
        ], is_array($deployments) ? $deployments : []);

        return [
            'success' => true,
            'deployments' => $deployments,
            'count' => count($deployments),
            '_frontendData' => $this->buildListFrontendData('coolifyManageDeployments', 'listRunning', count($deployments) . ' running deployment(s)', $items),
        ];
    }

    private function handleDeploymentCancel(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
            return $config;
        }

        $deploymentUuid = $args['deploymentUuid'] ?? null;
        if (!$deploymentUuid) {
            return ['success' => false, 'error' => 'Missing required parameter: deploymentUuid'];
        }

        $result = $this->coolifyApiService->cancelDeployment($config['baseUrl'], $config['token'], $deploymentUuid);
        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'message' => "Deployment '{$deploymentUuid}' cancelled",
            '_frontendData' => $this->buildFrontendData('coolifyManageDeployments', 'Deployment cancelled', $deploymentUuid, null),
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    //  coolifyManageProjects — list, get, create, update, delete, envs
    // ════════════════════════════════════════════════════════════════════

    public function coolifyManageProjects(array $arguments): array
    {
        $operation = $arguments['operation'] ?? null;
        if (!$operation) {
            return ['success' => false, 'error' => 'Missing required parameter: operation'];
        }

        return match ($operation) {
            'list' => $this->handleProjectList($arguments),
            'get' => $this->handleProjectGet($arguments),
            'create' => $this->handleProjectCreate($arguments),
            'update' => $this->handleProjectUpdate($arguments),
            'delete' => $this->handleProjectDelete($arguments),
            'listEnvironments' => $this->handleProjectListEnvironments($arguments),
            'createEnvironment' => $this->handleProjectCreateEnvironment($arguments),
            'getEnvironment' => $this->handleProjectGetEnvironment($arguments),
            'deleteEnvironment' => $this->handleProjectDeleteEnvironment($arguments),
            default => ['success' => false, 'error' => "Unknown coolifyManageProjects operation: {$operation}"],
        };
    }

    private function handleProjectList(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
            return $config;
        }

        $result = $this->coolifyApiService->listProjects($config['baseUrl'], $config['token']);
        if (!$result['success']) {
            return $result;
        }

        $projects = $result['data'] ?? [];
        $items = array_map(fn($p) => [
            'icon' => 'mdi-folder',
            'label' => $p['name'] ?? ($p['uuid'] ?? '?'),
            'meta' => $p['uuid'] ?? null,
        ], is_array($projects) ? $projects : []);

        return [
            'success' => true,
            'projects' => $projects,
            'count' => count($projects),
            '_frontendData' => $this->buildListFrontendData('coolifyManageProjects', 'list', count($projects) . ' project(s)', $items),
        ];
    }

    private function handleProjectGet(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
            return $config;
        }

        $projectUuid = $args['projectUuid'] ?? null;
        if (!$projectUuid) {
            return ['success' => false, 'error' => 'Missing required parameter: projectUuid'];
        }

        $result = $this->coolifyApiService->getProject($config['baseUrl'], $config['token'], $projectUuid);
        if (!$result['success']) {
            return $result;
        }

        $project = $result['data'] ?? [];
        return [
            'success' => true,
            'project' => $project,
            '_frontendData' => $this->buildFrontendData('coolifyManageProjects', 'Project', $project['name'] ?? $projectUuid, $projectUuid),
        ];
    }

    private function handleProjectCreate(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
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
            '_frontendData' => $this->buildFrontendData('coolifyManageProjects', 'Project created', $name, $project['uuid'] ?? null),
        ];
    }

    private function handleProjectUpdate(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
            return $config;
        }

        $projectUuid = $args['projectUuid'] ?? null;
        if (!$projectUuid) {
            return ['success' => false, 'error' => 'Missing required parameter: projectUuid'];
        }

        $data = [];
        if (isset($args['projectName'])) { $data['name'] = $args['projectName']; }
        if (isset($args['description'])) { $data['description'] = $args['description']; }

        $result = $this->coolifyApiService->updateProject($config['baseUrl'], $config['token'], $projectUuid, $data);
        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'message' => "Project '{$projectUuid}' updated",
            'projectUuid' => $projectUuid,
            'updatedFields' => array_keys($data),
            '_frontendData' => $this->buildFrontendData('coolifyManageProjects', 'Project updated', $projectUuid, implode(', ', array_keys($data))),
        ];
    }

    private function handleProjectDelete(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
            return $config;
        }

        $projectUuid = $args['projectUuid'] ?? null;
        if (!$projectUuid) {
            return ['success' => false, 'error' => 'Missing required parameter: projectUuid'];
        }

        $result = $this->coolifyApiService->deleteProject($config['baseUrl'], $config['token'], $projectUuid);
        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'message' => "Project '{$projectUuid}' deleted",
            '_frontendData' => $this->buildFrontendData('coolifyManageProjects', 'Project deleted', $projectUuid, null),
        ];
    }

    private function handleProjectListEnvironments(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
            return $config;
        }

        $projectUuid = $args['projectUuid'] ?? null;
        if (!$projectUuid) {
            return ['success' => false, 'error' => 'Missing required parameter: projectUuid'];
        }

        $result = $this->coolifyApiService->listEnvironments($config['baseUrl'], $config['token'], $projectUuid);
        if (!$result['success']) {
            return $result;
        }

        $environments = $result['data'] ?? [];
        $items = array_map(fn($e) => [
            'icon' => 'mdi-view-dashboard-outline',
            'label' => $e['name'] ?? ($e['uuid'] ?? '?'),
            'meta' => $e['uuid'] ?? null,
        ], is_array($environments) ? $environments : []);

        return [
            'success' => true,
            'projectUuid' => $projectUuid,
            'environments' => $environments,
            'count' => count($environments),
            '_frontendData' => $this->buildListFrontendData('coolifyManageProjects', 'listEnvironments', count($environments) . ' environment(s)', $items),
        ];
    }

    private function handleProjectCreateEnvironment(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
            return $config;
        }

        $projectUuid = $args['projectUuid'] ?? null;
        $envName = $args['environmentName'] ?? null;
        if (!$projectUuid || !$envName) {
            return ['success' => false, 'error' => 'Missing required parameters: projectUuid, environmentName'];
        }

        $result = $this->coolifyApiService->createEnvironment($config['baseUrl'], $config['token'], $projectUuid, $envName);
        if (!$result['success']) {
            return $result;
        }

        $env = $result['data'];
        return [
            'success' => true,
            'message' => "Environment '{$envName}' created in project {$projectUuid}",
            'environment' => ['uuid' => $env['uuid'] ?? null, 'name' => $envName],
            '_frontendData' => $this->buildFrontendData('coolifyManageProjects', 'Environment created', $envName, $env['uuid'] ?? null),
        ];
    }

    private function handleProjectGetEnvironment(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
            return $config;
        }

        $projectUuid = $args['projectUuid'] ?? null;
        $envNameOrUuid = $args['environmentNameOrUuid'] ?? null;
        if (!$projectUuid || !$envNameOrUuid) {
            return ['success' => false, 'error' => 'Missing required parameters: projectUuid, environmentNameOrUuid'];
        }

        $result = $this->coolifyApiService->getEnvironment($config['baseUrl'], $config['token'], $projectUuid, $envNameOrUuid);
        if (!$result['success']) {
            return $result;
        }

        $env = $result['data'];
        return [
            'success' => true,
            'environment' => $env,
            '_frontendData' => $this->buildFrontendData('coolifyManageProjects', 'Environment', $env['name'] ?? $envNameOrUuid, $env['uuid'] ?? $envNameOrUuid),
        ];
    }

    private function handleProjectDeleteEnvironment(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$this->configOk($config)) {
            return $config;
        }

        $projectUuid = $args['projectUuid'] ?? null;
        $envNameOrUuid = $args['environmentNameOrUuid'] ?? null;
        if (!$projectUuid || !$envNameOrUuid) {
            return ['success' => false, 'error' => 'Missing required parameters: projectUuid, environmentNameOrUuid'];
        }

        $result = $this->coolifyApiService->deleteEnvironment($config['baseUrl'], $config['token'], $projectUuid, $envNameOrUuid);
        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'message' => "Environment '{$envNameOrUuid}' deleted from project {$projectUuid}",
            '_frontendData' => $this->buildFrontendData('coolifyManageProjects', 'Environment deleted', $envNameOrUuid, $projectUuid),
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    //  Shared frontend rendering helpers
    // ════════════════════════════════════════════════════════════════════

    private const TOOL_ICON = 'mdi-rocket-launch-outline';
    private const SECONDARY_ICON = 'mdi-identifier';

    private function buildFrontendData(string $toolLabel, string $action, string $primary, ?string $secondary = null): string
    {
        $toolIcon = self::TOOL_ICON;
        $secondaryIcon = self::SECONDARY_ICON;
        $actionEsc = htmlspecialchars($action);
        $primaryEsc = htmlspecialchars($primary);
        $secondaryLine = $secondary !== null && $secondary !== ''
            ? '<div class="small text-muted"><i class="mdi ' . $secondaryIcon . ' me-1"></i>' . htmlspecialchars($secondary) . '</div>'
            : '';
        return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <div class="d-flex align-items-center">
        <i class="mdi $toolIcon text-cyber me-2"></i>
        <strong>$toolLabel</strong>
        <span class="ms-2 text-success"><i class="mdi mdi-check-circle me-1"></i></span>
        <span class="ms-2 text-muted">$actionEsc</span>
    </div>
    <div class="small text-muted mt-1">$primaryEsc</div>
    $secondaryLine
</div>
HTML;
    }

    /**
     * Build a frontend card for list/collection results, with a collapsible item list.
     * @param array $items each: ['icon' => 'mdi-...', 'label' => string, 'meta' => ?string]
     */
    private function buildListFrontendData(string $toolLabel, string $action, string $summary, array $items): string
    {
        $toolIcon = self::TOOL_ICON;
        $actionEsc = htmlspecialchars($action);
        $summaryEsc = htmlspecialchars($summary);
        $count = count($items);

        $itemsHtml = '';
        foreach (array_slice($items, 0, 20) as $it) {
            $icon = $it['icon'] ?? 'mdi-circle-small';
            $label = htmlspecialchars((string) ($it['label'] ?? ''));
            $meta = isset($it['meta']) && $it['meta'] !== null && $it['meta'] !== ''
                ? ' <span class="text-cyber">' . htmlspecialchars((string) $it['meta']) . '</span>'
                : '';
            $itemsHtml .= "<div class=\"small text-muted\"><i class=\"mdi {$icon} me-1\"></i><code>{$label}</code>{$meta}</div>";
        }
        $more = $count > 20 ? '<div class="small text-muted mt-1">… and ' . ($count - 20) . ' more</div>' : '';

        $listHtml = $count > 0
            ? $this->renderCollapsible("<i class=\"mdi mdi-format-list-bulleted me-1\"></i><strong>{$count} item(s)</strong>", $itemsHtml . $more, true)
            : '<div class="small text-muted mt-1"><i class="mdi mdi-information-outline me-1"></i>No results</div>';

        return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <div class="d-flex align-items-center">
        <i class="mdi $toolIcon text-cyber me-2"></i>
        <strong>$toolLabel</strong>
        <span class="ms-2 text-success"><i class="mdi mdi-check-circle me-1"></i></span>
        <span class="ms-2 text-muted">$actionEsc</span>
    </div>
    <div class="small text-muted mt-1">$summaryEsc</div>
    <div class="mt-2">$listHtml</div>
</div>
HTML;
    }

    private function renderCollapsible(string $summaryHtml, string $bodyHtml, bool $expanded = false): string
    {
        $chevClass = $expanded ? 'mdi-chevron-down' : 'mdi-chevron-right';
        $bodyHidden = $expanded ? '' : 'd-none';
        return <<<HTML
<div class="cq-collapsible mt-1">
    <div class="small text-muted cursor-pointer d-flex align-items-center"
         onclick="this.querySelector('.cq-chev').classList.toggle('mdi-chevron-down');this.querySelector('.cq-chev').classList.toggle('mdi-chevron-right');this.nextElementSibling.classList.toggle('d-none');">
        <i class="mdi $chevClass cq-chev me-1"></i>
        <span>$summaryHtml</span>
    </div>
    <div class="$bodyHidden mt-1 ps-3">$bodyHtml</div>
</div>
HTML;
    }
}
