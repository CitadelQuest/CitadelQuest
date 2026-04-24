<?php

/**
 * Migration: Create imager_generation table for CQ Imager feature.
 *
 * Stores per-generation metadata (model, full params JSON, seed, cost,
 * Runware task UUID). The actual image file is stored in the user's
 * File Browser (project_file table) and referenced here by project_file_id.
 *
 * @see /docs/features/CQ-IMAGER.md
 */
class UserMigration_20260418230000
{
    public function up(\PDO $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS imager_generation (
                id              TEXT PRIMARY KEY,
                project_id      TEXT NOT NULL,
                project_file_id TEXT NOT NULL,
                model           TEXT NOT NULL,
                model_slug      TEXT,
                model_name      TEXT,
                params_json     TEXT NOT NULL,
                seed            INTEGER,
                cost_credits    REAL,
                width           INTEGER,
                height          INTEGER,
                image_url       TEXT,
                task_uuid       TEXT,
                created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $db->exec('CREATE INDEX IF NOT EXISTS idx_imager_gen_created   ON imager_generation(created_at DESC)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_imager_gen_project   ON imager_generation(project_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_imager_gen_file      ON imager_generation(project_file_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_imager_gen_model     ON imager_generation(model)');
    }

    public function down(\PDO $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_imager_gen_model');
        $db->exec('DROP INDEX IF EXISTS idx_imager_gen_file');
        $db->exec('DROP INDEX IF EXISTS idx_imager_gen_project');
        $db->exec('DROP INDEX IF EXISTS idx_imager_gen_created');
        $db->exec('DROP TABLE IF EXISTS imager_generation');
    }
}
