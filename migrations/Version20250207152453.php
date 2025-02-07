<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250207152453 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
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

    public function down(Schema $schema): void
    {
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
