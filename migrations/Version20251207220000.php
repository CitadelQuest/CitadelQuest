<?php

declare(strict_types=1);

namespace DoctrineMigrations;

/**
 * Migration for User Migration feature
 * 
 * Creates migration_request table and adds migration columns to user table.
 * Compatible with both Doctrine and standalone updater.
 * 
 * Date: 2025-12-07
 */
final class Version20251207220000
{
    public function getDescription(): string
    {
        return 'Add migration_request table and migration columns to user table for cross-instance user migration';
    }

    /**
     * Run migration - works with both Doctrine Schema and PDO
     */
    public function up($connection): void
    {
        if ($connection instanceof \PDO) {
            $this->upPdo($connection);
        } else {
            $this->upDoctrine($connection);
        }
    }

    private function upPdo(\PDO $pdo): void
    {
        // Create migration_request table
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS migration_request (
                id BLOB NOT NULL PRIMARY KEY,
                user_id BLOB NOT NULL,
                username VARCHAR(180) NOT NULL,
                email VARCHAR(180) DEFAULT NULL,
                source_domain VARCHAR(255) NOT NULL,
                target_domain VARCHAR(255) NOT NULL,
                backup_size BIGINT DEFAULT NULL,
                migration_token VARCHAR(255) DEFAULT NULL,
                status VARCHAR(50) NOT NULL DEFAULT "pending",
                direction VARCHAR(20) NOT NULL,
                admin_id BLOB DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                accepted_at DATETIME DEFAULT NULL,
                completed_at DATETIME DEFAULT NULL,
                token_expires_at DATETIME DEFAULT NULL
            )
        ');

        // Create indexes
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_migration_request_user_id ON migration_request(user_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_migration_request_status ON migration_request(status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_migration_request_direction ON migration_request(direction)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_migration_request_token ON migration_request(migration_token)');

        // Add migration columns to user table
        // Check if columns exist first
        $result = $pdo->query("PRAGMA table_info(user)");
        $columns = $result->fetchAll(\PDO::FETCH_ASSOC);
        $columnNames = array_column($columns, 'name');

        if (!in_array('migrated_to', $columnNames)) {
            $pdo->exec('ALTER TABLE user ADD COLUMN migrated_to VARCHAR(255) DEFAULT NULL');
        }
        if (!in_array('migrated_at', $columnNames)) {
            $pdo->exec('ALTER TABLE user ADD COLUMN migrated_at DATETIME DEFAULT NULL');
        }
        if (!in_array('migration_status', $columnNames)) {
            $pdo->exec('ALTER TABLE user ADD COLUMN migration_status VARCHAR(50) DEFAULT NULL');
        }
    }

    private function upDoctrine($schema): void
    {
        if (method_exists($this, 'addSql')) {
            // Create migration_request table
            $this->addSql('
                CREATE TABLE IF NOT EXISTS migration_request (
                    id BLOB NOT NULL PRIMARY KEY,
                    user_id BLOB NOT NULL,
                    username VARCHAR(180) NOT NULL,
                    email VARCHAR(180) DEFAULT NULL,
                    source_domain VARCHAR(255) NOT NULL,
                    target_domain VARCHAR(255) NOT NULL,
                    backup_size BIGINT DEFAULT NULL,
                    migration_token VARCHAR(255) DEFAULT NULL,
                    status VARCHAR(50) NOT NULL DEFAULT "pending",
                    direction VARCHAR(20) NOT NULL,
                    admin_id BLOB DEFAULT NULL,
                    error_message TEXT DEFAULT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    accepted_at DATETIME DEFAULT NULL,
                    completed_at DATETIME DEFAULT NULL,
                    token_expires_at DATETIME DEFAULT NULL
                )
            ');

            $this->addSql('CREATE INDEX IF NOT EXISTS idx_migration_request_user_id ON migration_request(user_id)');
            $this->addSql('CREATE INDEX IF NOT EXISTS idx_migration_request_status ON migration_request(status)');
            $this->addSql('CREATE INDEX IF NOT EXISTS idx_migration_request_direction ON migration_request(direction)');
            $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS idx_migration_request_token ON migration_request(migration_token)');

            $this->addSql('ALTER TABLE user ADD COLUMN migrated_to VARCHAR(255) DEFAULT NULL');
            $this->addSql('ALTER TABLE user ADD COLUMN migrated_at DATETIME DEFAULT NULL');
            $this->addSql('ALTER TABLE user ADD COLUMN migration_status VARCHAR(50) DEFAULT NULL');
        }
    }

    public function down($connection): void
    {
        if ($connection instanceof \PDO) {
            $connection->exec('DROP TABLE IF EXISTS migration_request');
            // Note: SQLite doesn't support DROP COLUMN easily, would need table recreation
        } elseif (method_exists($this, 'addSql')) {
            $this->addSql('DROP TABLE IF EXISTS migration_request');
        }
    }
}
