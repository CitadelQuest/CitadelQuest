<?php

class UserMigration_20250611171000
{
    public function up(\PDO $db): void
    {
        // Create project_file table
        $db->exec('CREATE TABLE IF NOT EXISTS project_file (
            id VARCHAR(36) PRIMARY KEY,
            project_id VARCHAR(36) NOT NULL,
            path VARCHAR(255) NOT NULL,
            name VARCHAR(255) NOT NULL,
            type VARCHAR(50) NOT NULL,
            mime_type VARCHAR(100),
            size INTEGER,
            is_directory BOOLEAN DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE (project_id, path, name)
        )');
        
        // Create index for faster lookups
        $db->exec('CREATE INDEX IF NOT EXISTS idx_project_file_project_id ON project_file (project_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_project_file_path ON project_file (path)');
        
        // Create project_file_version table for versioning
        $db->exec('CREATE TABLE IF NOT EXISTS project_file_version (
            id VARCHAR(36) PRIMARY KEY,
            file_id VARCHAR(36) NOT NULL,
            version INTEGER NOT NULL,
            size INTEGER,
            hash VARCHAR(64),
            created_at DATETIME NOT NULL,
            FOREIGN KEY (file_id) REFERENCES project_file(id) ON DELETE CASCADE
        )');
        
        // Create index for faster lookups
        $db->exec('CREATE INDEX IF NOT EXISTS idx_project_file_version_file_id ON project_file_version (file_id)');
    }
    
    public function down(\PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS project_file_version');
        $db->exec('DROP TABLE IF EXISTS project_file');
    }
}
