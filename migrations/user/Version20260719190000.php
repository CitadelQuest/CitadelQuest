<?php

/**
 * Migration: Add infrastructure AI tools (Gitea, Coolify, Hostinger)
 *
 * Inserts three new AI tools into the ai_tool table:
 * - giteaManage: Gitea org/repo/user/token/webhook management
 * - coolifyManage: Coolify project/app/env/deploy management
 * - hostingerManage: Hostinger domain/DNS/VPS management
 *
 * Also seeds their ai_tool_settings with empty credential fields (typed, labelled
 * for future Settings GUI rendering). All tools are inactive by default.
 *
 * Part of P2 + P3 + P4 implementation.
 */
class UserMigration_20260719190000
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
        // ── giteaManage ──
        $giteaId = $this->addTool($db, 'giteaManage',
            'Manage Gitea organizations, repositories, users, tokens, and webhooks. Operations: createUser, createUserToken, createOrg, createRepo, addMember, createWebhook, getRepo, deleteRepo, searchRepos. Requires gitea.base_url and gitea.admin_token in tool settings.',
            [
                'type' => 'object',
                'properties' => [
                    'operation' => [
                        'type' => 'string',
                        'description' => 'Operation to perform',
                        'enum' => ['createUser', 'createUserToken', 'createOrg', 'createRepo', 'addMember', 'createWebhook', 'getRepo', 'deleteRepo', 'searchRepos']
                    ],
                    'username' => ['type' => 'string', 'description' => 'Username (for createUser, createUserToken, addMember)'],
                    'email' => ['type' => 'string', 'description' => 'Email (for createUser)'],
                    'password' => ['type' => 'string', 'description' => 'Password (for createUser, createUserToken)'],
                    'fullName' => ['type' => 'string', 'description' => 'Full name (optional, for createUser)'],
                    'tokenName' => ['type' => 'string', 'description' => 'Token name (for createUserToken, default: cq-spirit)'],
                    'scopes' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Token scopes (for createUserToken)'],
                    'orgName' => ['type' => 'string', 'description' => 'Organization name (for createOrg, createRepo, addMember)'],
                    'visibility' => ['type' => 'string', 'description' => 'Org visibility (for createOrg): public, limited, private (default: private)'],
                    'description' => ['type' => 'string', 'description' => 'Description (optional, for createOrg, createRepo)'],
                    'repoName' => ['type' => 'string', 'description' => 'Repository name (for createRepo, getRepo, deleteRepo, createWebhook)'],
                    'private' => ['type' => 'boolean', 'description' => 'Make repo private (default: true)'],
                    'autoInit' => ['type' => 'boolean', 'description' => 'Auto-init repo with README (default: true)'],
                    'defaultBranch' => ['type' => 'string', 'description' => 'Default branch name (default: main)'],
                    'owner' => ['type' => 'string', 'description' => 'Repo owner (for getRepo, deleteRepo, createWebhook)'],
                    'webhookUrl' => ['type' => 'string', 'description' => 'Webhook URL (for createWebhook)'],
                    'webhookSecret' => ['type' => 'string', 'description' => 'Webhook secret (optional, for createWebhook)'],
                    'query' => ['type' => 'string', 'description' => 'Search query (for searchRepos)'],
                ],
                'required' => ['operation']
            ],
            'development',
            60
        );
        $this->seedSettings($db, $giteaId, [
            ['gitea.base_url', 'text', 'Gitea Base URL', 'e.g. https://git.yourdomain.com', 1],
            ['gitea.admin_token', 'password', 'Gitea Admin Token', 'Admin API token for user/org management', 2],
            ['gitea.admin_user', 'text', 'Gitea Admin Username', 'Admin username (for BasicAuth token creation)', 3],
            ['gitea.admin_password', 'password', 'Gitea Admin Password', 'Admin password (for BasicAuth token creation)', 4],
        ]);

        // ── coolifyManage ──
        $coolifyId = $this->addTool($db, 'coolifyManage',
            'Manage Coolify projects, applications, deployments, and SSH keys. Operations: createProject, listServers, createApp, setEnv, deploy, deploymentStatus, createSshKey, getApp, deleteApp. Requires coolify.base_url and coolify.api_token in tool settings.',
            [
                'type' => 'object',
                'properties' => [
                    'operation' => [
                        'type' => 'string',
                        'description' => 'Operation to perform',
                        'enum' => ['createProject', 'listServers', 'createApp', 'setEnv', 'deploy', 'deploymentStatus', 'createSshKey', 'getApp', 'deleteApp']
                    ],
                    'projectName' => ['type' => 'string', 'description' => 'Project name (for createProject)'],
                    'projectUuid' => ['type' => 'string', 'description' => 'Project UUID (for createApp)'],
                    'serverUuid' => ['type' => 'string', 'description' => 'Server UUID (for createApp)'],
                    'gitRepository' => ['type' => 'string', 'description' => 'Git repository URL (for createApp)'],
                    'branch' => ['type' => 'string', 'description' => 'Git branch (default: main)'],
                    'privateKeyUuid' => ['type' => 'string', 'description' => 'Coolify SSH key UUID for private repo access'],
                    'buildPack' => ['type' => 'string', 'description' => 'Build pack: nixpacks, dockerfile, dockercompose (default: nixpacks)'],
                    'appName' => ['type' => 'string', 'description' => 'Application name (for createApp)'],
                    'domains' => ['type' => 'string', 'description' => 'Domain(s) for the app (comma-separated)'],
                    'port' => ['type' => 'integer', 'description' => 'Application port'],
                    'appUuid' => ['type' => 'string', 'description' => 'Application UUID (for setEnv, deploy, getApp, deleteApp)'],
                    'envs' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['key' => ['type' => 'string'], 'value' => ['type' => 'string']]], 'description' => 'Environment variables (for setEnv)'],
                    'force' => ['type' => 'boolean', 'description' => 'Force deploy (for deploy)'],
                    'deploymentUuid' => ['type' => 'string', 'description' => 'Deployment UUID (for deploymentStatus)'],
                    'keyName' => ['type' => 'string', 'description' => 'SSH key name (for createSshKey)'],
                    'privateKey' => ['type' => 'string', 'description' => 'SSH private key content (for createSshKey)'],
                ],
                'required' => ['operation']
            ],
            'development',
            61
        );
        $this->seedSettings($db, $coolifyId, [
            ['coolify.base_url', 'text', 'Coolify Base URL', 'e.g. https://coolify.yourdomain.com', 1],
            ['coolify.api_token', 'password', 'Coolify API Token', 'API token with read/write/deploy abilities', 2],
        ]);

        // ── hostingerManage ──
        $hostingerId = $this->addTool($db, 'hostingerManage',
            'Manage Hostinger domains, DNS records, and VPS instances. Operations: checkDomain, listDomains, registerDomain, getDns, setDns, listVps, getVps. Requires hostinger.api_token in tool settings. Note: Hostinger API has a 10 requests/min rate limit.',
            [
                'type' => 'object',
                'properties' => [
                    'operation' => [
                        'type' => 'string',
                        'description' => 'Operation to perform',
                        'enum' => ['checkDomain', 'listDomains', 'registerDomain', 'getDns', 'setDns', 'listVps', 'getVps']
                    ],
                    'domain' => ['type' => 'string', 'description' => 'Domain name (for checkDomain, registerDomain, getDns, setDns)'],
                    'withAlternatives' => ['type' => 'boolean', 'description' => 'Include alternative domain suggestions (for checkDomain)'],
                    'paymentMethodId' => ['type' => 'integer', 'description' => 'Payment method ID (for registerDomain)'],
                    'whoisProfileId' => ['type' => 'integer', 'description' => 'WHOIS profile ID (for registerDomain)'],
                    'records' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['type' => ['type' => 'string'], 'name' => ['type' => 'string'], 'content' => ['type' => 'string'], 'ttl' => ['type' => 'integer']]], 'description' => 'DNS records (for setDns)'],
                    'overwrite' => ['type' => 'boolean', 'description' => 'Overwrite existing DNS records (for setDns, default: false)'],
                    'vmId' => ['type' => 'string', 'description' => 'VPS VM ID (for getVps)'],
                ],
                'required' => ['operation']
            ],
            'development',
            62
        );
        $this->seedSettings($db, $hostingerId, [
            ['hostinger.api_token', 'password', 'Hostinger API Token', 'Bearer token for Hostinger API', 1],
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
        // Delete settings for the three tools
        $stmt = $db->prepare("SELECT id FROM ai_tool WHERE name IN ('giteaManage', 'coolifyManage', 'hostingerManage')");
        $stmt->execute();
        $toolIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        if (!empty($toolIds)) {
            $placeholders = implode(',', array_fill(0, count($toolIds), '?'));
            $db->prepare("DELETE FROM ai_tool_settings WHERE tool_id IN ($placeholders)")->execute($toolIds);
        }

        // Delete the tools
        $db->exec("DELETE FROM ai_tool WHERE name IN ('giteaManage', 'coolifyManage', 'hostingerManage')");
    }
}
