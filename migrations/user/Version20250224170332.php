<?php

class UserMigration_20250224170332
{
    public function up(PDO $db): void
    {
        // SQLite doesn't support ALTER COLUMN, so we need to:
        // 1. Create a new table with the desired schema
        // 2. Copy data from old table
        // 3. Drop old table
        // 4. Rename new table
        
        $db->exec('
            CREATE TABLE diary_entries_new (
                id VARCHAR(36) PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                content TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                is_encrypted BOOLEAN DEFAULT 0,
                is_favorite BOOLEAN DEFAULT 0,
                tags TEXT DEFAULT NULL,
                mood VARCHAR(50) DEFAULT NULL,
                content_formatted TEXT
            )
        ');
        
        $db->exec('INSERT INTO diary_entries_new SELECT * FROM diary_entries');
        $db->exec('DROP TABLE diary_entries');
        $db->exec('ALTER TABLE diary_entries_new RENAME TO diary_entries');
        
        // Recreate indexes
        $db->exec('CREATE INDEX idx_diary_entries_created_at ON diary_entries(created_at)');
        $db->exec('CREATE INDEX idx_diary_entries_is_favorite ON diary_entries(is_favorite)');
    }
    
    public function down(PDO $db): void
    {
        // Same process but reverting the default back to 1
        $db->exec('
            CREATE TABLE diary_entries_new (
                id VARCHAR(36) PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                content TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                is_encrypted BOOLEAN DEFAULT 1,
                is_favorite BOOLEAN DEFAULT 0,
                tags TEXT DEFAULT NULL,
                mood VARCHAR(50) DEFAULT NULL,
                content_formatted TEXT
            )
        ');
        
        $db->exec('INSERT INTO diary_entries_new SELECT * FROM diary_entries');
        $db->exec('DROP TABLE diary_entries');
        $db->exec('ALTER TABLE diary_entries_new RENAME TO diary_entries');
        
        // Recreate indexes
        $db->exec('CREATE INDEX idx_diary_entries_created_at ON diary_entries(created_at)');
        $db->exec('CREATE INDEX idx_diary_entries_is_favorite ON diary_entries(is_favorite)');
    }
}
