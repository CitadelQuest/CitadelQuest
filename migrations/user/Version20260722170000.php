<?php

/**
 * Migration: Update memoryReadNode AI tool parameters to include cqmpackPath
 *
 * Adds optional cqmpackPath parameter so Spirit can read nodes from packs
 * that are not in its library (e.g. discovered via memoryMap).
 */
class UserMigration_20260722170000
{
    public function up(\PDO $db): void
    {
        $parameters = json_encode([
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
                ]
            ],
            'required' => ['memoryId']
        ]);

        $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, updated_at = ? WHERE name = 'memoryReadNode'");
        $stmt->execute([$parameters, date('Y-m-d H:i:s')]);
    }

    public function down(\PDO $db): void
    {
        // No-op: reverting would require knowing the previous parameters JSON
    }
}
