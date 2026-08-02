<?php

/**
 * Migration: Coolify destinations support.
 *
 * - coolifyManage: new operation listDestinations (optional serverUuid filter).
 * - coolifyManage: new setting coolify.destination_uuid (default Docker destination
 *   for app creation when server has multiple destinations).
 * - coolifyManageApplications: new parameter destinationUuid (all create* operations).
 *
 * Destination resolution order in AIToolCoolifyService:
 *   destinationUuid arg → coolify.destination_uuid setting → auto-discover via API
 *   (single destination auto-picked; multiple → error listing choices).
 *
 * @see https://coolify.io/docs/api-reference/ (Servers → Destinations)
 */
class UserMigration_20260802090000
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
        // ── coolifyManage: add listDestinations op + serverUuid param ──
        $stmt = $db->prepare('SELECT id, parameters FROM ai_tool WHERE name = ?');
        $stmt->execute(['coolifyManage']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            $parameters = json_decode($row['parameters'], true) ?? [];
            $enum =& $parameters['properties']['operation']['enum'];
            if (!in_array('listDestinations', $enum, true)) {
                $enum[] = 'listDestinations';
            }
            if (!isset($parameters['properties']['serverUuid'])) {
                $parameters['properties']['serverUuid'] = [
                    'type' => 'string',
                    'description' => 'Server UUID (optional, for listDestinations — filter destinations by server). Note: listDestinations requires Coolify v4.2.0+; on older versions it returns 404 — use coolify.destination_uuid setting instead (find UUID in Coolify UI → Servers → Destinations).',
                ];
            }

            $description = 'Manage Coolify servers, destinations and SSH keys. Operations: listServers, '
                . 'listDestinations, createSshKey. Requires coolify.base_url and coolify.api_token in tool settings. '
                . 'Optional setting coolify.destination_uuid: default Docker destination UUID for app creation '
                . '(required when server has multiple destinations/networks). '
                . 'For applications, deployments, and projects use coolifyManageApplications, '
                . 'coolifyManageDeployments, coolifyManageProjects tools.';

            $update = $db->prepare('UPDATE ai_tool SET description = ?, parameters = ?, updated_at = ? WHERE id = ?');
            $update->execute([$description, json_encode($parameters), date('Y-m-d H:i:s'), $row['id']]);

            // ── Seed coolify.destination_uuid setting ──
            $this->seedSettings($db, $row['id'], [
                ['coolify.destination_uuid', 'text', 'Destination UUID', 'Default Docker destination/network UUID for app creation (optional — required only when server has multiple destinations). Find via coolifyManage listDestinations.', 3],
            ]);
        }

        // ── coolifyManageApplications: add destinationUuid param ──
        $stmt = $db->prepare('SELECT id, parameters FROM ai_tool WHERE name = ?');
        $stmt->execute(['coolifyManageApplications']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            $parameters = json_decode($row['parameters'], true) ?? [];
            if (!isset($parameters['properties']['destinationUuid'])) {
                $parameters['properties']['destinationUuid'] = [
                    'type' => 'string',
                    'description' => 'Docker destination UUID (for create* operations). Required only when the server has multiple destinations — otherwise auto-resolved from coolify.destination_uuid setting or auto-discovered. List via coolifyManage listDestinations.',
                ];
            }
            if (!isset($parameters['properties']['webhookSecret'])) {
                $parameters['properties']['webhookSecret'] = [
                    'type' => 'string',
                    'description' => 'Gitea webhook secret (for createPublic, createPrivateDeployKey, update). Auto-generated if not provided on create; returned in response. Sets manual_webhook_secret_gitea in Coolify.',
                ];
            }

            $update = $db->prepare('UPDATE ai_tool SET parameters = ?, updated_at = ? WHERE id = ?');
            $update->execute([json_encode($parameters), date('Y-m-d H:i:s'), $row['id']]);
        }
    }

    private function seedSettings(\PDO $db, string $toolId, array $settings): void
    {
        foreach ($settings as [$key, $type, $label, $description, $displayOrder]) {
            $stmt = $db->prepare('SELECT id FROM ai_tool_settings WHERE tool_id = ? AND key = ?');
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
        // ── coolifyManage: revert ──
        $stmt = $db->prepare('SELECT id, parameters FROM ai_tool WHERE name = ?');
        $stmt->execute(['coolifyManage']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            $parameters = json_decode($row['parameters'], true) ?? [];
            $enum =& $parameters['properties']['operation']['enum'];
            $enum = array_values(array_filter($enum, fn($op) => $op !== 'listDestinations'));
            unset($parameters['properties']['serverUuid']);

            $description = 'Manage Coolify servers and SSH keys. Operations: listServers, createSshKey. '
                . 'Requires coolify.base_url and coolify.api_token in tool settings. '
                . 'For applications, deployments, and projects use coolifyManageApplications, '
                . 'coolifyManageDeployments, coolifyManageProjects tools.';

            $update = $db->prepare('UPDATE ai_tool SET description = ?, parameters = ?, updated_at = ? WHERE id = ?');
            $update->execute([$description, json_encode($parameters), date('Y-m-d H:i:s'), $row['id']]);

            $del = $db->prepare('DELETE FROM ai_tool_settings WHERE tool_id = ? AND key = ?');
            $del->execute([$row['id'], 'coolify.destination_uuid']);
        }

        // ── coolifyManageApplications: revert ──
        $stmt = $db->prepare('SELECT id, parameters FROM ai_tool WHERE name = ?');
        $stmt->execute(['coolifyManageApplications']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            $parameters = json_decode($row['parameters'], true) ?? [];
            unset($parameters['properties']['destinationUuid'], $parameters['properties']['webhookSecret']);
            $update = $db->prepare('UPDATE ai_tool SET parameters = ?, updated_at = ? WHERE id = ?');
            $update->execute([json_encode($parameters), date('Y-m-d H:i:s'), $row['id']]);
        }
    }
}
