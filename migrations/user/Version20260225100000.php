<?php

/**
 * Migration: Create project_file_remote table
 * 
 * Tracks files downloaded from remote Citadels via CQ Share.
 * Enables automatic sync of shared files on read access,
 * mirroring the sync pattern used for CQ Memory Packs.
 */
class UserMigration_20260225100000
{
    public function up(\PDO $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS project_file_remote (
                id VARCHAR(36) PRIMARY KEY,
                project_file_id VARCHAR(36) NOT NULL,
                source_url TEXT NOT NULL,
                source_cq_contact_id VARCHAR(36),
                synced_at DATETIME,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (project_file_id) REFERENCES project_file(id) ON DELETE CASCADE
            )
        ");

        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_pfr_file_id ON project_file_remote(project_file_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_pfr_source_contact ON project_file_remote(source_cq_contact_id)");
    }

    public function down(\PDO $db): void
    {
        $db->exec("DROP TABLE IF EXISTS project_file_remote");
    }
}
