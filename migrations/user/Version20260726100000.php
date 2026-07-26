<?php

/**
 * Migration: Rename callSpirit -> spiritCall, remove listSpirits, add spiritManage.
 *
 * 1. Rename `callSpirit` tool to `spiritCall` (update name + description references).
 * 2. Remove deprecated `listSpirits` tool (replaced by spiritManage op:listSpirits).
 * 3. Add `spiritManage` tool with operations: listSpirits, listConversations, getConversation.
 *
 * @see /docs/features/Spirit2SpiritChat.md
 */
class UserMigration_20260726100000
{
    public function up(\PDO $db): void
    {
        // 1. Rename callSpirit -> spiritCall
        $stmt = $db->prepare('SELECT id, parameters FROM ai_tool WHERE name = ?');
        $stmt->execute(['callSpirit']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            $parameters = json_decode($row['parameters'], true) ?? [];
            // Update description references from listSpirits to spiritManage
            if (isset($parameters['properties']['targetSpiritId']['description'])) {
                $parameters['properties']['targetSpiritId']['description'] = str_replace(
                    'listSpirits',
                    'spiritManage (op: listSpirits)',
                    $parameters['properties']['targetSpiritId']['description']
                );
            }

            $update = $db->prepare('UPDATE ai_tool SET name = ?, description = ?, parameters = ?, updated_at = ? WHERE id = ?');
            $update->execute([
                'spiritCall',
                'Consult a fellow Spirit for help with a task that benefits from their different skills, tools or knowledge. The fellow Spirit runs a full turn with its own model, memory and tools, and returns its answer. Use spiritManage (op: listSpirits) first if unsure who to ask. Prefer this over guessing outside your expertise.',
                json_encode($parameters),
                date('Y-m-d H:i:s'),
                $row['id'],
            ]);
        }

        // 2. Remove deprecated listSpirits tool
        $db->exec("DELETE FROM ai_tool WHERE name = 'listSpirits'");

        // 3. Add spiritManage tool (inactive by default, same as original tools)
        $this->addTool(
            $db,
            'spiritManage',
            'Manage Spirit-to-Spirit interactions. Operations: listSpirits (list fellow Spirits available for consultation), listConversations (list the caller\'s S2S conversations, optionally filtered by a specific other Spirit), getConversation (retrieve a specific S2S conversation in full or compact mode).',
            [
                'type' => 'object',
                'properties' => [
                    'operation' => [
                        'type' => 'string',
                        'description' => 'The operation to perform: listSpirits, listConversations, or getConversation.',
                        'enum' => ['listSpirits', 'listConversations', 'getConversation'],
                    ],
                    'filterSpiritId' => [
                        'type' => 'string',
                        'description' => 'For listConversations: filter to only conversations between the caller and this other Spirit ID (from listSpirits). Omit to list all caller S2S conversations.',
                    ],
                    'filterSpiritName' => [
                        'type' => 'string',
                        'description' => 'For listConversations: filter by other Spirit name (alternative to filterSpiritId). Case-insensitive.',
                    ],
                    'conversationId' => [
                        'type' => 'string',
                        'description' => 'Conversation ID (required for getConversation operation).',
                    ],
                    'resultType' => [
                        'type' => 'string',
                        'description' => 'Result type for getConversation: "compact" (first and last message only, default — use this first) or "full" (all messages — only use if compact is not enough).',
                        'enum' => ['full', 'compact'],
                    ],
                    'page' => [
                        'type' => 'integer',
                        'description' => 'For listConversations: page number (10 results per page, newest first). Default: 1.',
                    ],
                ],
                'required' => ['operation'],
            ],
            0
        );
    }

    public function down(\PDO $db): void
    {
        // Reverse: rename spiritCall back to callSpirit, remove spiritManage, re-add listSpirits
        $stmt = $db->prepare('SELECT id, parameters FROM ai_tool WHERE name = ?');
        $stmt->execute(['spiritCall']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            $parameters = json_decode($row['parameters'], true) ?? [];
            if (isset($parameters['properties']['targetSpiritId']['description'])) {
                $parameters['properties']['targetSpiritId']['description'] = str_replace(
                    'spiritManage (op: listSpirits)',
                    'listSpirits',
                    $parameters['properties']['targetSpiritId']['description']
                );
            }

            $update = $db->prepare('UPDATE ai_tool SET name = ?, description = ?, parameters = ?, updated_at = ? WHERE id = ?');
            $update->execute([
                'callSpirit',
                'Consult a fellow Spirit for help with a task that benefits from their different skills, tools or knowledge. The fellow Spirit runs a full turn with its own model, memory and tools, and returns its answer. Use listSpirits first if unsure who to ask. Prefer this over guessing outside your expertise.',
                json_encode($parameters),
                date('Y-m-d H:i:s'),
                $row['id'],
            ]);
        }

        $db->exec("DELETE FROM ai_tool WHERE name = 'spiritManage'");

        // Re-add listSpirits
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

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
