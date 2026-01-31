<?php

/**
 * Migration: Spirit Memory v3.1 - Recursive/Fractal Extraction Support
 * 
 * Adds:
 * - source_range field to spirit_memory_nodes for tracking content block positions
 * - spirit_memory_jobs table for async processing of large document extractions
 * 
 * @see /docs/features/spirit-memory-v3.md
 */
class UserMigration_20260131000000
{
    public function up(\PDO $db): void
    {
        // Add source_range column to spirit_memory_nodes
        // Format: "start_line:end_line" (1-indexed) or "1:N" for whole document with N lines
        $db->exec("ALTER TABLE spirit_memory_nodes ADD COLUMN source_range TEXT DEFAULT NULL");

        // Create jobs table for async memory extraction processing
        $db->exec("
            CREATE TABLE IF NOT EXISTS spirit_memory_jobs (
                id TEXT PRIMARY KEY,
                spirit_id TEXT NOT NULL,
                type TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                payload TEXT NOT NULL,
                result TEXT,
                progress INTEGER DEFAULT 0,
                total_steps INTEGER DEFAULT 0,
                error TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                started_at DATETIME,
                completed_at DATETIME,
                FOREIGN KEY (spirit_id) REFERENCES spirits(id) ON DELETE CASCADE
            )
        ");

        // Index for efficient job polling
        $db->exec("CREATE INDEX IF NOT EXISTS idx_memory_jobs_status ON spirit_memory_jobs(status)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_memory_jobs_spirit ON spirit_memory_jobs(spirit_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_memory_jobs_created ON spirit_memory_jobs(created_at)");

        // Update memoryExtract tool to include new parameters
        $this->updateMemoryExtractTool($db);
    }

    /**
     * Update memoryExtract tool with recursive extraction parameters
     */
    private function updateMemoryExtractTool(\PDO $db): void
    {
        $parameters = [
            'type' => 'object',
            'properties' => [
                'content' => [
                    'type' => 'string',
                    'description' => 'Raw content to extract memories from (optional if sourceType+sourceRef provided)'
                ],
                'sourceType' => [
                    'type' => 'string',
                    'enum' => ['document', 'legacy_memory', 'spirit_conversation', 'url', 'derived'],
                    'description' => 'Type of source for auto-loading content'
                ],
                'sourceRef' => [
                    'type' => 'string',
                    'description' => 'Reference to source (format depends on sourceType)'
                ],
                'context' => [
                    'type' => 'string',
                    'description' => 'Additional context about the content (optional)'
                ],
                'maxDepth' => [
                    'type' => 'integer',
                    'description' => 'Maximum recursion depth for hierarchical extraction (default 3, use 1 for flat extraction)'
                ],
                'force' => [
                    'type' => 'boolean',
                    'description' => 'Force re-extraction even if source was already processed (default false)'
                ]
            ],
            'required' => []
        ];

        $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, description = ?, updated_at = ? WHERE name = ?");
        $stmt->execute([
            json_encode($parameters),
            'Extract memories from content using recursive/fractal hierarchical extraction. Automatically splits large documents into logical sections, creating a tree structure of summaries and detailed memories. Each level preserves source references for zero data loss.',
            date('Y-m-d H:i:s'),
            'memoryExtract'
        ]);
    }

    public function down(\PDO $db): void
    {
        // SQLite doesn't support DROP COLUMN easily, so we skip reverting source_range
        // Drop jobs table
        $db->exec("DROP TABLE IF EXISTS spirit_memory_jobs");
    }
}
