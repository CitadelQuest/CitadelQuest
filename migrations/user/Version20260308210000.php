<?php

/**
 * Migration: Add display_style column to cq_share table
 * 
 * Adds per-share display style control:
 *   0 = preview off (title only)
 *   1 = preview on (default, current behavior — thumbnail/truncated)
 *   2 = full content (full-width images, full text, Memory Packs same as 1)
 */
class UserMigration_20260308210000
{
    public function up(\PDO $db): void
    {
        // Check if column already exists
        $cols = $db->query("PRAGMA table_info(cq_share)")->fetchAll(\PDO::FETCH_ASSOC);
        $colNames = array_column($cols, 'name');

        if (!in_array('display_style', $colNames)) {
            $db->exec("ALTER TABLE cq_share ADD COLUMN display_style INTEGER DEFAULT 1");
        }
    }

    public function down(\PDO $db): void
    {
        // SQLite doesn't support DROP COLUMN before 3.35.0
    }
}
