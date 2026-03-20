<?php

/**
 * Add scope column to cq_federation_feed table.
 * Stores the remote feed's scope (0=public, 1=CQ Contact) for proper UI display.
 */
class UserMigration_20260320000000
{
    public function up(PDO $db): void
    {
        // Add scope column (default 0 = public for backward compatibility)
        $db->exec('ALTER TABLE cq_federation_feed ADD COLUMN scope INTEGER NOT NULL DEFAULT 0');
    }

    public function down(PDO $db): void
    {
        // SQLite doesn't support DROP COLUMN before 3.35.0; recreate table if needed
    }
}
