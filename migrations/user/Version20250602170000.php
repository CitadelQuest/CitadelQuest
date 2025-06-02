<?php

class UserMigration_20250602170000
{
    public function up(\PDO $db): void
    {
        // Create ai_tool table
        $db->exec('CREATE TABLE IF NOT EXISTS ai_tool (
            id VARCHAR(36) PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            parameters TEXT NOT NULL,
            is_active BOOLEAN DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
        
        // Add indexes
        $db->exec('CREATE INDEX IF NOT EXISTS idx_ai_tool_name ON ai_tool(name)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_ai_tool_is_active ON ai_tool(is_active)');
    }

    public function down(\PDO $db): void
    {
        // Drop ai_tool table
        $db->exec('DROP TABLE IF EXISTS ai_tool');
    }
}
