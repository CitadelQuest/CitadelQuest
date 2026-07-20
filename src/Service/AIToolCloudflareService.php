<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * AI Tool service for Cloudflare DNS operations.
 * Wraps CloudflareApiService, resolves credentials from ai_tool_settings.
 *
 * Tool name: cloudflareManage
 * Operations: verifyToken, getZoneId, listZones, listDns, createDns,
 *             updateDns, deleteDns, addSubdomains
 *
 * Zone IDs are cached per-domain in ai_tool_settings (cloudflare.zone_id.{domain})
 * to avoid a lookup on every call.
 */
class AIToolCloudflareService
{
    private const TOOL_NAME = 'cloudflareManage';

    public function __construct(
        private readonly AiToolService $aiToolService,
        private readonly AiToolSettingsService $aiToolSettingsService,
        private readonly CloudflareApiService $cloudflareApiService,
        private readonly LoggerInterface $logger
    ) {
    }

    private function getToolId(): ?string
    {
        $tool = $this->aiToolService->findByName(self::TOOL_NAME);
        return $tool?->getId();
    }

    private function getSetting(string $key): ?string
    {
        $toolId = $this->getToolId();
        if (!$toolId) {
            return null;
        }
        return $this->aiToolSettingsService->getSettingValue($toolId, $key);
    }

    private function resolveToken(): ?string
    {
        return $this->getSetting('cloudflare.api_token');
    }

    private function ensureConfig(): array
    {
        $token = $this->resolveToken();
        if (!$token) {
            return [
                'success' => false,
                'error' => 'Cloudflare is not configured. Set cloudflare.api_token in tool settings first.',
            ];
        }
        return ['token' => $token];
    }

    /**
     * Resolve a Zone ID: explicit arg > cached setting > API lookup (then cache).
     * Returns ['zoneId' => string] or an error array.
     */
    private function resolveZoneId(string $token, array $args): array
    {
        if (!empty($args['zoneId'])) {
            return ['zoneId' => $args['zoneId']];
        }

        $domain = $args['domain'] ?? null;
        if (!$domain) {
            return ['success' => false, 'error' => 'Missing required parameter: domain (or zoneId)'];
        }

        $toolId = $this->getToolId();
        $cacheKey = 'cloudflare.zone_id.' . $domain;
        if ($toolId) {
            $cached = $this->aiToolSettingsService->getSettingValue($toolId, $cacheKey);
            if ($cached) {
                return ['zoneId' => $cached];
            }
        }

        $zoneId = $this->cloudflareApiService->getZoneIdByName($token, $domain);
        if (!$zoneId) {
            return ['success' => false, 'error' => "No Cloudflare zone found for domain '{$domain}'. The domain may not be managed by Cloudflare."];
        }

        // Cache for future calls
        if ($toolId) {
            $this->aiToolSettingsService->setSetting($toolId, $cacheKey, $zoneId, 'text', 'Cached Zone ID: ' . $domain, 'Auto-resolved Cloudflare Zone ID', 90);
        }

        return ['zoneId' => $zoneId];
    }

    public function cloudflareManage(array $arguments): array
    {
        $operation = $arguments['operation'] ?? null;
        if (!$operation) {
            return ['success' => false, 'error' => 'Missing required parameter: operation'];
        }

        return match ($operation) {
            'verifyToken' => $this->handleVerifyToken($arguments),
            'getZoneId' => $this->handleGetZoneId($arguments),
            'listZones' => $this->handleListZones($arguments),
            'listDns' => $this->handleListDns($arguments),
            'createDns' => $this->handleCreateDns($arguments),
            'updateDns' => $this->handleUpdateDns($arguments),
            'deleteDns' => $this->handleDeleteDns($arguments),
            'addSubdomains' => $this->handleAddSubdomains($arguments),
            default => ['success' => false, 'error' => "Unknown Cloudflare operation: {$operation}"],
        };
    }

    private function handleVerifyToken(array $args): array
    {
        $config = $this->ensureConfig();
        if (!($config['token'] ?? false)) {
            return $config;
        }

        $token = $config['token'];

        // The /user/tokens/verify endpoint only works for classic User API tokens (cfut_).
        // Account API tokens (cfat_) return 401 there even when valid, so we fall back to
        // a lightweight /zones health check that works for both token types.
        $result = $this->cloudflareApiService->verifyToken($token);
        if ($result['success']) {
            $data = $result['data'] ?? [];
            return [
                'success' => true,
                'status' => $data['status'] ?? 'active',
                'tokenId' => $data['id'] ?? null,
                'tokenType' => 'user',
                'message' => 'Cloudflare API token is valid (User API token)',
                '_frontendData' => $this->buildFrontendData('Token verified', 'Cloudflare User API token', $data['status'] ?? 'active'),
            ];
        }

        // Fallback: verify via /zones (works for Account API tokens with Zone permissions)
        $zones = $this->cloudflareApiService->listZones($token, null, 1, 1);
        if ($zones['success']) {
            $zoneCount = $zones['resultInfo']['total_count'] ?? count($zones['data'] ?? []);
            return [
                'success' => true,
                'status' => 'active',
                'tokenType' => 'account',
                'zoneCount' => $zoneCount,
                'message' => 'Cloudflare API token is valid (Account API token, verified via /zones)',
                '_frontendData' => $this->buildFrontendData('Token verified', 'Cloudflare Account API token', $zoneCount . ' zone(s) accessible'),
            ];
        }

        // Both checks failed — token is genuinely invalid or lacks Zone:Read
        return [
            'success' => false,
            'error' => 'Cloudflare token verification failed. The /user/tokens/verify check returned: ' . ($result['error'] ?? 'unknown')
                . '. The /zones fallback returned: ' . ($zones['error'] ?? 'unknown')
                . '. Ensure the token is valid and scoped Zone:Read + DNS:Edit.',
        ];
    }

    private function handleGetZoneId(array $args): array
    {
        $config = $this->ensureConfig();
        if (!($config['token'] ?? false)) {
            return $config;
        }

        $resolved = $this->resolveZoneId($config['token'], $args);
        if (!($resolved['zoneId'] ?? false)) {
            return $resolved;
        }

        return [
            'success' => true,
            'domain' => $args['domain'] ?? null,
            'zoneId' => $resolved['zoneId'],
            '_frontendData' => $this->buildFrontendData('Zone ID resolved', $args['domain'] ?? 'zone', $resolved['zoneId']),
        ];
    }

    private function handleListZones(array $args): array
    {
        $config = $this->ensureConfig();
        if (!($config['token'] ?? false)) {
            return $config;
        }

        $result = $this->cloudflareApiService->listZones($config['token'], $args['domain'] ?? null);
        if (!$result['success']) {
            return $result;
        }

        $zones = $result['data'] ?? [];
        $list = array_map(fn($z) => [
            'id' => $z['id'] ?? null,
            'name' => $z['name'] ?? null,
            'status' => $z['status'] ?? null,
        ], is_array($zones) ? $zones : []);

        return [
            'success' => true,
            'zones' => $list,
            'count' => count($list),
        ];
    }

    private function handleListDns(array $args): array
    {
        $config = $this->ensureConfig();
        if (!($config['token'] ?? false)) {
            return $config;
        }

        $resolved = $this->resolveZoneId($config['token'], $args);
        if (!($resolved['zoneId'] ?? false)) {
            return $resolved;
        }

        $filters = [];
        if (isset($args['type'])) {
            $filters['type'] = $args['type'];
        }
        if (isset($args['name'])) {
            $filters['name'] = $args['name'];
        }

        $result = $this->cloudflareApiService->listDnsRecords($config['token'], $resolved['zoneId'], $filters);
        if (!$result['success']) {
            return $result;
        }

        $records = $result['data'] ?? [];
        $list = array_map(fn($r) => [
            'id' => $r['id'] ?? null,
            'type' => $r['type'] ?? null,
            'name' => $r['name'] ?? null,
            'content' => $r['content'] ?? null,
            'ttl' => $r['ttl'] ?? null,
            'proxied' => $r['proxied'] ?? null,
        ], is_array($records) ? $records : []);

        return [
            'success' => true,
            'zoneId' => $resolved['zoneId'],
            'records' => $list,
            'count' => count($list),
        ];
    }

    private function handleCreateDns(array $args): array
    {
        $config = $this->ensureConfig();
        if (!($config['token'] ?? false)) {
            return $config;
        }

        $resolved = $this->resolveZoneId($config['token'], $args);
        if (!($resolved['zoneId'] ?? false)) {
            return $resolved;
        }

        $type = $args['type'] ?? 'A';
        $name = $args['name'] ?? null;
        $content = $args['content'] ?? null;
        if (!$name || !$content) {
            return ['success' => false, 'error' => 'Missing required parameters: name, content'];
        }

        $record = [
            'type' => $type,
            'name' => $name,
            'content' => $content,
            'ttl' => $args['ttl'] ?? 1,
            'proxied' => $args['proxied'] ?? false,
        ];

        $result = $this->cloudflareApiService->createDnsRecord($config['token'], $resolved['zoneId'], $record);
        if (!$result['success']) {
            return $result;
        }

        $rec = $result['data'] ?? [];
        return [
            'success' => true,
            'message' => "DNS {$type} record created: {$name} → {$content}",
            'record' => [
                'id' => $rec['id'] ?? null,
                'type' => $rec['type'] ?? $type,
                'name' => $rec['name'] ?? $name,
                'content' => $rec['content'] ?? $content,
                'proxied' => $rec['proxied'] ?? false,
            ],
            '_frontendData' => $this->buildFrontendData('DNS record created', "{$type} {$name}", $content),
        ];
    }

    private function handleUpdateDns(array $args): array
    {
        $config = $this->ensureConfig();
        if (!($config['token'] ?? false)) {
            return $config;
        }

        $resolved = $this->resolveZoneId($config['token'], $args);
        if (!($resolved['zoneId'] ?? false)) {
            return $resolved;
        }

        $recordId = $args['recordId'] ?? null;
        if (!$recordId) {
            return ['success' => false, 'error' => 'Missing required parameter: recordId'];
        }

        $data = [];
        foreach (['type', 'name', 'content', 'ttl', 'proxied'] as $field) {
            if (isset($args[$field])) {
                $data[$field] = $args[$field];
            }
        }
        if (empty($data)) {
            return ['success' => false, 'error' => 'No fields to update (provide type, name, content, ttl, or proxied)'];
        }

        // Partial update (PATCH) unless a full record (type+name+content) is supplied
        $partial = !(isset($data['type']) && isset($data['name']) && isset($data['content']));

        $result = $this->cloudflareApiService->updateDnsRecord($config['token'], $resolved['zoneId'], $recordId, $data, $partial);
        if (!$result['success']) {
            return $result;
        }

        $rec = $result['data'] ?? [];
        return [
            'success' => true,
            'message' => 'DNS record updated',
            'record' => [
                'id' => $rec['id'] ?? $recordId,
                'type' => $rec['type'] ?? null,
                'name' => $rec['name'] ?? null,
                'content' => $rec['content'] ?? null,
                'proxied' => $rec['proxied'] ?? null,
            ],
            '_frontendData' => $this->buildFrontendData('DNS record updated', $rec['name'] ?? $recordId, $rec['content'] ?? null),
        ];
    }

    private function handleDeleteDns(array $args): array
    {
        $config = $this->ensureConfig();
        if (!($config['token'] ?? false)) {
            return $config;
        }

        $resolved = $this->resolveZoneId($config['token'], $args);
        if (!($resolved['zoneId'] ?? false)) {
            return $resolved;
        }

        $recordId = $args['recordId'] ?? null;
        if (!$recordId) {
            return ['success' => false, 'error' => 'Missing required parameter: recordId'];
        }

        $result = $this->cloudflareApiService->deleteDnsRecord($config['token'], $resolved['zoneId'], $recordId);
        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'message' => "DNS record '{$recordId}' deleted",
            '_frontendData' => $this->buildFrontendData('DNS record deleted', $recordId, null),
        ];
    }

    private function handleAddSubdomains(array $args): array
    {
        $config = $this->ensureConfig();
        if (!($config['token'] ?? false)) {
            return $config;
        }

        $resolved = $this->resolveZoneId($config['token'], $args);
        if (!($resolved['zoneId'] ?? false)) {
            return $resolved;
        }

        $vpsIp = $args['vpsIp'] ?? $args['content'] ?? null;
        $subdomains = $args['subdomains'] ?? null;
        if (!$vpsIp || !$subdomains || !is_array($subdomains)) {
            return ['success' => false, 'error' => 'Missing required parameters: vpsIp, subdomains (array of names)'];
        }

        $proxied = $args['proxied'] ?? false;
        $result = $this->cloudflareApiService->addSubdomainARecords($config['token'], $resolved['zoneId'], $vpsIp, $subdomains, $proxied);
        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'message' => count($subdomains) . " subdomain A-records created → {$vpsIp}",
            'zoneId' => $resolved['zoneId'],
            'subdomains' => $subdomains,
            'vpsIp' => $vpsIp,
            '_frontendData' => $this->buildFrontendData('Subdomains created', implode(', ', $subdomains), $vpsIp),
        ];
    }

    private function buildFrontendData(string $action, string $primary, ?string $secondary): string
    {
        $displayPrimary = htmlspecialchars($primary);
        $secondaryLine = $secondary ? '<div class="small text-muted"><i class="mdi mdi-dns-outline me-1"></i>' . htmlspecialchars($secondary) . '</div>' : '';
        return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <div class="d-flex align-items-center">
        <i class="mdi mdi-cloud-check-outline text-cyber me-2"></i>
        <strong>$displayPrimary</strong>
    </div>
    $secondaryLine
</div>
HTML;
    }
}
