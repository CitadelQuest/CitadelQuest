<?php

declare(strict_types=1);

namespace DoctrineMigrations;

/**
 * Migration for creating system_settings table
 * Compatible with both Doctrine and standalone updater
 */
final class Version20250930214912
{
    public function getDescription(): string
    {
        return 'Create system_settings table for system-wide configuration';
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
        // Create system_settings table
        $pdo->exec('CREATE TABLE system_settings (
            setting_key VARCHAR(255) NOT NULL, 
            value TEXT DEFAULT NULL, 
            created_at DATETIME NOT NULL, 
            updated_at DATETIME NOT NULL, 
            PRIMARY KEY(setting_key)
        )');
        
        // Add default setting: registration enabled by default
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, value, created_at, updated_at) VALUES ('cq_register', '1', ?, ?)");
        $stmt->execute([$now, $now]);
    }

    private function upDoctrine($schema): void
    {
        // For Doctrine migrations (dev environment)
        $sql = 'CREATE TABLE system_settings (setting_key VARCHAR(255) NOT NULL, value CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(setting_key))';
        
        // Use addSql if available (Doctrine AbstractMigration)
        if (method_exists($this, 'addSql')) {
            $this->addSql($sql);
            $now = date('Y-m-d H:i:s');
            $this->addSql("INSERT INTO system_settings (setting_key, value, created_at, updated_at) VALUES ('cq_register', '1', '{$now}', '{$now}')");
        }
    }

    public function down($connection): void
    {
        if ($connection instanceof \PDO) {
            $connection->exec('DROP TABLE system_settings');
        } elseif (method_exists($this, 'addSql')) {
            $this->addSql('DROP TABLE system_settings');
        }
    }
}
