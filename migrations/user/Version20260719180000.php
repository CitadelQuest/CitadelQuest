<?php

/**
 * Migration: Add localRepoPath parameter to gitOperation tool
 *
 * Follow-up to Version20260719173000 (which only updated gitSetCredentials).
 * Adds optional `localRepoPath` as a per-call override to gitOperation, so a
 * Spirit can target a specific repo subdir within a project without changing
 * the stored default. Enables multiple repos per project (e.g. "repo" + "theme").
 */
class UserMigration_20260719180000
{
    public function up(\PDO $db): void
    {
        $parameters = [
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
                'localRepoPath' => [
                    'type' => 'string',
                    'description' => 'Per-call override for the local repo working directory (relative to project dir). If omitted, uses the stored default from gitSetCredentials. Use this to operate on a different repo within the same project (e.g. "theme" while default is "repo"). Path-traversal safe.'
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
        ];

        $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, updated_at = ? WHERE name = 'gitOperation'");
        $stmt->execute([json_encode($parameters), date('Y-m-d H:i:s')]);
    }

    public function down(\PDO $db): void
    {
        $parameters = [
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
        ];

        $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, updated_at = ? WHERE name = 'gitOperation'");
        $stmt->execute([json_encode($parameters), date('Y-m-d H:i:s')]);
    }
}
