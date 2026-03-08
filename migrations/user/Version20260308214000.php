<?php

/**
 * Migration: Add description and description_display_style columns to cq_share table
 * 
 * description: free-text description for the share item
 * description_display_style: controls position relative to content preview
 *   0 = row above content preview
 *   1 = row below content preview (default)
 *   2 = column left of content preview
 *   3 = column right of content preview
 */
class UserMigration_20260308214000
{
    public function up(\PDO $db): void
    {
        $cols = $db->query("PRAGMA table_info(cq_share)")->fetchAll(\PDO::FETCH_ASSOC);
        $colNames = array_column($cols, 'name');

        if (!in_array('description', $colNames)) {
            $db->exec("ALTER TABLE cq_share ADD COLUMN description TEXT DEFAULT ''");
        }

        if (!in_array('description_display_style', $colNames)) {
            $db->exec("ALTER TABLE cq_share ADD COLUMN description_display_style INTEGER DEFAULT 1");
        }
    }

    public function down(\PDO $db): void
    {
        // SQLite doesn't support DROP COLUMN before 3.35.0
    }
}
