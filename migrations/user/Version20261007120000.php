<?php

class UserMigration_20261007120000
{
    public function up(PDO $db): void
    {
        // Background backup job processing (fixes Cloudflare 524 on large backups).
        // A "job" = one .citadel archive creation, run by a detached CLI worker
        // (app:backup-create). The browser only polls this table for status.
        $db->exec('CREATE TABLE IF NOT EXISTS backup_job (
            id VARCHAR(36) PRIMARY KEY,
            status VARCHAR(20) NOT NULL DEFAULT "pending",
            filename TEXT DEFAULT NULL,
            size INTEGER DEFAULT NULL,
            error TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL
        )');

        $db->exec('CREATE INDEX IF NOT EXISTS idx_backup_job_status
            ON backup_job(status)');
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS backup_job');
    }
}
