<?php

/**
 * CQ-PROJECT — disambiguate `project.is_active`.
 *
 * `project.is_active` read like "the selected project" but actually meant
 * "enabled". The currently-selected workspace lives solely in
 * `settings.active_project_id`, so rename the column to `is_archived`
 * (default 0 = enabled) to remove the footgun and leave a clean hook for a
 * future "archive project" feature.
 *
 * See docs/features/CQ-PROJECT.md.
 */
class UserMigration_20261009130000
{
    public function up(PDO $db): void
    {
        // SQLite refuses to DROP an indexed column — remove the index first.
        $db->exec('DROP INDEX IF EXISTS idx_project_is_active');

        $db->exec('ALTER TABLE project ADD COLUMN is_archived BOOLEAN DEFAULT 0');
        $db->exec('UPDATE project SET is_archived = CASE WHEN is_active = 0 THEN 1 ELSE 0 END');
        $db->exec('ALTER TABLE project DROP COLUMN is_active');

        $db->exec('CREATE INDEX IF NOT EXISTS idx_project_is_archived ON project (is_archived)');
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_project_is_archived');

        $db->exec('ALTER TABLE project ADD COLUMN is_active BOOLEAN DEFAULT 1');
        $db->exec('UPDATE project SET is_active = CASE WHEN is_archived = 1 THEN 0 ELSE 1 END');
        $db->exec('ALTER TABLE project DROP COLUMN is_archived');

        $db->exec('CREATE INDEX IF NOT EXISTS idx_project_is_active ON project (is_active)');
    }
}
