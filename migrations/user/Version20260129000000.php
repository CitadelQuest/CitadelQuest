<?php

/**
 * Migration: Spirit Memory v3 - Graph-Based Knowledge System
 * 
 * Creates the database schema for the new Spirit Memory system with:
 * - memory_nodes: Individual pieces of knowledge
 * - memory_relationships: Graph edges between memories
 * - memory_tags: Flexible tagging system
 * - consolidation_log: Audit trail for memory operations
 * 
 * @see /docs/features/spirit-memory-v3.md
 */
class UserMigration_20260129000000
{
    public function up(\PDO $db): void
    {
        // Memory nodes - individual pieces of knowledge
        $db->exec("
            CREATE TABLE IF NOT EXISTS spirit_memory_nodes (
                id TEXT PRIMARY KEY,
                spirit_id TEXT NOT NULL,
                content TEXT NOT NULL,
                summary TEXT,
                category TEXT NOT NULL DEFAULT 'knowledge',
                importance REAL DEFAULT 0.5,
                confidence REAL DEFAULT 1.0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_accessed DATETIME,
                access_count INTEGER DEFAULT 0,
                source_type TEXT,
                source_ref TEXT,
                is_active INTEGER DEFAULT 1,
                superseded_by TEXT,
                FOREIGN KEY (spirit_id) REFERENCES spirits(id) ON DELETE CASCADE,
                FOREIGN KEY (superseded_by) REFERENCES spirit_memory_nodes(id)
            )
        ");

        // Memory relationships - graph edges
        $db->exec("
            CREATE TABLE IF NOT EXISTS spirit_memory_relationships (
                id TEXT PRIMARY KEY,
                source_id TEXT NOT NULL,
                target_id TEXT NOT NULL,
                type TEXT NOT NULL,
                strength REAL DEFAULT 1.0,
                context TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (source_id) REFERENCES spirit_memory_nodes(id) ON DELETE CASCADE,
                FOREIGN KEY (target_id) REFERENCES spirit_memory_nodes(id) ON DELETE CASCADE
            )
        ");

        // Memory tags for filtering
        $db->exec("
            CREATE TABLE IF NOT EXISTS spirit_memory_tags (
                id TEXT PRIMARY KEY,
                memory_id TEXT NOT NULL,
                tag TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (memory_id) REFERENCES spirit_memory_nodes(id) ON DELETE CASCADE
            )
        ");

        // Consolidation log for debugging and rollback
        $db->exec("
            CREATE TABLE IF NOT EXISTS spirit_memory_consolidation_log (
                id TEXT PRIMARY KEY,
                spirit_id TEXT NOT NULL,
                action TEXT NOT NULL,
                affected_ids TEXT NOT NULL,
                details TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (spirit_id) REFERENCES spirits(id) ON DELETE CASCADE
            )
        ");

        // Indexes for performance
        $db->exec("CREATE INDEX IF NOT EXISTS idx_memory_nodes_spirit ON spirit_memory_nodes(spirit_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_memory_nodes_category ON spirit_memory_nodes(category)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_memory_nodes_importance ON spirit_memory_nodes(importance)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_memory_nodes_last_accessed ON spirit_memory_nodes(last_accessed)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_memory_nodes_is_active ON spirit_memory_nodes(is_active)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_memory_relationships_source ON spirit_memory_relationships(source_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_memory_relationships_target ON spirit_memory_relationships(target_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_memory_relationships_type ON spirit_memory_relationships(type)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_memory_tags_tag ON spirit_memory_tags(tag)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_memory_tags_memory ON spirit_memory_tags(memory_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_consolidation_log_spirit ON spirit_memory_consolidation_log(spirit_id)");

        // Add Spirit Memory v3 AI Tools
        $this->addMemoryTools($db);
    }

    /**
     * Add Spirit Memory v3 AI Tools
     */
    private function addMemoryTools(\PDO $db): void
    {
        // memoryStore tool
        $this->addTool($db, 'memoryStore',
            'Store a new memory in your knowledge graph. Use this to remember important facts, preferences, insights, or any information worth preserving for future conversations.',
            [
                'type' => 'object',
                'properties' => [
                    'content' => [
                        'type' => 'string',
                        'description' => 'The memory content to store (be specific and self-contained)'
                    ],
                    'category' => [
                        'type' => 'string',
                        'enum' => ['conversation', 'thought', 'knowledge', 'fact', 'preference'],
                        'description' => 'Category of the memory'
                    ],
                    'importance' => [
                        'type' => 'number',
                        'description' => 'Importance score from 0.0 to 1.0 (optional, default 0.5)'
                    ],
                    'tags' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Tags for categorization and search (optional)'
                    ],
                    'relatesTo' => [
                        'type' => 'string',
                        'description' => 'Summary/keyword of an existing memory to link to (optional)'
                    ]
                ],
                'required' => ['content', 'category']
            ],
            1 // Active by default
        );

        // memoryRecall tool
        $this->addTool($db, 'memoryRecall',
            'Search and retrieve memories from your knowledge graph. Use this to recall information about the user, past conversations, or any stored knowledge.',
            [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Search query (keywords or natural language)'
                    ],
                    'category' => [
                        'type' => 'string',
                        'enum' => ['conversation', 'thought', 'knowledge', 'fact', 'preference'],
                        'description' => 'Filter by category (optional)'
                    ],
                    'tags' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Filter by tags (optional)'
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of memories to return (optional, default 10)'
                    ],
                    'includeRelated' => [
                        'type' => 'boolean',
                        'description' => 'Include related memories via graph traversal (optional, default true)'
                    ]
                ],
                'required' => ['query']
            ],
            1 // Active by default
        );

        // memoryUpdate tool
        $this->addTool($db, 'memoryUpdate',
            'Update an existing memory with new information. Creates an EVOLVED_INTO relationship to preserve history while updating the knowledge.',
            [
                'type' => 'object',
                'properties' => [
                    'memoryId' => [
                        'type' => 'string',
                        'description' => 'ID of the memory to update'
                    ],
                    'newContent' => [
                        'type' => 'string',
                        'description' => 'Updated content for the memory'
                    ],
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Why this memory is being updated (optional)'
                    ]
                ],
                'required' => ['memoryId', 'newContent']
            ],
            1 // Active by default
        );

        // memoryForget tool
        $this->addTool($db, 'memoryForget',
            'Mark a memory as no longer relevant (soft delete). Use this when information becomes outdated or incorrect.',
            [
                'type' => 'object',
                'properties' => [
                    'memoryId' => [
                        'type' => 'string',
                        'description' => 'ID of the memory to forget'
                    ],
                    'reason' => [
                        'type' => 'string',
                        'description' => 'Why this memory should be forgotten (optional)'
                    ]
                ],
                'required' => ['memoryId']
            ],
            1 // Active by default
        );
    }

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

    /**
     * Helper method to add a tool if it doesn't exist
     */
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
                date('Y-m-d H:i:s')
            ]);
        }
    }

    public function down(\PDO $db): void
    {
        // Remove AI tools
        $db->exec("DELETE FROM ai_tool WHERE name IN ('memoryStore', 'memoryRecall', 'memoryUpdate', 'memoryForget')");

        // Drop tables (in reverse order due to foreign keys)
        $db->exec("DROP TABLE IF EXISTS spirit_memory_consolidation_log");
        $db->exec("DROP TABLE IF EXISTS spirit_memory_tags");
        $db->exec("DROP TABLE IF EXISTS spirit_memory_relationships");
        $db->exec("DROP TABLE IF EXISTS spirit_memory_nodes");
    }
}
