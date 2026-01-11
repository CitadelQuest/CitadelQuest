<?php

/**
 * Migration: Create SpiritSettings table
 * 
 * This migration creates the spirit_settings table to store Spirit-specific
 * settings and configurations. This follows the same pattern as the settings
 * table but is scoped to individual Spirits.
 */
class UserMigration_20260111194800
{
    public function getDescription(): string
    {
        return 'Create spirit_settings table for Spirit-specific settings';
    }

    public function up(\PDO $pdo): void
    {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS spirit_settings (
                id TEXT PRIMARY KEY,
                spirit_id TEXT NOT NULL,
                key TEXT NOT NULL,
                value TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        ');

        // Create indexes for performance
        $pdo->exec('
            CREATE INDEX IF NOT EXISTS idx_spirit_settings_spirit_id 
            ON spirit_settings(spirit_id)
        ');

        $pdo->exec('
            CREATE INDEX IF NOT EXISTS idx_spirit_settings_key 
            ON spirit_settings(key)
        ');

        $pdo->exec('
            CREATE UNIQUE INDEX IF NOT EXISTS idx_spirit_settings_spirit_key 
            ON spirit_settings(spirit_id, key)
        ');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP INDEX IF EXISTS idx_spirit_settings_spirit_key');
        $pdo->exec('DROP INDEX IF EXISTS idx_spirit_settings_key');
        $pdo->exec('DROP INDEX IF EXISTS idx_spirit_settings_spirit_id');
        $pdo->exec('DROP TABLE IF EXISTS spirit_settings');
    }
}
