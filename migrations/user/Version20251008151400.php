<?php

/**
 * CQ Group Chat Migration
 * Adds support for group chat functionality
 * 
 * Changes:
 * 1. Add is_group_chat and group_host_contact_id to cq_chat table
 * 2. Create cq_chat_group_members table
 * 3. Create cq_chat_msg_delivery table
 */
class UserMigration_20251008151400
{
    public function up(\PDO $db): void
    {
        // 1. Add group chat columns to cq_chat table
        $db->exec('ALTER TABLE cq_chat ADD COLUMN is_group_chat BOOLEAN DEFAULT 0');
        $db->exec('ALTER TABLE cq_chat ADD COLUMN group_host_contact_id VARCHAR(36) NULL');
        
        // Create index for group chat queries
        $db->exec('CREATE INDEX IF NOT EXISTS idx_cq_chat_is_group_chat ON cq_chat (is_group_chat)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_cq_chat_group_host_contact_id ON cq_chat (group_host_contact_id)');
        
        // 2. Create cq_chat_group_members table
        $db->exec('CREATE TABLE IF NOT EXISTS cq_chat_group_members (
            id VARCHAR(36) PRIMARY KEY,
            cq_chat_id VARCHAR(36) NOT NULL,
            cq_contact_id VARCHAR(36) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT "member",
            joined_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            FOREIGN KEY (cq_chat_id) REFERENCES cq_chat(id) ON DELETE CASCADE,
            FOREIGN KEY (cq_contact_id) REFERENCES cq_contact(id) ON DELETE CASCADE
        )');
        
        // Create indexes for cq_chat_group_members
        $db->exec('CREATE INDEX IF NOT EXISTS idx_cq_chat_group_members_cq_chat_id ON cq_chat_group_members (cq_chat_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_cq_chat_group_members_cq_contact_id ON cq_chat_group_members (cq_contact_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_cq_chat_group_members_role ON cq_chat_group_members (role)');
        
        // 3. Create cq_chat_msg_delivery table
        $db->exec('CREATE TABLE IF NOT EXISTS cq_chat_msg_delivery (
            id VARCHAR(36) PRIMARY KEY,
            cq_chat_msg_id VARCHAR(36) NOT NULL,
            cq_contact_id VARCHAR(36) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT "SENT",
            delivered_at DATETIME NULL,
            seen_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            FOREIGN KEY (cq_chat_msg_id) REFERENCES cq_chat_msg(id) ON DELETE CASCADE,
            FOREIGN KEY (cq_contact_id) REFERENCES cq_contact(id) ON DELETE CASCADE
        )');
        
        // Create indexes for cq_chat_msg_delivery
        $db->exec('CREATE INDEX IF NOT EXISTS idx_cq_chat_msg_delivery_cq_chat_msg_id ON cq_chat_msg_delivery (cq_chat_msg_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_cq_chat_msg_delivery_cq_contact_id ON cq_chat_msg_delivery (cq_contact_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_cq_chat_msg_delivery_status ON cq_chat_msg_delivery (status)');
    }
    
    public function down(\PDO $db): void
    {
        // Drop new tables
        $db->exec('DROP TABLE IF EXISTS cq_chat_msg_delivery');
        $db->exec('DROP TABLE IF EXISTS cq_chat_group_members');
        
        // Remove indexes from cq_chat
        $db->exec('DROP INDEX IF EXISTS idx_cq_chat_is_group_chat');
        $db->exec('DROP INDEX IF EXISTS idx_cq_chat_group_host_contact_id');
        
        // Remove columns from cq_chat (SQLite doesn't support DROP COLUMN easily)
        // For SQLite, we would need to recreate the table without these columns
        // For now, we'll leave them as they don't break existing functionality
    }
}
