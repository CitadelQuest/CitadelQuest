<?php

/**
 * Migration: Add persistent storage operations to coolifyManageApplications.
 *
 * New operations: listStorages, createStorage, updateStorage, deleteStorage.
 * New parameters: storageName, path, hostPath, volumeName, isReadonly, storageUuid.
 *
 * @see https://coolify.io/docs/api-reference/ (Applications → Storages)
 */
class UserMigration_20260726180000
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

        // Add storage operations to the enum
        $enum =& $parameters['properties']['operation']['enum'];
        $newOps = ['listStorages', 'createStorage', 'updateStorage', 'deleteStorage'];
        foreach ($newOps as $op) {
            if (!in_array($op, $enum, true)) {
                $enum[] = $op;
            }
        }

        // Add storage-specific parameters
        $storageParams = [
            'storageUuid' => [
                'type' => 'string',
                'description' => 'Persistent storage UUID (for updateStorage, deleteStorage)',
            ],
            'storageName' => [
                'type' => 'string',
                'description' => 'Storage name (for createStorage, updateStorage)',
            ],
            'path' => [
                'type' => 'string',
                'description' => 'Container mount path, e.g. /var/www/html/var (for createStorage, updateStorage). This is the in-container path where the persistent volume is mounted.',
            ],
            'type' => [
                'type' => 'string',
                'enum' => ['persistent', 'file'],
                'description' => 'Storage type: "persistent" for named Docker volume (default), "file" for file-based storage',
            ],
            'hostPath' => [
                'type' => 'string',
                'description' => 'Host path for bind mount (optional, for persistent storage). If set, uses a host directory instead of a named volume.',
            ],
        ];

        foreach ($storageParams as $key => $def) {
            if (!isset($parameters['properties'][$key])) {
                $parameters['properties'][$key] = $def;
            }
        }

        // Update description to mention storages
        $description = 'Manage Coolify applications: list, get, create (public, private-deploy-key, '
            . 'private-github-app, dockerfile, docker-image), update, delete, start, stop, '
            . 'restart, getLogs, listEnvs, createEnv, updateEnv, deleteEnv, bulkSetEnvs, '
            . 'listStorages, createStorage, updateStorage, deleteStorage. '
            . 'Requires coolify.base_url and coolify.api_token in coolifyManage tool settings.';

        $update = $db->prepare('UPDATE ai_tool SET description = ?, parameters = ?, updated_at = ? WHERE id = ?');
        $update->execute([
            $description,
            json_encode($parameters),
            date('Y-m-d H:i:s'),
            $row['id'],
        ]);
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

        // Remove storage operations from enum
        $enum =& $parameters['properties']['operation']['enum'];
        $enum = array_values(array_filter($enum, fn($op) => !in_array($op, ['listStorages', 'createStorage', 'updateStorage', 'deleteStorage'], true)));

        // Remove storage-specific parameters
        foreach (['storageUuid', 'storageName', 'path', 'type', 'hostPath'] as $key) {
            unset($parameters['properties'][$key]);
        }

        // Restore original description
        $description = 'Manage Coolify applications: list, get, create (public, private-deploy-key, '
            . 'private-github-app, dockerfile, docker-image), update, delete, start, stop, '
            . 'restart, getLogs, listEnvs, createEnv, updateEnv, deleteEnv, bulkSetEnvs. '
            . 'Requires coolify.base_url and coolify.api_token in coolifyManage tool settings.';

        $update = $db->prepare('UPDATE ai_tool SET description = ?, parameters = ?, updated_at = ? WHERE id = ?');
        $update->execute([
            $description,
            json_encode($parameters),
            date('Y-m-d H:i:s'),
            $row['id'],
        ]);
    }
}
