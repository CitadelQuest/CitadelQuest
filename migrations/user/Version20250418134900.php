<?php

class UserMigration_20250418134900
{
    public function up(PDO $db): void
    {
        // Create settings table
        $db->exec('CREATE TABLE IF NOT EXISTS settings (
            id VARCHAR(36) PRIMARY KEY,
            `key` VARCHAR(100) NOT NULL,
            value TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
        
        // Create unique index on key to ensure we don't have duplicate keys
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_settings_key ON settings(`key`)');
    }
    
    public function down(PDO $db): void
    {
        // Drop index
        $db->exec('DROP INDEX IF EXISTS idx_settings_key');
        
        // Drop table
        $db->exec('DROP TABLE IF EXISTS settings');
    }
}
