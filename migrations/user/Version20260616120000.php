<?php

class UserMigration_20260616120000
{
    public function up(PDO $db): void
    {
        // Background Spirit Chat turn processing (fixes Cloudflare 524 on long AI turns).
        // A "turn" = one user message -> AI response -> full tool-execution loop, run by a
        // detached CLI worker (app:spirit-chat-turn). The browser only polls this table.
        $db->exec('CREATE TABLE IF NOT EXISTS spirit_chat_turn (
            id VARCHAR(36) PRIMARY KEY,
            conversation_id VARCHAR(36) NOT NULL,
            user_message_id VARCHAR(36) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT "pending",
            stop_requested INTEGER NOT NULL DEFAULT 0,
            payload TEXT DEFAULT NULL,
            error TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL
        )');

        $db->exec('CREATE INDEX IF NOT EXISTS idx_spirit_chat_turn_conversation
            ON spirit_chat_turn(conversation_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_spirit_chat_turn_status
            ON spirit_chat_turn(status)');
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS spirit_chat_turn');
    }
}
