<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250204000953 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // Create a new table with UUID
        $this->addSql('CREATE TABLE user_new (id BLOB NOT NULL --(DC2Type:uuid)
        , username VARCHAR(180) NOT NULL, email VARCHAR(180) NOT NULL, roles CLOB NOT NULL --(DC2Type:json)
        , password VARCHAR(255) NOT NULL, database_path VARCHAR(255) NOT NULL, public_key VARCHAR(2048) DEFAULT NULL, encrypted_private_key CLOB DEFAULT NULL, key_salt VARCHAR(32) DEFAULT NULL, PRIMARY KEY(id))');
        
        // Get existing users and migrate them with new UUIDs
        $users = $this->connection->fetchAllAssociative('SELECT * FROM user');
        foreach ($users as $user) {
            $uuid = \Symfony\Component\Uid\Uuid::v7();
            $this->addSql(
                'INSERT INTO user_new (id, username, email, roles, password, database_path, public_key, encrypted_private_key, key_salt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$uuid->toBinary(), $user['username'], $user['email'], $user['roles'], $user['password'], $user['database_path'], $user['public_key'], $user['encrypted_private_key'], $user['key_salt']]
            );
        }
        
        // Drop old table and rename new one
        $this->addSql('DROP TABLE user');
        $this->addSql('ALTER TABLE user_new RENAME TO user');
        
        // Recreate indexes
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649F85E0677 ON user (username)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON user (email)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D6497929C943 ON user (database_path)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__user AS SELECT id, username, email, roles, password, database_path, public_key, encrypted_private_key, key_salt FROM "user"');
        $this->addSql('DROP TABLE "user"');
        $this->addSql('CREATE TABLE "user" (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, username VARCHAR(180) NOT NULL, email VARCHAR(180) NOT NULL, roles CLOB NOT NULL --(DC2Type:json)
        , password VARCHAR(255) NOT NULL, database_path VARCHAR(255) NOT NULL, public_key VARCHAR(2048) DEFAULT NULL, encrypted_private_key CLOB DEFAULT NULL, key_salt VARCHAR(32) DEFAULT NULL)');
        $this->addSql('INSERT INTO "user" (id, username, email, roles, password, database_path, public_key, encrypted_private_key, key_salt) SELECT id, username, email, roles, password, database_path, public_key, encrypted_private_key, key_salt FROM __temp__user');
        $this->addSql('DROP TABLE __temp__user');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649F85E0677 ON "user" (username)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D6497929C943 ON "user" (database_path)');
    }
}
