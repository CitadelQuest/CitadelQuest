<?php

/**
 * Migration: Fix git tool descriptions for per-repo-path credentials
 *
 * Updates gitSetCredentials and gitOperation tool descriptions to clarify
 * that credentials are stored per repo path (not per project only).
 * Each localRepoPath within a project can have its own credentials.
 */
class UserMigration_20260726000000
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
        // Update gitSetCredentials — clarify per-repo-path credential storage
        $gitSetCredsParams = [
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
                ],
                'localRepoPath' => [
                    'type' => 'string',
                    'description' => 'Local repo working directory, relative to the project dir (optional, default: "repo"). Credentials are stored per repo path, so each repo within a project can have different credentials. Path-traversal safe: "." and ".." segments are stripped. Use this to clone/work with multiple repos per project (e.g. "repo" for backend, "frontend" for frontend).'
                ]
            ],
            'required' => ['projectId', 'authMethod']
        ];

        $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, updated_at = ? WHERE name = 'gitSetCredentials'");
        $stmt->execute([json_encode($gitSetCredsParams), date('Y-m-d H:i:s')]);

        // Update gitOperation — clarify localRepoPath selects which repo's credentials to use
        $gitOperationParams = [
            'type' => 'object',
            'properties' => [
                'projectId' => [
                    'type' => 'string',
                    'description' => 'Project ID (default: "general")'
                ],
                'operation' => [
                    'type' => 'string',
                    'description' => 'Operation to perform',
                    'enum' => ['clone', 'pull', 'commitAndPush', 'status', 'diff', 'log', 'remoteAddOrigin']
                ],
                'localRepoPath' => [
                    'type' => 'string',
                    'description' => 'Which repo working directory to operate on (relative to project dir). Credentials are looked up per repo path. If omitted, defaults to "repo". Use this to operate on a different repo within the same project (e.g. "frontend" while default is "repo"). Path-traversal safe.'
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
                'remoteUrl' => [
                    'type' => 'string',
                    'description' => 'Remote repository URL for remoteAddOrigin operation (HTTPS or SSH)'
                ],
                'remoteName' => [
                    'type' => 'string',
                    'description' => 'Remote name for remoteAddOrigin operation (default: "origin")'
                ],
                'logCount' => [
                    'type' => 'integer',
                    'description' => 'Number of commits to show (default: 10, max: 50)'
                ]
            ],
            'required' => ['projectId', 'operation']
        ];

        $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, updated_at = ? WHERE name = 'gitOperation'");
        $stmt->execute([json_encode($gitOperationParams), date('Y-m-d H:i:s')]);
    }

    public function down(\PDO $db): void
    {
        // No destructive schema changes — nothing to revert
    }
}
