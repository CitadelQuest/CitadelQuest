<?php

/**
 * Migration: Expand Coolify AI Tools
 *
 * Updates coolifyManage to slim operations (listServers, createSshKey only).
 * Adds three new specialized Coolify tools:
 * - coolifyManageApplications: list, get, create*, update, delete, start/stop/restart, logs, envs
 * - coolifyManageDeployments: deploy, get, listByApp, listRunning, cancel
 * - coolifyManageProjects: list, get, create, update, delete, listEnvironments
 *
 * All tools share credentials stored under coolifyManage settings.
 */
class UserMigration_20260725160000
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
        // ── Update coolifyManage: slim to listServers + createSshKey ──
        $this->updateTool($db, 'coolifyManage',
            'Manage Coolify servers and SSH keys. Operations: listServers, createSshKey. '
            . 'Requires coolify.base_url and coolify.api_token in tool settings. '
            . 'For applications, deployments, and projects use coolifyManageApplications, '
            . 'coolifyManageDeployments, coolifyManageProjects tools.',
            [
                'type' => 'object',
                'properties' => [
                    'operation' => [
                        'type' => 'string',
                        'description' => 'Operation to perform',
                        'enum' => ['listServers', 'createSshKey'],
                    ],
                    'keyName' => ['type' => 'string', 'description' => 'SSH key name (for createSshKey)'],
                    'privateKey' => ['type' => 'string', 'description' => 'SSH private key content (for createSshKey)'],
                ],
                'required' => ['operation'],
            ]
        );

        // ── coolifyManageApplications ──
        $this->addTool($db, 'coolifyManageApplications',
            'Manage Coolify applications: list, get, create (public, private-deploy-key, '
            . 'private-github-app, dockerfile, docker-image), update, delete, start, stop, '
            . 'restart, getLogs, listEnvs, createEnv, updateEnv, deleteEnv, bulkSetEnvs. '
            . 'Requires coolify.base_url and coolify.api_token in coolifyManage tool settings.',
            [
                'type' => 'object',
                'properties' => [
                    'operation' => [
                        'type' => 'string',
                        'description' => 'Operation to perform',
                        'enum' => [
                            'list', 'get', 'createPublic', 'createPrivateDeployKey',
                            'createPrivateGithubApp', 'createDockerfile', 'createDockerImage',
                            'update', 'delete', 'start', 'stop', 'restart',
                            'getLogs', 'listEnvs', 'createEnv', 'updateEnv', 'deleteEnv', 'bulkSetEnvs',
                        ],
                    ],
                    'appUuid' => ['type' => 'string', 'description' => 'Application UUID (for get, update, delete, start, stop, restart, getLogs, listEnvs, createEnv, updateEnv, deleteEnv, bulkSetEnvs)'],
                    'projectUuid' => ['type' => 'string', 'description' => 'Project UUID (for create* operations)'],
                    'serverUuid' => ['type' => 'string', 'description' => 'Server UUID (for create* operations)'],
                    'gitRepository' => ['type' => 'string', 'description' => 'Git repository URL (for createPublic, createPrivateDeployKey, createPrivateGithubApp)'],
                    'branch' => ['type' => 'string', 'description' => 'Git branch (default: main)'],
                    'privateKeyUuid' => ['type' => 'string', 'description' => 'Coolify SSH key UUID (for createPrivateDeployKey)'],
                    'githubAppUuid' => ['type' => 'string', 'description' => 'GitHub App UUID (for createPrivateGithubApp)'],
                    'buildPack' => ['type' => 'string', 'description' => 'Build pack: nixpacks, dockerfile, dockercompose (default: nixpacks)'],
                    'environmentName' => ['type' => 'string', 'description' => 'Environment name within the project (for create* operations). Either environmentName or environmentUuid is required by Coolify API.'],
                    'environmentUuid' => ['type' => 'string', 'description' => 'Environment UUID within the project (for create* operations). Either environmentName or environmentUuid is required by Coolify API.'],
                    'appName' => ['type' => 'string', 'description' => 'Application name (for create* operations)'],
                    'domains' => ['type' => 'string', 'description' => 'Domain(s) for the app (comma-separated)'],
                    'port' => ['type' => 'integer', 'description' => 'Application port'],
                    'dockerfile' => ['type' => 'string', 'description' => 'Dockerfile content (for createDockerfile)'],
                    'dockerImage' => ['type' => 'string', 'description' => 'Docker image name (for createDockerImage)'],
                    'name' => ['type' => 'string', 'description' => 'Application name (for update)'],
                    'git_repository' => ['type' => 'string', 'description' => 'Git repository URL (for update)'],
                    'git_branch' => ['type' => 'string', 'description' => 'Git branch (for update)'],
                    'description' => ['type' => 'string', 'description' => 'Application description (for update)'],
                    'envUuid' => ['type' => 'string', 'description' => 'Environment variable UUID (for updateEnv, deleteEnv)'],
                    'key' => ['type' => 'string', 'description' => 'Env variable key (for createEnv, updateEnv)'],
                    'value' => ['type' => 'string', 'description' => 'Env variable value (for createEnv, updateEnv)'],
                    'isAutoDeployEnabled' => ['type' => 'boolean', 'description' => 'Enable auto-deploy on git push (for create* and update operations, default: false)'],
                    'isBuildTime' => ['type' => 'boolean', 'description' => 'Is build-time variable (for createEnv, updateEnv)'],
                    'envs' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['key' => ['type' => 'string'], 'value' => ['type' => 'string']]], 'description' => 'Environment variables (for bulkSetEnvs)'],
                ],
                'required' => ['operation'],
            ],
            'development',
            63
        );

        // ── coolifyManageDeployments ──
        $this->addTool($db, 'coolifyManageDeployments',
            'Manage Coolify deployments: deploy, get status, listByApp, listRunning, cancel. '
            . 'Requires coolify.base_url and coolify.api_token in coolifyManage tool settings.',
            [
                'type' => 'object',
                'properties' => [
                    'operation' => [
                        'type' => 'string',
                        'description' => 'Operation to perform',
                        'enum' => ['deploy', 'get', 'listByApp', 'listRunning', 'cancel'],
                    ],
                    'appUuid' => ['type' => 'string', 'description' => 'Application UUID (for deploy, listByApp)'],
                    'deploymentUuid' => ['type' => 'string', 'description' => 'Deployment UUID (for get, cancel)'],
                    'force' => ['type' => 'boolean', 'description' => 'Force deploy (for deploy, default: false)'],
                ],
                'required' => ['operation'],
            ],
            'development',
            64
        );

        // ── coolifyManageProjects ──
        $this->addTool($db, 'coolifyManageProjects',
            'Manage Coolify projects: list, get, create, update, delete, listEnvironments, '
            . 'createEnvironment, getEnvironment, deleteEnvironment. '
            . 'Requires coolify.base_url and coolify.api_token in coolifyManage tool settings.',
            [
                'type' => 'object',
                'properties' => [
                    'operation' => [
                        'type' => 'string',
                        'description' => 'Operation to perform',
                        'enum' => ['list', 'get', 'create', 'update', 'delete', 'listEnvironments', 'createEnvironment', 'getEnvironment', 'deleteEnvironment'],
                    ],
                    'projectUuid' => ['type' => 'string', 'description' => 'Project UUID (for get, update, delete, listEnvironments, createEnvironment, getEnvironment, deleteEnvironment)'],
                    'projectName' => ['type' => 'string', 'description' => 'Project name (for create, update)'],
                    'description' => ['type' => 'string', 'description' => 'Project description (for create, update)'],
                    'environmentName' => ['type' => 'string', 'description' => 'Environment name (for createEnvironment)'],
                    'environmentNameOrUuid' => ['type' => 'string', 'description' => 'Environment name or UUID (for getEnvironment, deleteEnvironment)'],
                ],
                'required' => ['operation'],
            ],
            'development',
            65
        );
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

    private function updateTool(\PDO $db, string $name, string $description, array $parameters): void
    {
        $stmt = $db->prepare("UPDATE ai_tool SET description = ?, parameters = ?, updated_at = ? WHERE name = ?");
        $stmt->execute([
            $description, json_encode($parameters), date('Y-m-d H:i:s'), $name
        ]);
    }

    public function down(\PDO $db): void
    {
        $db->exec("DELETE FROM ai_tool WHERE name IN ('coolifyManageApplications', 'coolifyManageDeployments', 'coolifyManageProjects')");
    }
}
