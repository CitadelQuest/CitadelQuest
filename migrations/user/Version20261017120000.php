<?php

/**
 * Migration: Add optional `projectId` parameter to `memoryMap` and `memoryReadNode` AI Tools.
 *
 * Spirit Memory is project-scoped, and a Spirit's library can also reference
 * `.cqmpack` files from other projects assigned to that Spirit. Adding `projectId`
 * lets the Spirit inspect memory maps and read memory nodes in any project.
 *
 * See docs/features/CQ-PROJECT.md.
 */
class UserMigration_20261017120000
{
    public function up(\PDO $db): void
    {
        $now = date('Y-m-d H:i:s');

        $memoryMapParams = json_encode([
            'type' => 'object',
            'properties' => [
                'source' => [
                    'type' => 'string',
                    'description' => 'What to map: "full" for all libraries, or a .cqmlib filename, or a .cqmpack filename, or a memory node ID (UUID) for a subtree.'
                ],
                'maxDepth' => [
                    'type' => 'integer',
                    'description' => 'Maximum depth of child nodes to show (default 0 (root nodes only)).'
                ],
                'projectId' => [
                    'type' => 'string',
                    'description' => 'Optional project ID (workspace) to map (defaults to the active project).'
                ]
            ],
            'required' => ['source']
        ]);

        $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, updated_at = ? WHERE name = 'memoryMap'");
        $stmt->execute([$memoryMapParams, $now]);

        $memoryReadNodeParams = json_encode([
            'type' => 'object',
            'properties' => [
                'memoryId' => [
                    'type' => 'string',
                    'description' => 'Memory node ID (UUID) to read.'
                ],
                'includeContent' => [
                    'type' => 'boolean',
                    'description' => 'Whether to include the full node content (default true). Set to false for metadata-only inspection.'
                ],
                'cqmpackPath' => [
                    'type' => 'string',
                    'description' => 'Optional pack path (e.g. "/memory/packs/Foo.cqmpack") to search in a pack that is not in the Spirit library. Use this when the memory node was found via memoryMap on a non-library pack.'
                ],
                'projectId' => [
                    'type' => 'string',
                    'description' => 'Optional project ID (workspace) to search in (defaults to the active project + all packs in the Spirit library).'
                ]
            ],
            'required' => ['memoryId']
        ]);

        $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, updated_at = ? WHERE name = 'memoryReadNode'");
        $stmt->execute([$memoryReadNodeParams, $now]);
    }

    public function down(\PDO $db): void
    {
        // No-op
    }
}
