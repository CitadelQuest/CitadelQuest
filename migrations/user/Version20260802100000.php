<?php

/**
 * Migration: Add redirect parameter to coolifyManageApplications.
 *
 * New parameter: redirect (enum: www, non-www, both) for all create* operations and update.
 * Maps to Coolify's Traefik/Caddy www<->non-www redirect setting.
 *
 * @see https://coolify.io/docs/api-reference/api/applications/update-application-by-uuid
 */
class UserMigration_20260802100000
{
    public function up(\PDO $db): void
    {
        $stmt = $db->prepare('SELECT id, parameters FROM ai_tool WHERE name = ?');
        $stmt->execute(['coolifyManageApplications']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }

        $parameters = json_decode($row['parameters'], true) ?? [];
        if (!isset($parameters['properties']['redirect'])) {
            $parameters['properties']['redirect'] = [
                'type' => 'string',
                'enum' => ['www', 'non-www', 'both'],
                'description' => 'Redirect direction for Traefik/Caddy (for create* and update operations): "www" = redirect non-www to www, "non-www" = redirect www to non-www, "both" = no redirect. Requires both www and non-www domains set on the app.',
            ];
        }

        $update = $db->prepare('UPDATE ai_tool SET parameters = ?, updated_at = ? WHERE id = ?');
        $update->execute([json_encode($parameters), date('Y-m-d H:i:s'), $row['id']]);
    }

    public function down(\PDO $db): void
    {
        $stmt = $db->prepare('SELECT id, parameters FROM ai_tool WHERE name = ?');
        $stmt->execute(['coolifyManageApplications']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }

        $parameters = json_decode($row['parameters'], true) ?? [];
        unset($parameters['properties']['redirect']);

        $update = $db->prepare('UPDATE ai_tool SET parameters = ?, updated_at = ? WHERE id = ?');
        $update->execute([json_encode($parameters), date('Y-m-d H:i:s'), $row['id']]);
    }
}
