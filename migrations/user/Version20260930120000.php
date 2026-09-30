<?php

/**
 * Migration: Add reasoning_effort column to ai_service_request.
 *
 * Lets each AI request carry a per-Spirit reasoning effort (OpenRouter
 * `reasoning.effort`: max, xhigh, high, medium, low, minimal, none).
 * NULL/empty means "use the gateway default" (currently `minimal`).
 */
class UserMigration_20260930120000
{
    public function up(\PDO $db): void
    {
        if ($this->columnExists($db, 'ai_service_request', 'reasoning_effort')) {
            return;
        }

        $db->exec('ALTER TABLE ai_service_request ADD COLUMN reasoning_effort TEXT');
    }

    public function down(\PDO $db): void
    {
        if (!$this->columnExists($db, 'ai_service_request', 'reasoning_effort')) {
            return;
        }

        $db->exec('ALTER TABLE ai_service_request DROP COLUMN reasoning_effort');
    }

    private function columnExists(\PDO $db, string $table, string $column): bool
    {
        $result = $db->query("PRAGMA table_info({$table})");
        while ($row = $result->fetch(\PDO::FETCH_ASSOC)) {
            if (($row['name'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }
}
