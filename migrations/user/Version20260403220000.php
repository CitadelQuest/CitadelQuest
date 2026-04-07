<?php

/**
 * Migration: Add Git AI Tools for CQ SW IDE
 * 
 * Adds gitOperation and gitSetCredentials tools enabling Spirit AI to:
 * - Clone repositories
 * - Pull updates
 * - Commit and push changes
 * - View status, diff, log
 * - Manage git credentials
 */
class UserMigration_20260403220000
{
    /**
     * Generate a UUID v4
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
    
    public function up(\PDO $db): void
    {
        // Add gitOperation tool
        $this->addTool($db, 'gitOperation', 
            'Run git commands. Supported operations: clone (clone repository), pull (update from remote), commitAndPush (stage, commit, and push changes), status (check working tree status), diff (show changes), log (show commit history). Use gitSetCredentials first for private repositories.',
            [
                'type' => 'object',
                'properties' => [
                    'projectId' => [
                        'type' => 'string',
                        'description' => 'Project ID (default: "general")'
                    ],
                    'operation' => [
                        'type' => 'string',
                        'description' => 'Operation to perform',
                        'enum' => ['clone', 'pull', 'commitAndPush', 'status', 'diff', 'log']
                    ],
                    'cloneRepoUrl' => [
                        'type' => 'string',
                        'description' => 'Repository URL for clone operation (HTTPS or SSH)'
                    ],
                    'branch' => [
                        'type' => 'string',
                        'description' => 'Branch name for clone/pull operations'
                    ],
                    'cloneDepth' => [
                        'type' => 'integer',
                        'description' => 'Shallow clone depth (e.g., 1 for latest commit only)'
                    ],
                    'pullRemote' => [
                        'type' => 'string',
                        'description' => 'Remote name for pull operation (default: origin)'
                    ],
                    'commitMessage' => [
                        'type' => 'string',
                        'description' => 'Commit message for commitAndPush operation'
                    ],
                    'commitFiles' => [
                        'type' => 'string',
                        'description' => 'Files to commit: "all" or comma-separated paths (default: "all")'
                    ],
                    'commitAndPush' => [
                        'type' => 'boolean',
                        'description' => 'Whether to push after commit (default: true)'
                    ],
                    'diffFile' => [
                        'type' => 'string',
                        'description' => 'Specific file to diff (default: all changes)'
                    ],
                    'diffStaged' => [
                        'type' => 'boolean',
                        'description' => 'Show staged changes instead of unstaged (default: false)'
                    ],
                    'logCount' => [
                        'type' => 'integer',
                        'description' => 'Number of commits to show (default: 10, max: 50)'
                    ]
                ],
                'required' => ['projectId', 'operation']
            ],
            0 // not Active by default
        );

        // Add gitSetCredentials tool
        $this->addTool($db, 'gitSetCredentials',
            'Store git credentials for a project. Supports HTTPS (username + token) and SSH (private key). Credentials are securely stored per-project and used for authentication during git operations.',
            [
                'type' => 'object',
                'properties' => [
                    'projectId' => [
                        'type' => 'string',
                        'description' => 'Project ID (default: "general")'
                    ],
                    'authMethod' => [
                        'type' => 'string',
                        'description' => 'Authentication method',
                        'enum' => ['https', 'ssh']
                    ],
                    'username' => [
                        'type' => 'string',
                        'description' => 'Username for HTTPS authentication'
                    ],
                    'token' => [
                        'type' => 'string',
                        'description' => 'Personal access token or password for HTTPS'
                    ],
                    'sshPrivateKey' => [
                        'type' => 'string',
                        'description' => 'SSH private key content for SSH authentication'
                    ],
                    'userName' => [
                        'type' => 'string',
                        'description' => 'Git user.name for commits (optional)'
                    ],
                    'userEmail' => [
                        'type' => 'string',
                        'description' => 'Git user.email for commits (optional)'
                    ]
                ],
                'required' => ['projectId', 'authMethod']
            ],
            0 // not Active by default
        );
    }
    
    /**
     * Helper method to add a tool if it doesn't exist
     */
    private function addTool(\PDO $db, string $name, string $description, array $parameters, int $isActive = 0): void
    {
        // Check if tool exists
        $stmt = $db->prepare("SELECT id FROM ai_tool WHERE name = ?");
        $stmt->execute([$name]);
        if (!$stmt->fetch(\PDO::FETCH_ASSOC)) {
            // Insert tool
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
                date('Y-m-d H:i:s')
            ]);
        }
    }

    public function down(\PDO $db): void
    {
        // Remove git tools
        $db->exec("DELETE FROM ai_tool WHERE name IN ('gitOperation', 'gitSetCredentials')");
    }
}
