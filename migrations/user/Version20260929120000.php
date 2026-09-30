<?php

/**
 * Migration: AI tool default-parameter cleanups.
 *
 * 1. memorySource — remove the "all" range option.
 *    The `range` parameter now only accepts an explicit "start:end" line range
 *    (e.g. "10:25") and is required. The tool description is adjusted accordingly
 *    (content is fetched by line range, not "full"). Full source content is still
 *    reachable by passing a range that covers the whole document (e.g. "1:999999"
 *    — the end line is clamped to the total line count).
 *
 * 2. spiritCall — the `conversationId` default is now 'new' (fresh S2S
 *    conversation) instead of 'continue-last'.
 *
 * Existing installations have the tools from earlier migrations, which skip if
 * the tool already exists. This migration updates the schemas in place.
 */
class UserMigration_20260929120000
{
    public function up(\PDO $db): void
    {
        $this->updateMemorySource($db);
        $this->updateSpiritCall($db);
    }

    private function updateMemorySource(\PDO $db): void
    {
        $stmt = $db->prepare("SELECT id, parameters FROM ai_tool WHERE name = 'memorySource'");
        $stmt->execute();
        $tool = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$tool) {
            return;
        }

        $parameters = json_decode($tool['parameters'], true);
        if (!is_array($parameters)) {
            $parameters = ['type' => 'object', 'properties' => [], 'required' => ['source']];
        }

        if (isset($parameters['properties']['range'])) {
            $parameters['properties']['range']['description'] = 'Line range to return in "start:end" format (e.g., "10:25" for lines 10-25).';
        }

        // range is now required
        $required = $parameters['required'] ?? ['source'];
        if (!in_array('range', $required, true)) {
            $required[] = 'range';
        }
        $parameters['required'] = $required;

        $newDescription = 'Retrieve original source content of a memory. Use this for fact-checking: when recalled memories include source_ref or memory IDs, fetch the original document/conversation/URL content they were extracted from, for a given line range. Searches across all CQ Memory Packs in the Spirit\'s Library.';

        $stmt = $db->prepare("UPDATE ai_tool SET description = ?, parameters = ?, updated_at = ? WHERE id = ?");
        $stmt->execute([
            $newDescription,
            json_encode($parameters),
            date('Y-m-d H:i:s'),
            $tool['id']
        ]);
    }

    private function updateSpiritCall(\PDO $db): void
    {
        $stmt = $db->prepare("SELECT id, parameters FROM ai_tool WHERE name = 'spiritCall'");
        $stmt->execute();
        $tool = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$tool) {
            return;
        }

        $parameters = json_decode($tool['parameters'], true);
        if (!is_array($parameters)) {
            $parameters = [];
        }

        if (isset($parameters['properties']['conversationId'])) {
            $parameters['properties']['conversationId']['description'] = 'Optional: \'new\' to start a fresh S2S conversation (default), \'continue-last\' to continue the most recent S2S conversation, or an existing S2S conversation UUID.';
        }

        $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, updated_at = ? WHERE id = ?");
        $stmt->execute([
            json_encode($parameters),
            date('Y-m-d H:i:s'),
            $tool['id']
        ]);
    }

    public function down(\PDO $db): void
    {
        // No-op: reverting schema text is not meaningful
    }
}
