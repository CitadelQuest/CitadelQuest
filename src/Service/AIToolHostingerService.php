<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * AI Tool service for Hostinger operations.
 * Wraps HostingerApiService, resolves credentials from ai_tool_settings.
 *
 * Tool name: hostingerManage
 * Operations: checkDomain, listDomains, registerDomain, getDns, setDns, listVps, getVps
 */
class AIToolHostingerService
{
    private const TOOL_NAME = 'hostingerManage';

    public function __construct(
        private readonly AiToolService $aiToolService,
        private readonly AiToolSettingsService $aiToolSettingsService,
        private readonly HostingerApiService $hostingerApiService,
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

    private function resolveToken(): ?string
    {
        return $this->getSetting('hostinger.api_token');
    }

    private function ensureConfig(): array
    {
        $token = $this->resolveToken();
        if (!$token) {
            return [
                'success' => false,
                'error' => 'Hostinger is not configured. Set hostinger.api_token in tool settings first.',
            ];
        }
        return ['token' => $token];
    }

    public function hostingerManage(array $arguments): array
    {
        $operation = $arguments['operation'] ?? null;
        if (!$operation) {
            return ['success' => false, 'error' => 'Missing required parameter: operation'];
        }

        return match ($operation) {
            'checkDomain' => $this->handleCheckDomain($arguments),
            'listDomains' => $this->handleListDomains($arguments),
            'registerDomain' => $this->handleRegisterDomain($arguments),
            'getDns' => $this->handleGetDns($arguments),
            'setDns' => $this->handleSetDns($arguments),
            'listVps' => $this->handleListVps($arguments),
            'getVps' => $this->handleGetVps($arguments),
            default => ['success' => false, 'error' => "Unknown Hostinger operation: {$operation}"],
        };
    }

    private function handleCheckDomain(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$config['token'] ?? false) {
            return $config;
        }

        $domain = $args['domain'] ?? null;
        if (!$domain) {
            return ['success' => false, 'error' => 'Missing required parameter: domain'];
        }

        $withAlternatives = $args['withAlternatives'] ?? false;
        $result = $this->hostingerApiService->checkDomainAvailability($config['token'], $domain, $withAlternatives);
        if (!$result['success']) {
            return $result;
        }

        $data = $result['data'] ?? [];
        return [
            'success' => true,
            'domain' => $domain,
            'available' => $data['available'] ?? null,
            'alternatives' => $data['alternatives'] ?? [],
            '_frontendData' => $this->buildFrontendData('Domain check', $domain, ($data['available'] ?? false) ? 'Available' : 'Not available'),
        ];
    }

    private function handleListDomains(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$config['token'] ?? false) {
            return $config;
        }

        $result = $this->hostingerApiService->listDomains($config['token']);
        if (!$result['success']) {
            return $result;
        }

        $domains = $result['data'] ?? [];
        $list = array_map(fn($d) => [
            'domain' => $d['domain'] ?? null,
            'status' => $d['status'] ?? null,
            'expiresAt' => $d['expires_at'] ?? null,
        ], is_array($domains) ? $domains : []);

        return [
            'success' => true,
            'domains' => $list,
            'count' => count($list),
        ];
    }

    private function handleRegisterDomain(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$config['token'] ?? false) {
            return $config;
        }

        $domain = $args['domain'] ?? null;
        if (!$domain) {
            return ['success' => false, 'error' => 'Missing required parameter: domain'];
        }

        $paymentMethodId = $args['paymentMethodId'] ?? null;
        $whoisProfileId = $args['whoisProfileId'] ?? null;

        $result = $this->hostingerApiService->registerDomain($config['token'], $domain, $paymentMethodId, $whoisProfileId);
        if (!$result['success']) {
            return $result;
        }

        $data = $result['data'] ?? [];
        return [
            'success' => true,
            'message' => "Domain '{$domain}' registration initiated",
            'domain' => $domain,
            'orderId' => $data['order_id'] ?? $data['id'] ?? null,
            '_frontendData' => $this->buildFrontendData('Domain registration', $domain, $data['order_id'] ?? null),
        ];
    }

    private function handleGetDns(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$config['token'] ?? false) {
            return $config;
        }

        $domain = $args['domain'] ?? null;
        if (!$domain) {
            return ['success' => false, 'error' => 'Missing required parameter: domain'];
        }

        $result = $this->hostingerApiService->getDnsRecords($config['token'], $domain);
        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'domain' => $domain,
            'records' => $result['data']['zone'] ?? $result['data'] ?? [],
        ];
    }

    private function handleSetDns(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$config['token'] ?? false) {
            return $config;
        }

        $domain = $args['domain'] ?? null;
        $records = $args['records'] ?? null;
        if (!$domain || !$records) {
            return ['success' => false, 'error' => 'Missing required parameters: domain, records (array of {type, name, content, ttl})'];
        }

        $overwrite = $args['overwrite'] ?? false;
        $result = $this->hostingerApiService->setDnsRecords($config['token'], $domain, $records, $overwrite);
        if (!$result['success']) {
            return $result;
        }

        return [
            'success' => true,
            'message' => "DNS records updated for '{$domain}'",
            'domain' => $domain,
            'recordCount' => count($records),
            '_frontendData' => $this->buildFrontendData('DNS updated', $domain, count($records) . ' records'),
        ];
    }

    private function handleListVps(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$config['token'] ?? false) {
            return $config;
        }

        $result = $this->hostingerApiService->listVps($config['token']);
        if (!$result['success']) {
            return $result;
        }

        $vpsList = $result['data'] ?? [];
        $list = array_map(fn($v) => [
            'vmId' => $v['vm_id'] ?? $v['id'] ?? null,
            'name' => $v['name'] ?? null,
            'ipv4' => $v['ipv4'] ?? $v['ip'] ?? null,
            'status' => $v['status'] ?? null,
        ], is_array($vpsList) ? $vpsList : []);

        return [
            'success' => true,
            'vps' => $list,
            'count' => count($list),
        ];
    }

    private function handleGetVps(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$config['token'] ?? false) {
            return $config;
        }

        $vmId = $args['vmId'] ?? null;
        if (!$vmId) {
            return ['success' => false, 'error' => 'Missing required parameter: vmId'];
        }

        $result = $this->hostingerApiService->getVps($config['token'], $vmId);
        if (!$result['success']) {
            return $result;
        }

        $vps = $result['data'] ?? [];
        return [
            'success' => true,
            'vps' => [
                'vmId' => $vps['vm_id'] ?? $vps['id'] ?? $vmId,
                'name' => $vps['name'] ?? null,
                'ipv4' => $vps['ipv4'] ?? $vps['ip'] ?? null,
                'status' => $vps['status'] ?? null,
            ],
        ];
    }

    private function buildFrontendData(string $action, string $primary, ?string $secondary): string
    {
        $displayPrimary = htmlspecialchars($primary);
        $secondaryLine = $secondary ? '<div class="small text-muted"><i class="mdi mdi-information-outline me-1"></i>' . htmlspecialchars($secondary) . '</div>' : '';
        return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <div class="d-flex align-items-center">
        <i class="mdi mdi-earth text-cyber me-2"></i>
        <strong>$displayPrimary</strong>
    </div>
    $secondaryLine
</div>
HTML;
    }
}
