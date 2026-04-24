<?php

/**
 * Migration: Add runCommand AI Tool
 *
 * Completes the CQ SW IDE toolchain alongside gitOperation / fileManage:
 * enables Spirit AI to run arbitrary shell commands inside a project
 * directory (composer install, npm run build, php bin/console, tests, etc.).
 *
 * Project-scoped: cwd is validated to stay within project root.
 * Inactive by default — user must explicitly enable.
 */
class UserMigration_20260421010000
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
        $this->addTool(
            $db,
            'runCommand',
            'Run an arbitrary shell command inside a project directory. Supports pipes, redirects and && chaining. ALWAYS project-scoped (cwd validated to stay within project root). Returns exit code, stdout and stderr separately, plus duration. Use for build tools (composer install, npm run build), scripts (php bin/console), tests (phpunit, jest), inspection (ls, cat, grep, find), etc. After mutating commands the project file tree is auto-synced into the File Browser. For git, prefer gitOperation.',
            [
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
            ],
            0 // inactive by default
        );
    }

    private function addTool(\PDO $db, string $name, string $description, array $parameters, int $isActive = 0): void
    {
        $stmt = $db->prepare("SELECT id FROM ai_tool WHERE name = ?");
        $stmt->execute([$name]);
        if (!$stmt->fetch(\PDO::FETCH_ASSOC)) {
            $stmt = $db->prepare(
                'INSERT INTO ai_tool (id, name, description, parameters, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $this->generateUuid(),
                $name,
                $description,
                json_encode($parameters),
                $isActive,
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function down(\PDO $db): void
    {
        $db->exec("DELETE FROM ai_tool WHERE name = 'runCommand'");
    }
}
