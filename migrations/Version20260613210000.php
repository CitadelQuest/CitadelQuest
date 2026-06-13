<?php

declare(strict_types=1);

namespace DoctrineMigrations;

/**
 * Migration: Create ai_tools table in main.db for Citadel-level AI tool policy.
 *
 * Stores the `adminOnly` flag per tool name (system-wide, not per-user).
 * Tools flagged admin_only are hidden from and unusable by non-admin users.
 * Seeds `runCommand` as admin-only by default (powerful/dangerous tool).
 *
 * Compatible with both Doctrine and the standalone updater (PDO).
 */
final class Version20260613210000
{
    public function getDescription(): string
    {
        return 'Create ai_tools table for Citadel-level AI tool policy (adminOnly)';
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
        $pdo->exec('CREATE TABLE IF NOT EXISTS ai_tools (
            id VARCHAR(36) NOT NULL,
            tool_name VARCHAR(255) NOT NULL,
            admin_only INTEGER NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY(id)
        )');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_ai_tools_tool_name ON ai_tools (tool_name)');

        $now = date('Y-m-d H:i:s');
        // Seed runCommand as admin-only by default
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO ai_tools (id, tool_name, admin_only, updated_at) VALUES (?, ?, 1, ?)');
        $stmt->execute([$this->uuidV4(), 'runCommand', $now]);
    }

    private function upDoctrine($schema): void
    {
        if (method_exists($this, 'addSql')) {
            $this->addSql('CREATE TABLE IF NOT EXISTS ai_tools (id VARCHAR(36) NOT NULL, tool_name VARCHAR(255) NOT NULL, admin_only INTEGER NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_ai_tools_tool_name ON ai_tools (tool_name)');
            $now = date('Y-m-d H:i:s');
            $this->addSql("INSERT OR IGNORE INTO ai_tools (id, tool_name, admin_only, updated_at) VALUES ('{$this->uuidV4()}', 'runCommand', 1, '{$now}')");
        }
    }

    public function down($connection): void
    {
        if ($connection instanceof \PDO) {
            $connection->exec('DROP TABLE IF EXISTS ai_tools');
        } elseif (method_exists($this, 'addSql')) {
            $this->addSql('DROP TABLE IF EXISTS ai_tools');
        }
    }

    /**
     * Standalone UUID v4 generator (no external dependency, safe in updater context).
     */
    private function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
