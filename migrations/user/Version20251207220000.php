<?php

/**
 * User Migration: Add contact_update_queue table
 * 
 * This migration adds a table to track failed contact domain updates
 * during user migration, enabling retry functionality.
 * 
 * Date: 2025-12-07
 */
class UserMigration_20251207220000
{
    /**
     * Apply migration
     */
    public function up(\PDO $db): void
    {
        // Create contact_update_queue table
        $db->exec('
            CREATE TABLE IF NOT EXISTS contact_update_queue (
                id VARCHAR(36) PRIMARY KEY,
                contact_id VARCHAR(36) NOT NULL,
                contact_domain VARCHAR(255) NOT NULL,
                old_domain VARCHAR(255) NOT NULL,
                new_domain VARCHAR(255) NOT NULL,
                attempts INTEGER DEFAULT 0,
                max_attempts INTEGER DEFAULT 5,
                last_attempt_at DATETIME DEFAULT NULL,
                next_attempt_at DATETIME DEFAULT NULL,
                status VARCHAR(50) DEFAULT "pending",
                error_message TEXT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');

        // Create indexes
        $db->exec('CREATE INDEX IF NOT EXISTS idx_contact_update_queue_status ON contact_update_queue(status)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_contact_update_queue_next_attempt ON contact_update_queue(next_attempt_at)');
    }
    
    /**
     * Rollback migration
     */
    public function down(\PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS contact_update_queue');
    }
}
