<?php

/**
 * Migration: Disable updateNameservers operation from hostingerManage tool
 *
 * Hostinger API endpoint PUT /api/domains/v1/portfolio/{domain}/nameservers
 * returns 500 [Domains:9999] — confirmed server-side bug (fails on their
 * official API dashboard too). Reported to Hostinger Support.
 *
 * This migration removes updateNameservers from the enum, description,
 * and ns1–ns4 properties so Spirit won't see or attempt to call it.
 */
class UserMigration_20260720210000
{
    public function up(\PDO $db): void
    {
        $stmt = $db->prepare("SELECT id FROM ai_tool WHERE name = 'hostingerManage'");
        $stmt->execute();
        $tool = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$tool) {
            return;
        }

        $parameters = [
            'type' => 'object',
            'properties' => [
                'operation' => [
                    'type' => 'string',
                    'description' => 'Operation to perform',
                    'enum' => ['checkDomain', 'listDomains', 'registerDomain', 'getDns', 'setDns', 'listVps', 'getVps'],
                ],
                'domain' => ['type' => 'string', 'description' => 'Domain name (for checkDomain, registerDomain, getDns, setDns)'],
                'withAlternatives' => ['type' => 'boolean', 'description' => 'Include alternative domain suggestions (for checkDomain)'],
                'paymentMethodId' => ['type' => 'integer', 'description' => 'Payment method ID (for registerDomain)'],
                'whoisProfileId' => ['type' => 'integer', 'description' => 'WHOIS profile ID (for registerDomain)'],
                'records' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['type' => ['type' => 'string'], 'name' => ['type' => 'string'], 'content' => ['type' => 'string'], 'ttl' => ['type' => 'integer']]], 'description' => 'DNS records (for setDns)'],
                'overwrite' => ['type' => 'boolean', 'description' => 'Overwrite existing DNS records (for setDns, default: false)'],
                'vmId' => ['type' => 'string', 'description' => 'VPS VM ID (for getVps)'],
            ],
            'required' => ['operation'],
        ];

        $description = 'Manage Hostinger domains, DNS records, and VPS instances. Operations: checkDomain, listDomains, registerDomain, getDns, setDns, listVps, getVps. Requires hostinger.api_token in tool settings. Note: Hostinger API has a 10 requests/min rate limit.';

        $stmt = $db->prepare("UPDATE ai_tool SET description = ?, parameters = ?, updated_at = ? WHERE id = ?");
        $stmt->execute([
            $description,
            json_encode($parameters),
            date('Y-m-d H:i:s'),
            $tool['id'],
        ]);
    }

    public function down(\PDO $db): void
    {
        // No-op: updateNameservers is disabled due to Hostinger API server-side bug.
    }
}
