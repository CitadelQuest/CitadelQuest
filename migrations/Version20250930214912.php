<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250930214912 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create system_settings table for system-wide configuration';
    }

    public function up(Schema $schema): void
    {
        // Create system_settings table
        $this->addSql('CREATE TABLE system_settings (setting_key VARCHAR(255) NOT NULL, value CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(setting_key))');
        
        // Add default setting: registration enabled by default
        $now = date('Y-m-d H:i:s');
        $this->addSql("INSERT INTO system_settings (setting_key, value, created_at, updated_at) VALUES ('cq_register', '1', '{$now}', '{$now}')");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE system_settings');
    }
}
