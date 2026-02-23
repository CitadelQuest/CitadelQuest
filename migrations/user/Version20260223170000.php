<?php

/**
 * Migration: Create cq_share table
 * 
 * CQ Share — core sharing feature for CitadelQuest.
 * Enables users to share files and Memory Packs with CQ Contacts or publicly via URL.
 * 
 * @see /docs/CQ-SHARE.md
 */
class UserMigration_20260223170000
{
    public function up(\PDO $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS cq_share (
                id VARCHAR(36) PRIMARY KEY,
                source_type VARCHAR(36) NOT NULL DEFAULT 'file',
                source_id VARCHAR(36) NOT NULL,
                title TEXT NOT NULL,
                share_url TEXT NOT NULL,
                scope INTEGER NOT NULL DEFAULT 1,
                is_active INTEGER NOT NULL DEFAULT 1,
                views INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(share_url)
            )
        ");

        $db->exec("CREATE INDEX IF NOT EXISTS idx_cq_share_source ON cq_share(source_type, source_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_cq_share_active ON cq_share(is_active)");
    }

    public function down(\PDO $db): void
    {
        $db->exec("DROP TABLE IF EXISTS cq_share");
    }
}
