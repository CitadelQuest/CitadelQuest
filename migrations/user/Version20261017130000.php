<?php

/**
 * Migration: Add `listAllSpirits` operation to `spiritManage` AI Tool.
 *
 * - `listSpirits`: lists fellow Spirits assigned to the active project.
 * - `listAllSpirits`: lists all fellow Spirits across all projects on the Citadel
 *   (including each Spirit's assigned project IDs).
 *
 * `spiritCall` and `spiritManage` (`listConversations`, `getConversation`) can
 * consult and inspect fellow Spirits across all projects on the Citadel.
 */
class UserMigration_20261017130000
{
    public function up(\PDO $db): void
    {
        $now = date('Y-m-d H:i:s');

        $description = 'Manage Spirit-to-Spirit interactions. Operations: listSpirits (list fellow Spirits in the active project), listAllSpirits (list all fellow Spirits across all projects on the Citadel), listConversations (list the caller\'s S2S conversations, optionally filtered by a specific other Spirit), getConversation (retrieve a specific S2S conversation in full or compact mode).';

        $parameters = json_encode([
            'type' => 'object',
            'properties' => [
                'operation' => [
                    'type' => 'string',
                    'description' => 'The operation to perform: listSpirits, listAllSpirits, listConversations, or getConversation.',
                    'enum' => ['listSpirits', 'listAllSpirits', 'listConversations', 'getConversation'],
                ],
                'filterSpiritId' => [
                    'type' => 'string',
                    'description' => 'For listConversations: filter to only conversations between the caller and this other Spirit ID (from listSpirits / listAllSpirits). Omit to list all caller S2S conversations.',
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
        ]);

        $stmt = $db->prepare("UPDATE ai_tool SET description = ?, parameters = ?, updated_at = ? WHERE name = 'spiritManage'");
        $stmt->execute([$description, $parameters, $now]);
    }

    public function down(\PDO $db): void
    {
        // No-op
    }
}
