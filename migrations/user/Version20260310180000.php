<?php

/**
 * Migration: Create CQ Share Group tables
 * 
 * cq_share_group — named, ordered groups for organizing shared items on CQ Profile
 * cq_share_group_item — pivot table linking shares to groups with per-item display config
 * 
 * @see /docs/features/CQ-SHARE-GROUPS.md
 */
class UserMigration_20260310180000
{
    public function up(\PDO $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS cq_share_group (
                id VARCHAR(36) PRIMARY KEY,
                title TEXT NOT NULL,
                mdi_icon VARCHAR(64) NOT NULL DEFAULT 'mdi-folder',
                scope INTEGER NOT NULL DEFAULT 0,
                show_in_nav INTEGER NOT NULL DEFAULT 1,
                is_active INTEGER NOT NULL DEFAULT 1,
                \"order\" INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $db->exec("CREATE INDEX IF NOT EXISTS idx_cq_share_group_active ON cq_share_group(is_active)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_cq_share_group_order ON cq_share_group(\"order\")");

        $db->exec("
            CREATE TABLE IF NOT EXISTS cq_share_group_item (
                id VARCHAR(36) PRIMARY KEY,
                group_id VARCHAR(36) NOT NULL,
                share_id VARCHAR(36) NOT NULL,
                display_style INTEGER DEFAULT NULL,
                description_display_style INTEGER DEFAULT NULL,
                show_header INTEGER NOT NULL DEFAULT 1,
                \"order\" INTEGER NOT NULL DEFAULT 0,
                UNIQUE(group_id, share_id),
                FOREIGN KEY(group_id) REFERENCES cq_share_group(id) ON DELETE CASCADE
            )
        ");

        $db->exec("CREATE INDEX IF NOT EXISTS idx_cq_share_group_item_group ON cq_share_group_item(group_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_cq_share_group_item_share ON cq_share_group_item(share_id)");
    }

    public function down(\PDO $db): void
    {
        $db->exec("DROP TABLE IF EXISTS cq_share_group_item");
        $db->exec("DROP TABLE IF EXISTS cq_share_group");
    }
}
