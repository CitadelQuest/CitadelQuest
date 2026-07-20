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
            // 'updateNameservers' => $this->handleUpdateNameservers($arguments), // Disabled: Hostinger API returns 500 [Domains:9999] — reported to Hostinger Support
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
        // Availability API returns a per-TLD list, e.g. [{domain, tld, is_available, ...}]
        $first = (is_array($data) && isset($data[0]) && is_array($data[0])) ? $data[0] : $data;
        $available = $first['is_available'] ?? $first['available'] ?? null;
        $alternatives = $data['alternatives'] ?? $first['alternatives'] ?? [];

        return [
            'success' => true,
            'domain' => $domain,
            'available' => $available,
            'alternatives' => $alternatives,
            '_frontendData' => $this->buildFrontendData('Domain check', $domain, $available === null ? 'Unknown' : ($available ? 'Available' : 'Not available')),
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

        $items = array_map(fn($d) => [
            'icon' => 'mdi-web',
            'label' => $d['domain'] ?? '(unknown)',
            'meta' => $d['status'] ?? null,
        ], $list);

        return [
            'success' => true,
            'domains' => $list,
            'count' => count($list),
            '_frontendData' => $this->buildListFrontendData('listDomains', count($list) . ' domain(s)', $items),
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

        $records = $result['data']['zone'] ?? $result['data'] ?? [];
        $items = [];
        if (is_array($records)) {
            foreach ($records as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $items[] = [
                    'icon' => 'mdi-dns-outline',
                    'label' => trim(($r['type'] ?? '') . ' ' . ($r['name'] ?? '')),
                    'meta' => $r['content'] ?? ($r['records'][0]['content'] ?? null),
                ];
            }
        }

        return [
            'success' => true,
            'domain' => $domain,
            'records' => $records,
            '_frontendData' => $this->buildListFrontendData('getDns', $domain . ' — ' . count($items) . ' record(s)', $items),
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
            'vmId' => $v['id'] ?? $v['vm_id'] ?? null,
            'name' => $v['hostname'] ?? $v['name'] ?? null,
            'ipv4' => $this->extractIpv4($v),
            'status' => $v['state'] ?? $v['status'] ?? null,
        ], is_array($vpsList) ? $vpsList : []);

        $items = array_map(fn($v) => [
            'icon' => 'mdi-server',
            'label' => $v['name'] ?? ($v['vmId'] ?? '(unknown)'),
            'meta' => $v['ipv4'] ?? null,
        ], $list);

        return [
            'success' => true,
            'vps' => $list,
            'count' => count($list),
            '_frontendData' => $this->buildListFrontendData('listVps', count($list) . ' VPS', $items),
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
        $ipv4 = $this->extractIpv4($vps);
        $status = $vps['state'] ?? $vps['status'] ?? null;
        $vpsName = $vps['hostname'] ?? $vps['name'] ?? ($vps['id'] ?? $vps['vm_id'] ?? $vmId);
        $vpsMeta = trim(($ipv4 ?? '') . ' ' . ($status ?? ''));
        return [
            'success' => true,
            'vps' => [
                'vmId' => $vps['id'] ?? $vps['vm_id'] ?? $vmId,
                'name' => $vps['hostname'] ?? $vps['name'] ?? null,
                'ipv4' => $ipv4,
                'status' => $status,
            ],
            '_frontendData' => $this->buildFrontendData('VPS', (string) $vpsName, $vpsMeta !== '' ? $vpsMeta : null),
        ];
    }

    private function handleUpdateNameservers(array $args): array
    {
        $config = $this->ensureConfig();
        if (!$config['token'] ?? false) {
            return $config;
        }

        $domain = $args['domain'] ?? null;
        $ns1 = $args['ns1'] ?? null;
        $ns2 = $args['ns2'] ?? null;
        if (!$domain || !$ns1 || !$ns2) {
            return ['success' => false, 'error' => 'Missing required parameters: domain, ns1, ns2 (ns3 and ns4 are optional)'];
        }

        $nameservers = ['ns1' => $ns1, 'ns2' => $ns2];
        foreach (['ns3', 'ns4'] as $field) {
            if (!empty($args[$field])) {
                $nameservers[$field] = $args[$field];
            }
        }

        $result = $this->hostingerApiService->updateNameservers($config['token'], $domain, $nameservers);
        if (!$result['success']) {
            return $result;
        }

        $nsList = implode(', ', array_filter($nameservers));
        return [
            'success' => true,
            'message' => "Nameservers updated for '{$domain}'",
            'domain' => $domain,
            'nameservers' => $nameservers,
            '_frontendData' => $this->buildFrontendData('Nameservers updated', $domain, $nsList),
        ];
    }

    /**
     * Extract a printable IPv4 address from a Hostinger VPS object.
     * Hostinger returns `ipv4` as an array of objects: [{id, address, ptr}, ...].
     * Falls back to a plain `ip`/string value from other shapes.
     */
    private function extractIpv4(array $vps): ?string
    {
        $ipv4 = $vps['ipv4'] ?? $vps['ip'] ?? null;
        if (is_array($ipv4)) {
            $first = $ipv4[0] ?? null;
            if (is_array($first)) {
                return $first['address'] ?? null;
            }
            return is_string($first) ? $first : null;
        }
        return is_string($ipv4) ? $ipv4 : null;
    }

    private const TOOL_ICON = 'mdi-earth';
    private const SECONDARY_ICON = 'mdi-information-outline';

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
