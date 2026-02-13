<?php

/**
 * Migration: Add memorySource AI Tool
 * 
 * New tool for fact-checking: retrieve original source content of a memory node.
 * Accepts either a memory_id (UUID) or source_ref string, with optional line range.
 * Searches across all CQ Memory Packs in Spirit's main Library.
 * 
 * @see /docs/features/CQ-MEMORY.md
 */
class UserMigration_20260213100000
{
    public function up(\PDO $db): void
    {
        // Check if tool already exists
        $stmt = $db->prepare("SELECT id FROM ai_tool WHERE name = ?");
        $stmt->execute(['memorySource']);
        if ($stmt->fetch(\PDO::FETCH_ASSOC)) {
            return; // Already exists
        }

        $stmt = $db->prepare(
            'INSERT INTO ai_tool (id, name, description, parameters, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $this->generateUuid(),
            'memorySource',
            'Retrieve original source content of a memory. Use this for fact-checking: when recalled memories include source_ref or memory IDs, fetch the full original document/conversation/URL content they were extracted from. Searches across all CQ Memory Packs in the Spirit\'s Library.',
            json_encode([
                'type' => 'object',
                'properties' => [
                    'source' => [
                        'type' => 'string',
                        'description' => 'Memory node ID (UUID) or source_ref string (e.g., "general:/docs:readme.md", conversation ID, URL). The tool will look up the original source content.'
                    ],
                    'range' => [
                        'type' => 'string',
                        'description' => 'Line range to return. "all" for full content (default), or "start:end" for specific lines (e.g., "10:25" for lines 10-25).'
                    ]
                ],
                'required' => ['source']
            ]),
            1, // Active by default
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s')
        ]);
    }

    public function down(\PDO $db): void
    {
        $db->exec("DELETE FROM ai_tool WHERE name = 'memorySource'");
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
