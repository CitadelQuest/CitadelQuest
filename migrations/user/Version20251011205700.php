<?php

class UserMigration_20251011205700
{
    public function up(PDO $db): void
    {
        // Add missing indexes for ai_service_request table
        $db->exec('CREATE INDEX IF NOT EXISTS idx_ai_service_request_model_id ON ai_service_request(ai_service_model_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_ai_service_request_created_at ON ai_service_request(created_at)');
        
        // Add missing indexes for ai_service_response table
        $db->exec('CREATE INDEX IF NOT EXISTS idx_ai_service_response_request_id ON ai_service_response(ai_service_request_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_ai_service_response_created_at ON ai_service_response(created_at)');
    }
    
    public function down(PDO $db): void
    {
        // Drop indexes
        $db->exec('DROP INDEX IF EXISTS idx_ai_service_response_created_at');
        $db->exec('DROP INDEX IF EXISTS idx_ai_service_response_request_id');
        $db->exec('DROP INDEX IF EXISTS idx_ai_service_request_created_at');
        $db->exec('DROP INDEX IF EXISTS idx_ai_service_request_model_id');
    }
}
