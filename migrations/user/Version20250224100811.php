<?php

class UserMigration_20250224100811
{
    public function up(PDO $db): void
    {
        // Check if column exists before adding it
        $result = $db->query("PRAGMA table_info(diary_entries)");
        $columnExists = false;
        
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            if ($row['name'] === 'content_formatted') {
                $columnExists = true;
                break;
            }
        }
        
        if (!$columnExists) {
            $db->exec('ALTER TABLE diary_entries ADD COLUMN content_formatted TEXT');
            
            // Update existing entries to have the same content in both columns
            $db->exec('UPDATE diary_entries SET content_formatted = content WHERE content_formatted IS NULL');
        }
    }
    
    public function down(PDO $db): void
    {
        $db->exec('ALTER TABLE diary_entries DROP COLUMN content_formatted');
    }
}
