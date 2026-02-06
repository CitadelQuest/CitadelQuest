<?php

/**
 * Migration: Drop legacy Spirit Memory v3 tables
 * 
 * Spirit Memory has been fully migrated from user database tables
 * to CQ Memory Pack (.cqmpack) SQLite files.
 * 
 * Drops:
 * - spirit_memory_jobs (async extraction jobs → now in memory_jobs inside .cqmpack)
 * - spirit_memory_tags (memory tags → now in memory_tags inside .cqmpack)
 * - spirit_memory_relationships (graph edges → now in memory_relationships inside .cqmpack)
 * - spirit_memory_consolidation_log (debug log — no longer used)
 * - spirit_memory_nodes (memory nodes → now in memory_nodes inside .cqmpack)
 * 
 * @see /docs/features/spirit-memory-2-cq-memory-pack.md
 */
class UserMigration_20260206080000
{
    public function up(\PDO $db): void
    {
        // Drop in correct order (foreign key dependencies)
        $db->exec("DROP TABLE IF EXISTS spirit_memory_jobs");
        $db->exec("DROP TABLE IF EXISTS spirit_memory_consolidation_log");
        $db->exec("DROP TABLE IF EXISTS spirit_memory_tags");
        $db->exec("DROP TABLE IF EXISTS spirit_memory_relationships");
        $db->exec("DROP TABLE IF EXISTS spirit_memory_nodes");
    }

    public function down(\PDO $db): void
    {
        // Re-creating legacy tables is not supported.
        // Data has been migrated to CQ Memory Pack files.
    }
}
