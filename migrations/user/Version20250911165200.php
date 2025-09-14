<?php

class UserMigration_20250911165200
{
    public function up(\PDO $db): void
    {
        // Create cq_chat table
        $db->exec('CREATE TABLE IF NOT EXISTS cq_chat (
            id VARCHAR(36) PRIMARY KEY,
            cq_contact_id VARCHAR(36),
            title VARCHAR(255) NOT NULL,
            summary TEXT,
            is_star BOOLEAN DEFAULT 0,
            is_pin BOOLEAN DEFAULT 0,
            is_mute BOOLEAN DEFAULT 0,
            is_active BOOLEAN DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            FOREIGN KEY (cq_contact_id) REFERENCES cq_contact(id) ON DELETE SET NULL
        )');
        
        // Create indexes for cq_chat
        $db->exec('CREATE INDEX IF NOT EXISTS idx_cq_chat_id ON cq_chat (id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_cq_chat_cq_contact_id ON cq_chat (cq_contact_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_cq_chat_is_active ON cq_chat (is_active)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_cq_chat_is_star ON cq_chat (is_star)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_cq_chat_is_pin ON cq_chat (is_pin)');
        
        // Create cq_chat_msg table
        $db->exec('CREATE TABLE IF NOT EXISTS cq_chat_msg (
            id VARCHAR(36) PRIMARY KEY,
            cq_chat_id VARCHAR(36) NOT NULL,
            cq_contact_id VARCHAR(36),
            content TEXT,
            attachments TEXT,
            status VARCHAR(36),
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            FOREIGN KEY (cq_chat_id) REFERENCES cq_chat(id) ON DELETE CASCADE,
            FOREIGN KEY (cq_contact_id) REFERENCES cq_contact(id) ON DELETE SET NULL
        )');
        
        // Create indexes for cq_chat_msg
        $db->exec('CREATE INDEX IF NOT EXISTS idx_cq_chat_msg_id ON cq_chat_msg (id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_cq_chat_msg_cq_chat_id ON cq_chat_msg (cq_chat_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_cq_chat_msg_cq_contact_id ON cq_chat_msg (cq_contact_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_cq_chat_msg_status ON cq_chat_msg (status)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_cq_chat_msg_created_at ON cq_chat_msg (created_at)');
    }
    
    public function down(\PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS cq_chat_msg');
        $db->exec('DROP TABLE IF EXISTS cq_chat');
    }
}
