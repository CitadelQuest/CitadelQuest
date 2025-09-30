<?php

declare(strict_types=1);

namespace DoctrineMigrations;

/**
 * Migration for adding require_password_change field to user table
 * Compatible with both Doctrine and standalone updater
 */
final class Version20250930223524
{
    public function getDescription(): string
    {
        return 'Add require_password_change field to user table for password reset functionality';
    }

    /**
     * Run migration - works with both Doctrine Schema and PDO
     */
    public function up($connection): void
    {
        // Check if we're using PDO (updater) or Doctrine Schema
        if ($connection instanceof \PDO) {
            // Standalone updater mode
            $this->upPdo($connection);
        } else {
            // Doctrine mode
            $this->upDoctrine($connection);
        }
    }

    private function upPdo(\PDO $pdo): void
    {
        // Add require_password_change column to user table
        $pdo->exec('ALTER TABLE user ADD COLUMN require_password_change BOOLEAN DEFAULT 0 NOT NULL');
    }

    private function upDoctrine($schema): void
    {
        // For Doctrine migrations (dev environment)
        if (method_exists($this, 'addSql')) {
            $this->addSql('ALTER TABLE user ADD COLUMN require_password_change BOOLEAN DEFAULT 0 NOT NULL');
        }
    }

    public function down($connection): void
    {
        if ($connection instanceof \PDO) {
            // Remove the column (SQLite doesn't support DROP COLUMN directly, need to recreate table)
            $pdo->exec('CREATE TEMPORARY TABLE __temp__user AS SELECT id, username, email, roles, password, database_path FROM "user"');
            $pdo->exec('DROP TABLE "user"');
            $pdo->exec('CREATE TABLE "user" (id BLOB NOT NULL, username VARCHAR(180) NOT NULL, email VARCHAR(180) NOT NULL, roles CLOB NOT NULL, password VARCHAR(255) NOT NULL, database_path VARCHAR(255) NOT NULL, PRIMARY KEY(id))');
            $pdo->exec('INSERT INTO "user" (id, username, email, roles, password, database_path) SELECT id, username, email, roles, password, database_path FROM __temp__user');
            $pdo->exec('DROP TABLE __temp__user');
            $pdo->exec('CREATE UNIQUE INDEX UNIQ_8D93D649F85E0677 ON "user" (username)');
            $pdo->exec('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)');
            $pdo->exec('CREATE UNIQUE INDEX UNIQ_8D93D6497929C943 ON "user" (database_path)');
        } elseif (method_exists($this, 'addSql')) {
            $this->addSql('CREATE TEMPORARY TABLE __temp__user AS SELECT id, username, email, roles, password, database_path FROM "user"');
            $this->addSql('DROP TABLE "user"');
            $this->addSql('CREATE TABLE "user" (id BLOB NOT NULL, username VARCHAR(180) NOT NULL, email VARCHAR(180) NOT NULL, roles CLOB NOT NULL, password VARCHAR(255) NOT NULL, database_path VARCHAR(255) NOT NULL, PRIMARY KEY(id))');
            $this->addSql('INSERT INTO "user" (id, username, email, roles, password, database_path) SELECT id, username, email, roles, password, database_path FROM __temp__user');
            $this->addSql('DROP TABLE __temp__user');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649F85E0677 ON "user" (username)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D6497929C943 ON "user" (database_path)');
        }
    }
}
