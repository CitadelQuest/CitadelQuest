<?php

class UserMigration_20250428023145
{
    public function up(PDO $db): void
    {
        // Check if column exists before adding it
        $result = $db->query("PRAGMA table_info(ai_service_response)");
        $columnExists = false;
        
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            if ($row['name'] === 'full_response') {
                $columnExists = true;
                break;
            }
        }
        
        if (!$columnExists) {
            $db->exec('ALTER TABLE ai_service_response ADD COLUMN full_response TEXT');
            
            // Initialize existing entries with empty full_response array
            $db->exec("UPDATE ai_service_response SET full_response = '[]' WHERE full_response IS NULL");
        }
    }
    
    public function down(PDO $db): void
    {
        $db->exec('ALTER TABLE ai_service_response DROP COLUMN full_response');
    }
}
