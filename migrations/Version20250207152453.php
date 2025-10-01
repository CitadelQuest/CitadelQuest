<?php

declare(strict_types=1);

namespace DoctrineMigrations;

/**
 * Migration: Remove encryption key columns from user table
 * Compatible with both Doctrine and standalone updater
 */
final class Version20250207152453
{
    public function getDescription(): string
    {
        return 'Remove encryption key columns from user table';
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
        // Create a temporary table with only the columns we want to keep
        $pdo->exec('CREATE TABLE __temp__user_new (
            id BLOB NOT NULL, 
            username VARCHAR(180) NOT NULL,
            email VARCHAR(180) NOT NULL,
            roles CLOB NOT NULL,
            password VARCHAR(255) NOT NULL,
            database_path VARCHAR(255) NOT NULL,
            PRIMARY KEY(id)
        )');
        
        // Copy data to the new table
        $pdo->exec('INSERT INTO __temp__user_new 
            SELECT id, username, email, roles, password, database_path
            FROM user');
            
        // Drop the old table and rename the new one
        $pdo->exec('DROP TABLE user');
        $pdo->exec('ALTER TABLE __temp__user_new RENAME TO user');
        
        // Recreate the indexes
        $pdo->exec('CREATE UNIQUE INDEX UNIQ_8D93D6497929C943 ON user (database_path)');
        $pdo->exec('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON user (email)');
        $pdo->exec('CREATE UNIQUE INDEX UNIQ_8D93D649F85E0677 ON user (username)');
    }

    private function upDoctrine($schema): void
    {
        if (method_exists($this, 'addSql')) {
            // Create a temporary table with only the columns we want to keep
            $this->addSql('CREATE TABLE __temp__user_new (
                id BLOB NOT NULL, 
                username VARCHAR(180) NOT NULL,
                email VARCHAR(180) NOT NULL,
                roles CLOB NOT NULL,
                password VARCHAR(255) NOT NULL,
                database_path VARCHAR(255) NOT NULL,
                PRIMARY KEY(id)
            )');
            
            // Copy data to the new table
            $this->addSql('INSERT INTO __temp__user_new 
                SELECT id, username, email, roles, password, database_path
                FROM user');
                
            // Drop the old table and rename the new one
            $this->addSql('DROP TABLE user');
            $this->addSql('ALTER TABLE __temp__user_new RENAME TO user');
            
            // Recreate the indexes
            $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D6497929C943 ON user (database_path)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON user (email)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649F85E0677 ON user (username)');
        }
    }

    public function down($connection): void
    {
        if ($connection instanceof \PDO) {
            // Create a temporary table with all columns
            $connection->exec('CREATE TABLE __temp__user_new (
                id BLOB NOT NULL,
                username VARCHAR(180) NOT NULL,
                email VARCHAR(180) NOT NULL,
                roles CLOB NOT NULL,
                password VARCHAR(255) NOT NULL,
                database_path VARCHAR(255) NOT NULL,
                public_key VARCHAR(2048) DEFAULT NULL,
                encrypted_private_key CLOB DEFAULT NULL,
                key_salt VARCHAR(32) DEFAULT NULL,
                PRIMARY KEY(id)
            )');
            
            // Copy data to the new table
            $connection->exec('INSERT INTO __temp__user_new (id, username, email, roles, password, database_path)
                SELECT id, username, email, roles, password, database_path FROM user');
                
            // Drop the old table and rename the new one
            $connection->exec('DROP TABLE user');
            $connection->exec('ALTER TABLE __temp__user_new RENAME TO user');
            
            // Recreate the indexes
            $connection->exec('CREATE UNIQUE INDEX UNIQ_8D93D6497929C943 ON user (database_path)');
            $connection->exec('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON user (email)');
            $connection->exec('CREATE UNIQUE INDEX UNIQ_8D93D649F85E0677 ON user (username)');
        } elseif (method_exists($this, 'addSql')) {
            // Create a temporary table with all columns
            $this->addSql('CREATE TABLE __temp__user_new (
                id BLOB NOT NULL,
                username VARCHAR(180) NOT NULL,
                email VARCHAR(180) NOT NULL,
                roles CLOB NOT NULL,
                password VARCHAR(255) NOT NULL,
                database_path VARCHAR(255) NOT NULL,
                public_key VARCHAR(2048) DEFAULT NULL,
                encrypted_private_key CLOB DEFAULT NULL,
                key_salt VARCHAR(32) DEFAULT NULL,
                PRIMARY KEY(id)
            )');
            
            // Copy data to the new table
            $this->addSql('INSERT INTO __temp__user_new (id, username, email, roles, password, database_path)
                SELECT id, username, email, roles, password, database_path FROM user');
                
            // Drop the old table and rename the new one
            $this->addSql('DROP TABLE user');
            $this->addSql('ALTER TABLE __temp__user_new RENAME TO user');
            
            // Recreate the indexes
            $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D6497929C943 ON user (database_path)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON user (email)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649F85E0677 ON user (username)');
        }
    }
}
