<?php

/**
 * Migration: Add memoryMap and memoryReadNode AI Tools
 *
 * memoryMap — structural tree view of CQ Memory hierarchy (Library → Pack → Root Nodes → Child Nodes).
 *   Accepts: "full", library filename (.cqmlib), pack filename (.cqmpack), or memory node ID.
 *
 * memoryReadNode — read a memory node's full content + subtree map + all relationships.
 *   Accepts: memory node ID (UUID).
 *
 * @see /docs/features/CQ-MEMORY.md
 */
class UserMigration_20260721220000
{
    public function up(\PDO $db): void
    {
        $tools = [
            [
                'name' => 'memoryMap',
                'description' => "Structural tree view of CQ Memory hierarchy. Shows Library -> Pack -> Root Nodes -> Child Nodes as a compact ASCII tree. Use this to explore the Spirit's entire memory structure, find specific packs or nodes, and understand the knowledge graph layout. Accepts: \"full\" (all libraries), a .cqmlib filename (specific library), a .cqmpack filename (specific pack), or a memory node ID (subtree of that node). Optional maxDepth parameter (default 5) controls how deep child nodes are shown.",
                'parameters' => json_encode([
                    'type' => 'object',
                    'properties' => [
                        'source' => [
                            'type' => 'string',
                            'description' => 'What to map: "full" for all libraries, or a .cqmlib filename, or a .cqmpack filename, or a memory node ID (UUID) for a subtree.'
                        ],
                        'maxDepth' => [
                            'type' => 'integer',
                            'description' => 'Maximum depth of child nodes to show (default 0 (root nodes only)).'
                        ]
                    ],
                    'required' => ['source']
                ])
            ],
            [
                'name' => 'memoryReadNode',
                'description' => "Read a memory node's full content, metadata, subtree map, and all relationships. Use this for deep research: when you have a memory node ID (from memoryMap or memoryRecall), this tool gives you the complete node info including content, summary, category, importance, tags, source reference, child node tree, and relationship graph (CONTRADICTS, REINFORCES, RELATES_TO with direction and strength).",
                'parameters' => json_encode([
                    'type' => 'object',
                    'properties' => [
                        'memoryId' => [
                            'type' => 'string',
                            'description' => 'Memory node ID (UUID) to read.'
                        ],
                        'includeContent' => [
                            'type' => 'boolean',
                            'description' => 'Whether to include the full node content (default true). Set to false for metadata-only inspection.'
                        ]
                    ],
                    'required' => ['memoryId']
                ])
            ]
        ];

        foreach ($tools as $tool) {
            // Check if tool already exists
            $stmt = $db->prepare("SELECT id FROM ai_tool WHERE name = ?");
            $stmt->execute([$tool['name']]);
            if ($stmt->fetch(\PDO::FETCH_ASSOC)) {
                continue;
            }

            $stmt = $db->prepare(
                'INSERT INTO ai_tool (id, name, description, parameters, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $this->generateUuid(),
                $tool['name'],
                $tool['description'],
                $tool['parameters'],
                1,
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s')
            ]);
        }
    }

    public function down(\PDO $db): void
    {
        $db->exec("DELETE FROM ai_tool WHERE name IN ('memoryMap', 'memoryReadNode')");
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
