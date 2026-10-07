<?php

class UserMigration_20261007130000
{
    public function up(PDO $db): void
    {
        // Generic background maintenance jobs in the user database.
        // One row per long-running job (backup creation, database optimization) run
        // by a detached CLI worker, so the browser only polls this table and long
        // work never hits Cloudflare's 100s proxy timeout (HTTP 524).
        $db->exec('CREATE TABLE IF NOT EXISTS maintenance_job (
            id VARCHAR(36) PRIMARY KEY,
            type VARCHAR(40) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT "pending",
            payload TEXT DEFAULT NULL,
            result TEXT DEFAULT NULL,
            error TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL
        )');

        $db->exec('CREATE INDEX IF NOT EXISTS idx_maintenance_job_type_status
            ON maintenance_job(type, status)');

        // Superseded by maintenance_job. Backup jobs are ephemeral (no data to migrate).
        $db->exec('DROP TABLE IF EXISTS backup_job');
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS maintenance_job');
    }
}
