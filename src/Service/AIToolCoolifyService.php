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

        $items = array_map(fn($s) => [
            'icon' => 'mdi-server',
            'label' => $s['name'] ?? ($s['uuid'] ?? '(unknown)'),
            'meta' => $s['ip'] ?? null,
        ], $list);

        return [
            'success' => true,
            'servers' => $list,
            'count' => count($list),
            '_frontendData' => $this->buildListFrontendData('listServers', count($list) . ' server(s)', $items),
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
            '_frontendData' => $this->buildFrontendData('Env vars set', count($envs) . ' variable(s)', $appUuid),
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
            '_frontendData' => $this->buildFrontendData('Deployment status', $deployment['status'] ?? 'unknown', $deploymentUuid),
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
            ],
            '_frontendData' => $this->buildFrontendData('Application', $appName, $statusLine !== '' ? $statusLine : null),
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

    private const TOOL_ICON = 'mdi-rocket-launch-outline';
    private const SECONDARY_ICON = 'mdi-identifier';

    private function buildFrontendData(string $action, string $primary, ?string $secondary = null): string
    {
        $toolIcon = self::TOOL_ICON;
        $toolLabel = self::TOOL_NAME;
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
    private function buildListFrontendData(string $action, string $summary, array $items): string
    {
        $toolIcon = self::TOOL_ICON;
        $toolLabel = self::TOOL_NAME;
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
