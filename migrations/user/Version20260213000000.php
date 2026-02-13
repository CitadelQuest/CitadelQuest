<?php

/**
 * Migration: CQ Memory AI Tools — Consolidation
 * 
 * After testing and stabilization of CQ Memory system:
 * 1. Deactivate rarely-used memory tools (memoryStore, memoryUpdate, memoryForget)
 *    - memoryStore: Extraction pipeline handles memory creation better
 *    - memoryUpdate/memoryForget: Low usage, can be re-enabled per user preference
 * 2. Keep active: memoryRecall (manual search) + memoryExtract (AI extraction)
 * 3. Update descriptions to match current implementation
 * 
 * @see /docs/features/CQ-MEMORY.md
 */
class UserMigration_20260213000000
{
    public function up(\PDO $db): void
    {
        // 1. Set is_active for memory tools
        $toolStates = [
            'memoryStore'   => 0,
            'memoryRecall'  => 1,
            'memoryUpdate'  => 0,
            'memoryForget'  => 0,
            'memoryExtract' => 1,
        ];

        $stmt = $db->prepare("UPDATE ai_tool SET is_active = ?, updated_at = ? WHERE name = ?");
        foreach ($toolStates as $name => $isActive) {
            $stmt->execute([$isActive, date('Y-m-d H:i:s'), $name]);
        }

        // 2. Update descriptions for active memory tools
        $descriptions = [
            'memoryRecall' => 'Search and retrieve memories by query, category, or tags. Returns scored results with related memories. Use this to remember things about the user or past conversations.',
            'memoryExtract' => 'Extract memories from content using AI Sub-Agent. SMART FEATURES: Auto-loads content from files/conversations/URLs (no need to call getFileContent or fetchURL first!), Prevents duplicate extraction of same source. Use sourceType + sourceRef to auto-load: document: "projectId:path:filename" (e.g., "general:/docs:readme.md"), spirit_conversation: conversation ID, or alias "current"/"active"/"now" for the most recent conversation, or "all" to batch-extract ALL conversations (skips already-extracted ones), url: URL string (e.g., "https://example.com/article"). Use \'force: true\' to re-extract already processed sources.',
        ];

        $stmt = $db->prepare("UPDATE ai_tool SET description = ?, updated_at = ? WHERE name = ?");
        foreach ($descriptions as $name => $description) {
            $stmt->execute([$description, date('Y-m-d H:i:s'), $name]);
        }
    }

    public function down(\PDO $db): void
    {
        // Re-activate all memory tools
        $stmt = $db->prepare("UPDATE ai_tool SET is_active = 1, updated_at = ? WHERE name = ?");
        foreach (['memoryStore', 'memoryRecall', 'memoryUpdate', 'memoryForget', 'memoryExtract'] as $name) {
            $stmt->execute([date('Y-m-d H:i:s'), $name]);
        }
    }
}
