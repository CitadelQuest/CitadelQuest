<?php

/**
 * Migration: Simplify runCommand AI Tool — remove cwd and syncFiles params
 *
 * - cwd removed: commands always run in project root. Security check
 *   (validateCommandPaths) blocks absolute paths that escape the project.
 * - syncFiles removed: auto-detected — read-only commands (ls, cat, grep,
 *   find, etc.) skip sync; mutating commands always sync.
 */
class UserMigration_20260506130000
{
    public function up(\PDO $db): void
    {
        $newParams = [
            'type' => 'object',
            'properties' => [
                'projectId' => [
                    'type' => 'string',
                    'description' => 'Project ID (default: "general")'
                ],
                'command' => [
                    'type' => 'string',
                    'description' => 'Full shell command to execute. Always runs in project root. Use relative paths (e.g. "cat repo/config.ini"). Absolute paths are blocked for security. Examples: "composer install", "php bin/console cache:clear", "ls -la src | grep Service".'
                ],
                'timeout' => [
                    'type' => 'integer',
                    'description' => 'Timeout in seconds (default 60, max 300). For long tasks (composer/npm install) use 120-300.'
                ],
            ],
            'required' => ['projectId', 'command']
        ];

        $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, updated_at = ? WHERE name = 'runCommand'");
        $stmt->execute([
            json_encode($newParams),
            date('Y-m-d H:i:s'),
        ]);
    }

    public function down(\PDO $db): void
    {
        // Restore original params with cwd and syncFiles
        $oldParams = [
            'type' => 'object',
            'properties' => [
                'projectId' => [
                    'type' => 'string',
                    'description' => 'Project ID (default: "general")'
                ],
                'command' => [
                    'type' => 'string',
                    'description' => 'Full shell command to execute. Example: "composer install", "php bin/console cache:clear", "ls -la src | grep Service".'
                ],
                'cwd' => [
                    'type' => 'string',
                    'description' => 'Working directory relative to project root. Default "/". Use "/repo" when working inside a cloned git repository. Must stay inside project root.'
                ],
                'timeout' => [
                    'type' => 'integer',
                    'description' => 'Timeout in seconds (default 60, max 300). For long tasks (composer/npm install) use 120-300.'
                ],
                'syncFiles' => [
                    'type' => 'boolean',
                    'description' => 'Re-sync the project filesystem into the File Browser database after the command runs. Default true. Set false for read-only commands (ls, cat, grep) to save time.'
                ]
            ],
            'required' => ['projectId', 'command']
        ];

        $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, updated_at = ? WHERE name = 'runCommand'");
        $stmt->execute([
            json_encode($oldParams),
            date('Y-m-d H:i:s'),
        ]);
    }
}
