<?php

class UserMigration_20260622040000
{
    public function up(PDO $db): void
    {
        // Webhook callback results from CQ AI Gateway.
        // When a background AI job completes on the gateway, it POSTs the result
        // to our /{username}/api/webhook/ai-gateway endpoint, which stores it here.
        // The waiting CQAIGateway::sendRequestViaJob() loop reads from this table
        // instead of polling the gateway over HTTP.
        $db->exec('CREATE TABLE IF NOT EXISTS ai_webhook_result (
            job_id TEXT PRIMARY KEY,
            status TEXT NOT NULL,
            response_payload TEXT,
            error TEXT,
            created_at TEXT NOT NULL
        )');
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS ai_webhook_result');
    }
}
