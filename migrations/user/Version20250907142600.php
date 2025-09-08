<?php

class UserMigration_20250907142600
{
    public function up(\PDO $db): void
    {
        // Create project table
        $db->exec('CREATE TABLE IF NOT EXISTS project (
            id VARCHAR(36) PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            slug VARCHAR(36) NOT NULL,
            description TEXT,
            is_public BOOLEAN DEFAULT 0,
            is_active BOOLEAN DEFAULT 1,
            src_url VARCHAR(1000),
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )');
        
        // Create index for project slug
        $db->exec('CREATE INDEX IF NOT EXISTS idx_project_id ON project (id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_project_slug ON project (slug)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_project_is_public ON project (is_public)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_project_is_active ON project (is_active)');
        
        // Create project_spirit table
        $db->exec('CREATE TABLE IF NOT EXISTS project_spirit (
            id VARCHAR(36) PRIMARY KEY,
            project_id VARCHAR(36) NOT NULL,
            spirit_id VARCHAR(36) NOT NULL,
            FOREIGN KEY (project_id) REFERENCES project(id) ON DELETE CASCADE,
            UNIQUE (project_id, spirit_id)
        )');
        
        // Create index for project_spirit
        $db->exec('CREATE INDEX IF NOT EXISTS idx_project_spirit_project_id ON project_spirit (project_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_project_spirit_spirit_id ON project_spirit (spirit_id)');
        
        // Create project_spirit_conversation table
        $db->exec('CREATE TABLE IF NOT EXISTS project_spirit_conversation (
            id VARCHAR(36) PRIMARY KEY,
            project_id VARCHAR(36) NOT NULL,
            spirit_conversation_id VARCHAR(36) NOT NULL,
            category VARCHAR(255),
            system_prompt_instructions TEXT,
            task_list TEXT,
            task_list_status VARCHAR(255),
            task_list_result TEXT,
            frontend_data TEXT,
            autorun BOOLEAN DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            FOREIGN KEY (project_id) REFERENCES project(id) ON DELETE CASCADE
        )');
        
        // Create index for project_spirit_conversation
        $db->exec('CREATE INDEX IF NOT EXISTS idx_project_spirit_conversation_project_id ON project_spirit_conversation (project_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_project_spirit_conversation_spirit_conversation_id ON project_spirit_conversation (spirit_conversation_id)');
        
        // Create project_tool table
        $db->exec('CREATE TABLE IF NOT EXISTS project_tool (
            id VARCHAR(36) PRIMARY KEY,
            project_id VARCHAR(36) NOT NULL,
            tool_id VARCHAR(36) NOT NULL,
            FOREIGN KEY (project_id) REFERENCES project(id) ON DELETE CASCADE,
            UNIQUE (project_id, tool_id)
        )');
        
        // Create index for project_tool
        $db->exec('CREATE INDEX IF NOT EXISTS idx_project_tool_project_id ON project_tool (project_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_project_tool_tool_id ON project_tool (tool_id)');
        
        // Create cq_contact table
        $db->exec('CREATE TABLE IF NOT EXISTS cq_contact (
            id VARCHAR(36) PRIMARY KEY,
            cq_contact_url VARCHAR(255) NOT NULL,
            cq_contact_domain VARCHAR(255) NOT NULL,
            cq_contact_username VARCHAR(255) NOT NULL,
            cq_contact_id VARCHAR(255),
            cq_contact_api_key VARCHAR(255),
            friend_request_status VARCHAR(36),
            description TEXT,
            profile_photo_project_file_id VARCHAR(36),
            is_active BOOLEAN DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE (cq_contact_url)
        )');
        
        // Create index for cq_contact
        $db->exec('CREATE INDEX IF NOT EXISTS idx_cq_contact_id ON cq_contact (id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_cq_contact_domain ON cq_contact (cq_contact_domain)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_cq_contact_username ON cq_contact (cq_contact_username)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_cq_contact_is_active ON cq_contact (is_active)');
        
        // Create project_cq_contact table
        $db->exec('CREATE TABLE IF NOT EXISTS project_cq_contact (
            id VARCHAR(36) PRIMARY KEY,
            project_id VARCHAR(36) NOT NULL,
            cq_contact_id VARCHAR(36) NOT NULL,
            FOREIGN KEY (project_id) REFERENCES project(id) ON DELETE CASCADE,
            FOREIGN KEY (cq_contact_id) REFERENCES cq_contact(id) ON DELETE CASCADE,
            UNIQUE (project_id, cq_contact_id)
        )');
        
        // Create index for project_cq_contact
        $db->exec('CREATE INDEX IF NOT EXISTS idx_project_cq_contact_project_id ON project_cq_contact (project_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_project_cq_contact_cq_contact_id ON project_cq_contact (cq_contact_id)');
    }
    
    public function down(\PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS project_cq_contact');
        $db->exec('DROP TABLE IF EXISTS project_tool');
        $db->exec('DROP TABLE IF EXISTS project_spirit_conversation');
        $db->exec('DROP TABLE IF EXISTS project_spirit');
        $db->exec('DROP TABLE IF EXISTS cq_contact');
        $db->exec('DROP TABLE IF EXISTS project');
    }
}
