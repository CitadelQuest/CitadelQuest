<?php

/**
 * Migration: Add updateNameservers operation to hostingerManage tool
 *
 * Updates the ai_tool row for hostingerManage to include the new
 * updateNameservers operation in its description, parameters enum,
 * and ns1–ns4 property definitions. Required because addTool() in
 * Version20260719190000 skips inserts when the tool already exists.
 */
class UserMigration_20260720200000
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
                    'enum' => ['checkDomain', 'listDomains', 'registerDomain', 'getDns', 'setDns', 'updateNameservers', 'listVps', 'getVps'],
                ],
                'domain' => ['type' => 'string', 'description' => 'Domain name (for checkDomain, registerDomain, getDns, setDns, updateNameservers)'],
                'withAlternatives' => ['type' => 'boolean', 'description' => 'Include alternative domain suggestions (for checkDomain)'],
                'paymentMethodId' => ['type' => 'integer', 'description' => 'Payment method ID (for registerDomain)'],
                'whoisProfileId' => ['type' => 'integer', 'description' => 'WHOIS profile ID (for registerDomain)'],
                'records' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['type' => ['type' => 'string'], 'name' => ['type' => 'string'], 'content' => ['type' => 'string'], 'ttl' => ['type' => 'integer']]], 'description' => 'DNS records (for setDns)'],
                'overwrite' => ['type' => 'boolean', 'description' => 'Overwrite existing DNS records (for setDns, default: false)'],
                'vmId' => ['type' => 'string', 'description' => 'VPS VM ID (for getVps)'],
                'ns1' => ['type' => 'string', 'description' => 'Primary nameserver (for updateNameservers, required)'],
                'ns2' => ['type' => 'string', 'description' => 'Secondary nameserver (for updateNameservers, required)'],
                'ns3' => ['type' => 'string', 'description' => 'Third nameserver (for updateNameservers, optional)'],
                'ns4' => ['type' => 'string', 'description' => 'Fourth nameserver (for updateNameservers, optional)'],
            ],
            'required' => ['operation'],
        ];

        $description = 'Manage Hostinger domains, DNS records, nameservers, and VPS instances. Operations: checkDomain, listDomains, registerDomain, getDns, setDns, updateNameservers, listVps, getVps. Requires hostinger.api_token in tool settings. Note: Hostinger API has a 10 requests/min rate limit.';

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
        // No-op: reverting the enum would break already-used updateNameservers calls.
    }
}
