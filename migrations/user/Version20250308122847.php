<?php

class UserMigration_20250308122847
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
                content_formatted TEXT,
                consciousness_level INTEGER DEFAULT NULL
            )
        ');
        
        $db->exec('INSERT INTO diary_entries_new SELECT id, title, content, created_at, updated_at, is_encrypted, is_favorite, tags, mood, content_formatted, NULL FROM diary_entries');
        $db->exec('DROP TABLE diary_entries');
        $db->exec('ALTER TABLE diary_entries_new RENAME TO diary_entries');
        
        // Recreate indexes
        $db->exec('CREATE INDEX idx_diary_entries_created_at ON diary_entries(created_at)');
        $db->exec('CREATE INDEX idx_diary_entries_is_favorite ON diary_entries(is_favorite)');
        
        // Add new index for consciousness_level
        $db->exec('CREATE INDEX idx_diary_entries_consciousness_level ON diary_entries(consciousness_level)');
    }
    
    public function down(PDO $db): void
    {
        // Revert by removing the consciousness_level column
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
        
        $db->exec('INSERT INTO diary_entries_new SELECT id, title, content, created_at, updated_at, is_encrypted, is_favorite, tags, mood, content_formatted FROM diary_entries');
        $db->exec('DROP TABLE diary_entries');
        $db->exec('ALTER TABLE diary_entries_new RENAME TO diary_entries');
        
        // Recreate original indexes
        $db->exec('CREATE INDEX idx_diary_entries_created_at ON diary_entries(created_at)');
        $db->exec('CREATE INDEX idx_diary_entries_is_favorite ON diary_entries(is_favorite)');
    }
}
