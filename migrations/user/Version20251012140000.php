<?php

/**
 * User Migration: Create spirit_conversation_message table
 * 
 * This migration creates the new message table for async Spirit conversations.
 * Each message is a separate record linked via parent_message_id.
 * 
 * Date: 2025-10-12
 * Part of: Async Spirit Conversation Architecture
 */
class UserMigration_20251012140000
{
    /**
     * Apply migration
     */
    public function up(\PDO $db): void
    {
        // Create spirit_conversation_message table
        $db->exec('
            CREATE TABLE IF NOT EXISTS spirit_conversation_message (
                id TEXT PRIMARY KEY,
                conversation_id TEXT NOT NULL,
                role TEXT NOT NULL,
                type TEXT NOT NULL,
                content TEXT NOT NULL,
                ai_service_request_id TEXT,
                ai_service_response_id TEXT,
                parent_message_id TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (conversation_id) REFERENCES spirit_conversation(id) ON DELETE CASCADE,
                FOREIGN KEY (parent_message_id) REFERENCES spirit_conversation_message(id) ON DELETE SET NULL,
                FOREIGN KEY (ai_service_request_id) REFERENCES ai_service_request(id) ON DELETE SET NULL,
                FOREIGN KEY (ai_service_response_id) REFERENCES ai_service_response(id) ON DELETE SET NULL
            )
        ');
        
        // Create index on conversation_id for fast lookups
        $db->exec('
            CREATE INDEX IF NOT EXISTS idx_spirit_conversation_message_conversation_id 
            ON spirit_conversation_message(conversation_id)
        ');
        
        // Create index on parent_message_id for chain traversal
        $db->exec('
            CREATE INDEX IF NOT EXISTS idx_spirit_conversation_message_parent_id 
            ON spirit_conversation_message(parent_message_id)
        ');
        
        // Create index on created_at for ordering
        $db->exec('
            CREATE INDEX IF NOT EXISTS idx_spirit_conversation_message_created_at 
            ON spirit_conversation_message(created_at)
        ');
        
        // Create composite index for conversation + created_at (most common query)
        $db->exec('
            CREATE INDEX IF NOT EXISTS idx_spirit_conversation_message_conv_created 
            ON spirit_conversation_message(conversation_id, created_at)
        ');
    }
    
    /**
     * Rollback migration
     */
    public function down(\PDO $db): void
    {
        // Drop indexes first
        $db->exec('DROP INDEX IF EXISTS idx_spirit_conversation_message_conv_created');
        $db->exec('DROP INDEX IF EXISTS idx_spirit_conversation_message_created_at');
        $db->exec('DROP INDEX IF EXISTS idx_spirit_conversation_message_parent_id');
        $db->exec('DROP INDEX IF EXISTS idx_spirit_conversation_message_conversation_id');
        
        // Drop table
        $db->exec('DROP TABLE IF EXISTS spirit_conversation_message');
    }
}
