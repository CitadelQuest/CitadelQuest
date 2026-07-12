<?php

/**
 * Migration: Update callSpirit tool parameters for conversationId semantics.
 *
 * Adds documented values for the conversationId parameter:
 *   - 'continue-last' (default) to continue the most recent S2S conversation
 *   - 'new' to force a fresh S2S conversation
 *   - existing S2S conversation UUID for a specific thread
 *
 * @see /docs/features/Spirit2SpiritChat.md
 */
class UserMigration_20260712150000
{
    public function up(\PDO $db): void
    {
        $stmt = $db->prepare('SELECT parameters FROM ai_tool WHERE name = ?');
        $stmt->execute(['callSpirit']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }

        $parameters = json_decode($row['parameters'], true);
        if (!is_array($parameters)) {
            $parameters = [];
        }

        $parameters['properties']['conversationId'] = [
            'type' => 'string',
            'description' => 'Optional: \'continue-last\' to continue the most recent S2S conversation (default), \'new\' to start a fresh S2S conversation, or an existing S2S conversation UUID.',
        ];

        $update = $db->prepare('UPDATE ai_tool SET parameters = ?, updated_at = ? WHERE name = ?');
        $update->execute([
            json_encode($parameters),
            date('Y-m-d H:i:s'),
            'callSpirit',
        ]);
    }

    public function down(\PDO $db): void
    {
        // No-op: previous parameters are compatible.
    }
}
