<?php

/**
 * Migration: Migrate Spirit properties to SpiritSettings
 * 
 * This migration migrates Spirit properties that were moved to SpiritSettings:
 * - level, experience, visualState, systemPrompt, aiModel
 * 
 * These properties are now stored as key-value pairs in the spirit_settings table.
 */
class UserMigration_20260111194900
{
    public function getDescription(): string
    {
        return 'Migrate Spirit properties to SpiritSettings table';
    }

    public function up(\PDO $pdo): void
    {
        // Get all existing spirits
        $stmt = $pdo->query('SELECT id, level, experience, visual_state, system_prompt, ai_model FROM spirits');
        $spirits = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $now = date('Y-m-d H:i:s');

        foreach ($spirits as $spirit) {
            $spiritId = $spirit['id'];

            // Migrate level
            if (isset($spirit['level'])) {
                $this->insertSpiritSetting($pdo, $spiritId, 'level', (string)$spirit['level'], $now);
            }

            // Migrate experience
            if (isset($spirit['experience'])) {
                $this->insertSpiritSetting($pdo, $spiritId, 'experience', (string)$spirit['experience'], $now);
            }

            // Migrate visualState
            if (isset($spirit['visual_state'])) {
                $this->insertSpiritSetting($pdo, $spiritId, 'visualState', $spirit['visual_state'], $now);
            }

            // Migrate systemPrompt (can be null)
            if (isset($spirit['system_prompt']) && $spirit['system_prompt'] !== null) {
                $this->insertSpiritSetting($pdo, $spiritId, 'systemPrompt', $spirit['system_prompt'], $now);
            }

            // Migrate aiModel
            if (isset($spirit['ai_model']) && $spirit['ai_model'] !== '') {
                $this->insertSpiritSetting($pdo, $spiritId, 'aiModel', $spirit['ai_model'], $now);
            }
        }

        // Drop old columns from spirits table
        $this->dropColumn($pdo, 'spirits', 'level');
        $this->dropColumn($pdo, 'spirits', 'experience');
        $this->dropColumn($pdo, 'spirits', 'visual_state');
        $this->dropColumn($pdo, 'spirits', 'consciousness_level');
        $this->dropColumn($pdo, 'spirits', 'system_prompt');
        $this->dropColumn($pdo, 'spirits', 'ai_model');

        // Drop spirit abilities table
        $pdo->exec('DROP TABLE spirit_abilities');
    }

    private function insertSpiritSetting(\PDO $pdo, string $spiritId, string $key, ?string $value, string $now): void
    {
        $stmt = $pdo->prepare('
            INSERT INTO spirit_settings (id, spirit_id, key, value, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([uuid_create(), $spiritId, $key, $value, $now, $now]);
    }

    private function dropColumn(\PDO $pdo, string $table, string $column): void
    {
        // SQLite doesn't support DROP COLUMN directly, need to recreate table
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS spirits_new (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                created_at TEXT NOT NULL,
                last_interaction TEXT NOT NULL
            )
        ');

        $pdo->exec('
            INSERT INTO spirits_new (id, name, created_at, last_interaction)
            SELECT id, name, created_at, last_interaction FROM spirits
        ');

        $pdo->exec('DROP TABLE spirits');
        $pdo->exec('ALTER TABLE spirits_new RENAME TO spirits');
    }

    public function down(\PDO $pdo): void
    {
        // Cannot easily rollback - would require recreating old columns
        // This is a one-way migration
    }
}
