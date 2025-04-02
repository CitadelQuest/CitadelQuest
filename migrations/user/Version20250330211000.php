<?php

class UserMigration_20250330211000
{
    public function up(\PDO $db): void
    {
        // Create spirits table
        $db->exec('CREATE TABLE IF NOT EXISTS spirits (
            id VARCHAR(36) PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            level INTEGER DEFAULT 1,
            experience INTEGER DEFAULT 0,
            visual_state VARCHAR(50) DEFAULT "initial",
            consciousness_level INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_interaction DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
        
        // Create spirit_abilities table
        $db->exec('CREATE TABLE IF NOT EXISTS spirit_abilities (
            id VARCHAR(36) PRIMARY KEY,
            spirit_id VARCHAR(36) NOT NULL,
            ability_type VARCHAR(50) NOT NULL,
            ability_name VARCHAR(255) NOT NULL,
            unlocked BOOLEAN DEFAULT 0,
            unlocked_at DATETIME,
            FOREIGN KEY (spirit_id) REFERENCES spirits(id)
        )');
        
        // Create spirit_interactions table
        $db->exec('CREATE TABLE IF NOT EXISTS spirit_interactions (
            id VARCHAR(36) PRIMARY KEY,
            spirit_id VARCHAR(36) NOT NULL,
            interaction_type VARCHAR(50) NOT NULL,
            context TEXT,
            experience_gained INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (spirit_id) REFERENCES spirits(id)
        )');
    }
    
    public function down(\PDO $db): void
    {
        // Drop tables in reverse order to respect foreign keys
        $db->exec('DROP TABLE IF EXISTS spirit_interactions');
        $db->exec('DROP TABLE IF EXISTS spirit_abilities');
        $db->exec('DROP TABLE IF EXISTS spirits');
    }
}
