<?php

class UserMigration_20250403151428
{
    public function up(PDO $db): void
    {
        // Check if column exists before adding it
        $result = $db->query("PRAGMA table_info(spirits)");
        $columnExists = false;
        
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            if ($row['name'] === 'system_prompt') {
                $columnExists = true;
                break;
            }
        }
        
        if (!$columnExists) {            
            $db->exec('ALTER TABLE spirits ADD COLUMN system_prompt TEXT');
        }

        // Check if column exists before adding it
        $result = $db->query("PRAGMA table_info(spirits)");
        $columnExists = false;
        
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            if ($row['name'] === 'ai_model') {
                $columnExists = true;
                break;
            }
        }
        
        if (!$columnExists) {
            $db->exec('ALTER TABLE spirits ADD COLUMN ai_model VARCHAR(50) DEFAULT ""');
        }
    }
    
    public function down(PDO $db): void
    {
        // Remove the columns (if possible in SQLite)
        // Note: SQLite has limited ALTER TABLE support
        // In a real migration, you might need to recreate the table
        // For now, we'll just attempt to drop the columns
        try {
            $db->exec('ALTER TABLE spirits DROP COLUMN system_prompt');
            $db->exec('ALTER TABLE spirits DROP COLUMN ai_model');
        } catch (\Exception $e) {
            // SQLite might not support dropping columns
            // We'll just log the error and continue
            error_log('Could not drop columns: ' . $e->getMessage());
        }
    }
}
