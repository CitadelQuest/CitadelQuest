<?php

/**
 * CQ-PROJECT — Phase A foundation.
 *
 * A "Project" is a workspace. `project.id` is now the short human-readable slug
 * (e.g. 'general', 'marketing') used everywhere as `projectId`; there is no
 * UUID and no separate `slug` column anymore.
 *
 * The `project`/`project_spirit*` tables were created back in
 * Version20250907142600 as an unused scaffold (empty in every instance), so a
 * rebuild is safe. The system project 'general' is seeded here, and every
 * existing Spirit / conversation is attached to it so nothing breaks.
 *
 * See docs/features/CQ-PROJECT.md for the full design.
 */
class UserMigration_20261009120000
{
    public function up(PDO $db): void
    {
        // 1. `project`: id becomes the slug, add is_system, drop legacy `slug` column.
        $db->exec('DROP TABLE IF EXISTS project');
        $db->exec('CREATE TABLE project (
            id VARCHAR(64) PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            is_public BOOLEAN DEFAULT 0,
            is_active BOOLEAN DEFAULT 1,
            is_system BOOLEAN DEFAULT 0,
            src_url VARCHAR(1000),
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_project_is_public ON project (is_public)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_project_is_active ON project (is_active)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_project_is_system ON project (is_system)');

        // 2. `project_spirit_conversation`: narrow it down to a project <-> conversation pairing.
        $db->exec('DROP TABLE IF EXISTS project_spirit_conversation');
        $db->exec('CREATE TABLE project_spirit_conversation (
            id VARCHAR(36) PRIMARY KEY,
            project_id VARCHAR(64) NOT NULL,
            spirit_conversation_id VARCHAR(36) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE (project_id, spirit_conversation_id)
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_project_spirit_conversation_project_id ON project_spirit_conversation (project_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_project_spirit_conversation_spirit_conversation_id ON project_spirit_conversation (spirit_conversation_id)');

        // 3. `project_tool` removed — AI tools are global, not project-scoped.
        $db->exec('DROP TABLE IF EXISTS project_tool');

        // 4. Seed the system project 'general' (always present, non-deletable).
        $db->exec("INSERT OR IGNORE INTO project
            (id, title, description, is_public, is_active, is_system, src_url, created_at, updated_at)
            VALUES ('general', 'General', 'Personal workspace', 0, 1, 1, NULL, datetime('now'), datetime('now'))");

        // 5. Attach every existing Spirit to 'general'.
        $db->exec("INSERT OR IGNORE INTO project_spirit (id, project_id, spirit_id)
            SELECT
                lower(
                    hex(randomblob(4)) || '-' ||
                    hex(randomblob(2)) || '-4' ||
                    substr(hex(randomblob(2)), 2) || '-' ||
                    substr('89ab', abs(random()) % 4 + 1, 1) ||
                    substr(hex(randomblob(2)), 2) || '-' ||
                    hex(randomblob(6))
                ),
                'general',
                id
            FROM spirits");

        // 6. Pair every existing conversation with 'general'.
        $db->exec("INSERT OR IGNORE INTO project_spirit_conversation
            (id, project_id, spirit_conversation_id, created_at, updated_at)
            SELECT
                lower(
                    hex(randomblob(4)) || '-' ||
                    hex(randomblob(2)) || '-4' ||
                    substr(hex(randomblob(2)), 2) || '-' ||
                    substr('89ab', abs(random()) % 4 + 1, 1) ||
                    substr(hex(randomblob(2)), 2) || '-' ||
                    hex(randomblob(6))
                ),
                'general',
                id,
                datetime('now'),
                datetime('now')
            FROM spirit_conversation");
    }

    public function down(PDO $db): void
    {
        // Best-effort reverse. Restores the legacy (pre-Phase-A) shape.
        $db->exec('DROP TABLE IF EXISTS project');
        $db->exec('CREATE TABLE project (
            id VARCHAR(36) PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            slug VARCHAR(36) NOT NULL,
            description TEXT,
            is_public BOOLEAN DEFAULT 0,
            is_active BOOLEAN DEFAULT 1,
            src_url VARCHAR(1000),
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_project_id ON project (id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_project_slug ON project (slug)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_project_is_public ON project (is_public)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_project_is_active ON project (is_active)');

        $db->exec('DROP TABLE IF EXISTS project_spirit_conversation');
        $db->exec('CREATE TABLE project_spirit_conversation (
            id VARCHAR(36) PRIMARY KEY,
            project_id VARCHAR(36) NOT NULL,
            spirit_conversation_id VARCHAR(36) NOT NULL,
            category VARCHAR(255),
            system_prompt_instructions TEXT,
            task_list TEXT,
            task_list_status VARCHAR(255),
            task_list_result TEXT,
            frontend_data TEXT,
            autorun BOOLEAN DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )');

        $db->exec('CREATE TABLE IF NOT EXISTS project_tool (
            id VARCHAR(36) PRIMARY KEY,
            project_id VARCHAR(36) NOT NULL,
            tool_id VARCHAR(36) NOT NULL,
            UNIQUE (project_id, tool_id)
        )');
    }
}
