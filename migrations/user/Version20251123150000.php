<?php

/**
 * User Migration: Add full_config column to ai_service_model
 * 
 * This migration adds a JSON column to store the complete configuration
 * of the AI model retrieved from the gateway.
 * 
 * Date: 2025-11-23
 */
class UserMigration_20251123150000
{
    /**
     * Apply migration
     */
    public function up(\PDO $db): void
    {
        // Check if column already exists to avoid errors
        $result = $db->query("PRAGMA table_info(ai_service_model)");
        $columns = $result->fetchAll(\PDO::FETCH_ASSOC);
        $hasColumn = false;
        foreach ($columns as $column) {
            if ($column['name'] === 'full_config') {
                $hasColumn = true;
                break;
            }
        }
        
        if (!$hasColumn) {
            $db->exec('ALTER TABLE ai_service_model ADD COLUMN full_config TEXT DEFAULT NULL');
        }
    }
    
    /**
     * Rollback migration
     */
    public function down(\PDO $db): void
    {
        // SQLite does not support DROP COLUMN in older versions, 
        // but we generally don't need to implement complex down migrations 
        // for adding columns in this project context.
        // If needed, we would have to recreate the table.
    }
}
