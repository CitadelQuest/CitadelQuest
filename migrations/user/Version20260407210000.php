<?php

/**
 * Migration: Add ai_tool_settings table, add category + display_order to ai_tool
 * 
 * - Creates ai_tool_settings table for per-tool configurable settings with type info for UI rendering
 * - Adds category and display_order columns to ai_tool for UI grouping and ordering
 * - Seeds default categories and display_order for existing tools
 */
class UserMigration_20260407210000
{
    public function up(\PDO $db): void
    {
        // 1. Create ai_tool_settings table
        $db->exec('
            CREATE TABLE IF NOT EXISTS ai_tool_settings (
                id TEXT PRIMARY KEY,
                tool_id TEXT NOT NULL,
                key TEXT NOT NULL,
                value TEXT,
                type TEXT DEFAULT \'text\',
                label TEXT,
                description TEXT,
                display_order INTEGER DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        ');

        $db->exec('CREATE INDEX IF NOT EXISTS idx_ai_tool_settings_tool_id ON ai_tool_settings(tool_id)');
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_ai_tool_settings_tool_key ON ai_tool_settings(tool_id, key)');

        // 2. Add category and display_order columns to ai_tool
        // Check if columns exist first (SQLite doesn't support IF NOT EXISTS for ALTER TABLE)
        $columns = $db->query("PRAGMA table_info(ai_tool)")->fetchAll(\PDO::FETCH_ASSOC);
        $columnNames = array_column($columns, 'name');

        if (!in_array('category', $columnNames)) {
            $db->exec("ALTER TABLE ai_tool ADD COLUMN category TEXT DEFAULT 'general'");
        }
        if (!in_array('display_order', $columnNames)) {
            $db->exec("ALTER TABLE ai_tool ADD COLUMN display_order INTEGER DEFAULT 0");
        }

        // 3. Seed categories and display_order for existing tools
        $categoryMap = [
            // File Management
            'fileManage'    => ['category' => 'file', 'order' => 10],
            'fileUpdate'    => ['category' => 'file', 'order' => 20],
            'fileSearch'    => ['category' => 'file', 'order' => 30],

            // Web
            'fetchURL'      => ['category' => 'web', 'order' => 10],

            // Image
            'spiritCreateOrEditImage'    => ['category' => 'image', 'order' => 10],
            'spiritCreateDiffusionImage' => ['category' => 'image', 'order' => 20],

            // Memory
            'memoryStore'   => ['category' => 'memory', 'order' => 10],
            'memoryRecall'  => ['category' => 'memory', 'order' => 20],
            'memoryUpdate'  => ['category' => 'memory', 'order' => 30],
            'memoryForget'  => ['category' => 'memory', 'order' => 40],
            'memoryExtract' => ['category' => 'memory', 'order' => 50],
            'memorySource'  => ['category' => 'memory', 'order' => 60],

            // Profile
            'cqProfileManage'      => ['category' => 'profile', 'order' => 10],
            'cqProfileManageGroup' => ['category' => 'profile', 'order' => 20],
            'cqProfileManageItem'  => ['category' => 'profile', 'order' => 30],

            // Development
            'gitOperation'      => ['category' => 'development', 'order' => 10],
            'gitSetCredentials' => ['category' => 'development', 'order' => 20],

            // Spirit / Meta
            'aiToolList'      => ['category' => 'spirit', 'order' => 10],
            'aiToolSetActive' => ['category' => 'spirit', 'order' => 20],

            // Utility
            'createSepaEuroPaymentQrCode' => ['category' => 'utility', 'order' => 20],
        ];

        $stmt = $db->prepare("UPDATE ai_tool SET category = ?, display_order = ? WHERE name = ?");
        foreach ($categoryMap as $name => $data) {
            $stmt->execute([$data['category'], $data['order'], $name]);
        }
    }

    public function down(\PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS ai_tool_settings');
        // SQLite doesn't support DROP COLUMN, so we leave category/display_order in place
    }
}
