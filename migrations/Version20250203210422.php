<?php

declare(strict_types=1);

namespace DoctrineMigrations;

/**
 * Migration: Create user table
 * Compatible with both Doctrine and standalone updater
 */
final class Version20250203210422
{
    public function getDescription(): string
    {
        return 'Create user table';
    }

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
        $pdo->exec('CREATE TABLE "user" (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, username VARCHAR(180) NOT NULL, email VARCHAR(180) NOT NULL, roles CLOB NOT NULL, password VARCHAR(255) NOT NULL, database_path VARCHAR(255) NOT NULL, public_key VARCHAR(255) DEFAULT NULL)');
        $pdo->exec('CREATE UNIQUE INDEX UNIQ_8D93D649F85E0677 ON "user" (username)');
        $pdo->exec('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)');
        $pdo->exec('CREATE UNIQUE INDEX UNIQ_8D93D6497929C943 ON "user" (database_path)');
    }

    private function upDoctrine($schema): void
    {
        if (method_exists($this, 'addSql')) {
            $this->addSql('CREATE TABLE "user" (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, username VARCHAR(180) NOT NULL, email VARCHAR(180) NOT NULL, roles CLOB NOT NULL --(DC2Type:json)
        , password VARCHAR(255) NOT NULL, database_path VARCHAR(255) NOT NULL, public_key VARCHAR(255) DEFAULT NULL)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649F85E0677 ON "user" (username)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D6497929C943 ON "user" (database_path)');
        }
    }

    public function down($connection): void
    {
        if ($connection instanceof \PDO) {
            $connection->exec('DROP TABLE "user"');
        } elseif (method_exists($this, 'addSql')) {
            $this->addSql('DROP TABLE "user"');
        }
    }
}
