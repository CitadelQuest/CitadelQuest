<?php

class UserMigration_20250502125022
{
    public function up(\PDO $db): void
    {
        // Drop ai_user_settings table
        $db->exec('DROP TABLE IF EXISTS ai_user_settings');
    }

    public function down(\PDO $db): void
    {
        // Create ai_user_settings table
        $db->exec('CREATE TABLE IF NOT EXISTS ai_user_settings (
            id VARCHAR(36) PRIMARY KEY,
            ai_gateway_id VARCHAR(36) NOT NULL,
            primary_ai_service_model_id VARCHAR(36),
            secondary_ai_service_model_id VARCHAR(36),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (ai_gateway_id) REFERENCES ai_gateway(id)
        )');
    }
}
