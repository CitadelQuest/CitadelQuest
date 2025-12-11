<?php

declare(strict_types=1);

namespace DoctrineMigrations;

/**
 * Add backup_filename and transferred_bytes columns to migration_request table
 * for pre-staged backup support and progress tracking
 * 
 * Date: 2025-12-11
 */
final class Version20251211140000
{
    public function getDescription(): string
    {
        return 'Add backup_filename and transferred_bytes columns to migration_request table';
    }

    /**
     * Run migration - works with both Doctrine Schema and PDO
     */
    public function up($connection): void
    {
        if ($connection instanceof \PDO) {
            $this->upPdo($connection);
        }
    }

    private function upPdo(\PDO $pdo): void
    {
        // Check if columns exist first
        $result = $pdo->query("PRAGMA table_info(migration_request)");
        $columns = $result->fetchAll(\PDO::FETCH_ASSOC);
        $columnNames = array_column($columns, 'name');

        // Add backup_filename column for pre-staged backup support
        if (!in_array('backup_filename', $columnNames)) {
            $pdo->exec('ALTER TABLE migration_request ADD COLUMN backup_filename VARCHAR(255) DEFAULT NULL');
        }
        
        // Add transferred_bytes column for progress tracking
        if (!in_array('transferred_bytes', $columnNames)) {
            $pdo->exec('ALTER TABLE migration_request ADD COLUMN transferred_bytes BIGINT DEFAULT NULL');
        }
    }

    public function down($connection): void
    {
        // SQLite doesn't support DROP COLUMN directly
    }
}
