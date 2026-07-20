<?php

/**
 * Migration: Add Cloudflare AI tool (cloudflareManage)
 *
 * Inserts the cloudflareManage tool into the ai_tool table and seeds its
 * ai_tool_settings with credential fields (typed, labelled for future Settings GUI).
 * Tool is inactive by default.
 *
 * Part of P7 implementation (Cloudflare API + DNS provider routing).
 * Follow-up to Version20260719190000 (Gitea/Coolify/Hostinger infra tools).
 */
class UserMigration_20260720150000
{
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public function up(\PDO $db): void
    {
        $cloudflareId = $this->addTool($db, 'cloudflareManage',
            'Manage Cloudflare DNS zones and records for domains routed through Cloudflare nameservers. Operations: verifyToken, getZoneId, listZones, listDns, createDns, updateDns, deleteDns, addSubdomains. Zone IDs are auto-resolved from a domain name and cached. Requires cloudflare.api_token in tool settings (scoped Zone:Read + DNS:Edit). Use this instead of hostingerManage when a domain uses Cloudflare DNS.',
            [
                'type' => 'object',
                'properties' => [
                    'operation' => [
                        'type' => 'string',
                        'description' => 'Operation to perform',
                        'enum' => ['verifyToken', 'getZoneId', 'listZones', 'listDns', 'createDns', 'updateDns', 'deleteDns', 'addSubdomains']
                    ],
                    'domain' => ['type' => 'string', 'description' => 'Domain name; Zone ID is auto-resolved and cached (for getZoneId, listDns, createDns, updateDns, deleteDns, addSubdomains). Optional if zoneId is given.'],
                    'zoneId' => ['type' => 'string', 'description' => 'Explicit Cloudflare Zone ID (skips domain lookup)'],
                    'type' => ['type' => 'string', 'description' => 'DNS record type (A, AAAA, CNAME, TXT, MX, ...); default A for createDns. Also filters listDns.'],
                    'name' => ['type' => 'string', 'description' => 'DNS record name / subdomain (e.g. "git", "cq", "www"). Also filters listDns.'],
                    'content' => ['type' => 'string', 'description' => 'DNS record content (e.g. IP address for A records)'],
                    'ttl' => ['type' => 'integer', 'description' => 'TTL in seconds; 1 = automatic/Cloudflare-managed (default 1)'],
                    'proxied' => ['type' => 'boolean', 'description' => 'Cloudflare proxy: false = DNS-only/grey cloud (default, Coolify handles SSL), true = proxied/orange cloud'],
                    'recordId' => ['type' => 'string', 'description' => 'DNS record ID (for updateDns, deleteDns)'],
                    'vpsIp' => ['type' => 'string', 'description' => 'VPS IP address for addSubdomains (A-records for all subdomains)'],
                    'subdomains' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Subdomain names for addSubdomains (e.g. ["git", "cq", "stage", "www"])'],
                ],
                'required' => ['operation']
            ],
            'development',
            63
        );

        $this->seedSettings($db, $cloudflareId, [
            ['cloudflare.api_token', 'password', 'Cloudflare API Token', 'Bearer token scoped to Zone:Read + DNS:Edit', 1],
            ['cloudflare.account_id', 'text', 'Cloudflare Account ID', 'Optional — only needed to create new zones', 2],
        ]);
    }

    private function addTool(\PDO $db, string $name, string $description, array $parameters, string $category = 'development', int $displayOrder = 0): string
    {
        $stmt = $db->prepare("SELECT id FROM ai_tool WHERE name = ?");
        $stmt->execute([$name]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($existing) {
            return $existing['id'];
        }

        $id = $this->generateUuid();
        $stmt = $db->prepare(
            'INSERT INTO ai_tool (id, name, description, parameters, is_active, category, display_order, created_at, updated_at) VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id, $name, $description, json_encode($parameters), $category, $displayOrder,
            date('Y-m-d H:i:s'), date('Y-m-d H:i:s')
        ]);
        return $id;
    }

    private function seedSettings(\PDO $db, string $toolId, array $settings): void
    {
        foreach ($settings as [$key, $type, $label, $description, $displayOrder]) {
            $stmt = $db->prepare("SELECT id FROM ai_tool_settings WHERE tool_id = ? AND key = ?");
            $stmt->execute([$toolId, $key]);
            if ($stmt->fetch(\PDO::FETCH_ASSOC)) {
                continue;
            }
            $id = $this->generateUuid();
            $stmt = $db->prepare(
                'INSERT INTO ai_tool_settings (id, tool_id, key, value, type, label, description, display_order, created_at, updated_at) VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $id, $toolId, $key, $type, $label, $description, $displayOrder,
                date('Y-m-d H:i:s'), date('Y-m-d H:i:s')
            ]);
        }
    }

    public function down(\PDO $db): void
    {
        $stmt = $db->prepare("SELECT id FROM ai_tool WHERE name = 'cloudflareManage'");
        $stmt->execute();
        $toolIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        if (!empty($toolIds)) {
            $placeholders = implode(',', array_fill(0, count($toolIds), '?'));
            $db->prepare("DELETE FROM ai_tool_settings WHERE tool_id IN ($placeholders)")->execute($toolIds);
        }

        $db->exec("DELETE FROM ai_tool WHERE name = 'cloudflareManage'");
    }
}
