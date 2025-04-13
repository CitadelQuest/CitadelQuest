<?php

class UserMigration_20250413115500
{
    public function up(PDO $db): void
    {
        // Create spirit_conversation table
        $db->exec('CREATE TABLE IF NOT EXISTS spirit_conversation (
            id VARCHAR(36) PRIMARY KEY,
            spirit_id VARCHAR(36) NOT NULL,
            title VARCHAR(255) NOT NULL,
            messages TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_interaction DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (spirit_id) REFERENCES spirits(id)
        )');
        
        // Create spirit_conversation_request table
        $db->exec('CREATE TABLE IF NOT EXISTS spirit_conversation_request (
            id VARCHAR(36) PRIMARY KEY,
            spirit_conversation_id VARCHAR(36) NOT NULL,
            ai_service_request_id VARCHAR(36) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (spirit_conversation_id) REFERENCES spirit_conversation(id),
            FOREIGN KEY (ai_service_request_id) REFERENCES ai_service_request(id)
        )');
        
        // Create indexes for better performance
        $db->exec('CREATE INDEX IF NOT EXISTS idx_spirit_conversation_spirit_id ON spirit_conversation(spirit_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_spirit_conversation_request_conversation_id ON spirit_conversation_request(spirit_conversation_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_spirit_conversation_request_ai_request_id ON spirit_conversation_request(ai_service_request_id)');
    }
    
    public function down(PDO $db): void
    {
        // Drop indexes
        $db->exec('DROP INDEX IF EXISTS idx_spirit_conversation_request_ai_request_id');
        $db->exec('DROP INDEX IF EXISTS idx_spirit_conversation_request_conversation_id');
        $db->exec('DROP INDEX IF EXISTS idx_spirit_conversation_spirit_id');
        
        // Drop tables
        $db->exec('DROP TABLE IF EXISTS spirit_conversation_request');
        $db->exec('DROP TABLE IF EXISTS spirit_conversation');
    }
}
