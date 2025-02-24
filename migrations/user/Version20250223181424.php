<?php

class UserMigration_20250223181424
{
    public function up(PDO $db): void
    {
        $db->exec('CREATE TABLE IF NOT EXISTS diary_entries (
            id VARCHAR(36) PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            content TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_encrypted BOOLEAN DEFAULT 1,
            is_favorite BOOLEAN DEFAULT 0,
            tags TEXT DEFAULT NULL,
            mood VARCHAR(50) DEFAULT NULL
        )');
        
        $db->exec('CREATE INDEX IF NOT EXISTS idx_diary_entries_created_at ON diary_entries(created_at)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_diary_entries_is_favorite ON diary_entries(is_favorite)');
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS diary_entries');
    }
}
