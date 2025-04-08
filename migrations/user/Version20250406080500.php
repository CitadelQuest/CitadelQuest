<?php

class UserMigration_20250406080500
{
    public function up(\PDO $db): void
    {
        // Create ai_gateway table
        $db->exec('CREATE TABLE IF NOT EXISTS ai_gateway (
            id VARCHAR(36) PRIMARY KEY,
            name VARCHAR(50) NOT NULL,
            api_key TEXT NOT NULL,
            api_endpoint_url TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
        
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
        
        // Create ai_service_model table
        $db->exec('CREATE TABLE IF NOT EXISTS ai_service_model (
            id VARCHAR(36) PRIMARY KEY,
            ai_gateway_id VARCHAR(36) NOT NULL,
            virtual_key TEXT,
            model_name VARCHAR(255) NOT NULL,
            model_slug VARCHAR(255) NOT NULL,
            context_window INTEGER,
            max_input VARCHAR(50),
            max_input_image_size VARCHAR(50),
            max_output INTEGER,
            ppm_input REAL,
            ppm_output REAL,
            is_active BOOLEAN DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (ai_gateway_id) REFERENCES ai_gateway(id)
        )');
        
        // Create ai_service_request table
        $db->exec('CREATE TABLE IF NOT EXISTS ai_service_request (
            id VARCHAR(36) PRIMARY KEY,
            ai_service_model_id VARCHAR(36) NOT NULL,
            messages TEXT NOT NULL,
            max_tokens INTEGER,
            temperature REAL,
            stop_sequence TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (ai_service_model_id) REFERENCES ai_service_model(id)
        )');
        
        // Create ai_service_response table
        $db->exec('CREATE TABLE IF NOT EXISTS ai_service_response (
            id VARCHAR(36) PRIMARY KEY,
            ai_service_request_id VARCHAR(36) NOT NULL,
            message TEXT NOT NULL,
            finish_reason VARCHAR(50),
            input_tokens INTEGER,
            output_tokens INTEGER,
            total_tokens INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (ai_service_request_id) REFERENCES ai_service_request(id)
        )');
        
        // Create ai_service_use_log table
        $db->exec('CREATE TABLE IF NOT EXISTS ai_service_use_log (
            id VARCHAR(36) PRIMARY KEY,
            ai_gateway_id VARCHAR(36) NOT NULL,
            ai_service_model_id VARCHAR(36) NOT NULL,
            ai_service_request_id VARCHAR(36) NOT NULL,
            ai_service_response_id VARCHAR(36) NOT NULL,
            purpose VARCHAR(255),
            input_tokens INTEGER,
            output_tokens INTEGER,
            total_tokens INTEGER,
            input_price REAL,
            output_price REAL,
            total_price REAL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (ai_gateway_id) REFERENCES ai_gateway(id),
            FOREIGN KEY (ai_service_model_id) REFERENCES ai_service_model(id),
            FOREIGN KEY (ai_service_request_id) REFERENCES ai_service_request(id),
            FOREIGN KEY (ai_service_response_id) REFERENCES ai_service_response(id)
        )');
    }
    
    public function down(\PDO $db): void
    {
        // Drop tables in reverse order to respect foreign keys
        $db->exec('DROP TABLE IF EXISTS ai_service_use_log');
        $db->exec('DROP TABLE IF EXISTS ai_service_response');
        $db->exec('DROP TABLE IF EXISTS ai_service_request');
        $db->exec('DROP TABLE IF EXISTS ai_service_model');
        $db->exec('DROP TABLE IF EXISTS ai_user_settings');
        $db->exec('DROP TABLE IF EXISTS ai_gateway');
    }
}
