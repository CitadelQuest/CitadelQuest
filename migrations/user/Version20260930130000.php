<?php

/**
 * Migration: Add reasoning column to spirit_conversation_message.
 *
 * Stores the model's reasoning text (OpenRouter `message.reasoning`) alongside
 * the assistant message so it can be shown in the Spirit Chat conversation UI.
 * NULL for user/tool messages and for models that don't emit reasoning.
 */
class UserMigration_20260930130000
{
    public function up(\PDO $db): void
    {
        if ($this->columnExists($db, 'spirit_conversation_message', 'reasoning')) {
            return;
        }

        $db->exec('ALTER TABLE spirit_conversation_message ADD COLUMN reasoning TEXT');
    }

    public function down(\PDO $db): void
    {
        if (!$this->columnExists($db, 'spirit_conversation_message', 'reasoning')) {
            return;
        }

        $db->exec('ALTER TABLE spirit_conversation_message DROP COLUMN reasoning');
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
