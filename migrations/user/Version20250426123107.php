<?php

class UserMigration_20250426123107
{
    public function up(PDO $db): void
    {
        // Check if column exists before adding it
        $result = $db->query("PRAGMA table_info(ai_service_request)");
        $columnExists = false;
        
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            if ($row['name'] === 'tools') {
                $columnExists = true;
                break;
            }
        }
        
        if (!$columnExists) {
            $db->exec('ALTER TABLE ai_service_request ADD COLUMN tools TEXT');
            
            // Initialize existing entries with empty tools array
            $db->exec("UPDATE ai_service_request SET tools = '[]' WHERE tools IS NULL");
        }
    }
    
    public function down(PDO $db): void
    {
        $db->exec('ALTER TABLE ai_service_request DROP COLUMN tools');
    }
}
