<?php

/**
 * Migration: Spirit-to-Spirit Chat (`callSpirit`)
 *
 * 1. Extends spirit_conversation with:
 *    - origin ('user' | 'spirit') to mark Spirit-to-Spirit consultations
 *    - initiator_spirit_id (the CALLER spirit's id when origin='spirit')
 * 2. Seeds the `callSpirit` and `listSpirits` AI tools (inactive by default;
 *    each Spirit opts in via per-spirit activeTools + s2s.enabled setting).
 *
 * @see /docs/features/Spirit2SpiritChat.md
 */
class UserMigration_20260711220000
{
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public function up(\PDO $db): void
    {
        // --- 1. spirit_conversation columns (idempotent) ---
        $existing = [];
        foreach ($db->query('PRAGMA table_info(spirit_conversation)') as $col) {
            $existing[] = $col['name'];
        }

        if (!in_array('origin', $existing, true)) {
            // 'user' (default, Human -> Spirit) | 'spirit' (Spirit -> Spirit)
            $db->exec("ALTER TABLE spirit_conversation ADD COLUMN origin TEXT NOT NULL DEFAULT 'user'");
        }
        if (!in_array('initiator_spirit_id', $existing, true)) {
            // The CALLER spirit's id when origin='spirit'; NULL for normal conversations.
            $db->exec('ALTER TABLE spirit_conversation ADD COLUMN initiator_spirit_id VARCHAR(36) DEFAULT NULL');
        }

        $db->exec('CREATE INDEX IF NOT EXISTS idx_spirit_conversation_origin
            ON spirit_conversation(origin, initiator_spirit_id)');

        // --- 2. Seed tools ---
        $this->addTool(
            $db,
            'listSpirits',
            'List fellow Spirits you can consult with callSpirit. Returns each Spirit\'s name, id and specialty. Use this to decide who to ask for help with a task outside your own skills.',
            [
                'type' => 'object',
                'properties' => new \stdClass(),
                'required' => [],
            ],
            0
        );

        $this->addTool(
            $db,
            'callSpirit',
            'Consult a fellow Spirit for help with a task that benefits from their different skills, tools or knowledge. The fellow Spirit runs a full turn with its own model, memory and tools, and returns its answer. Use listSpirits first if unsure who to ask. Prefer this over guessing outside your expertise.',
            [
                'type' => 'object',
                'properties' => [
                    'targetSpiritId' => [
                        'type' => 'string',
                        'description' => 'The id of the Spirit to consult (from listSpirits). Either this or targetSpiritName is required.',
                    ],
                    'targetSpiritName' => [
                        'type' => 'string',
                        'description' => 'The name of the Spirit to consult (case-insensitive). Used if targetSpiritId is not given.',
                    ],
                    'message' => [
                        'type' => 'string',
                        'description' => 'Your request/question/task for the fellow Spirit. Be specific and self-contained - include the context they need.',
                    ],
                    'conversationId' => [
                        'type' => 'string',
                        'description' => 'Optional: an existing Spirit-to-Spirit conversation id to continue the thread instead of starting fresh.',
                    ],
                ],
                'required' => ['message'],
            ],
            0
        );
    }

    private function addTool(\PDO $db, string $name, string $description, array $parameters, int $isActive = 0): void
    {
        $stmt = $db->prepare('SELECT id FROM ai_tool WHERE name = ?');
        $stmt->execute([$name]);
        if (!$stmt->fetch(\PDO::FETCH_ASSOC)) {
            $stmt = $db->prepare(
                'INSERT INTO ai_tool (id, name, description, parameters, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $this->generateUuid(),
                $name,
                $description,
                json_encode($parameters),
                $isActive,
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function down(\PDO $db): void
    {
        // SQLite DROP COLUMN is awkward/version-dependent; leave columns in place.
        $db->exec("DELETE FROM ai_tool WHERE name IN ('callSpirit', 'listSpirits')");
        $db->exec('DROP INDEX IF EXISTS idx_spirit_conversation_origin');
    }
}
