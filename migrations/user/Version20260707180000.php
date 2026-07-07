<?php

/**
 * Migration: Point AI tool and spirit settings to the default fast model.
 *
 * 1. Removes deprecated document_summary_* settings from memoryExtract tool.
 * 2. Sets memoryExtract extraction/relationship analyzer models to the default model.
 * 3. Sets fetchURL extraction model to the default model.
 * 4. Sets subconsciousnessAgentAiModel for all spirits to the default model.
 *
 * Default model: ai_service_model.model_slug = "citadelquest/deepseek-v4-flash"
 */
class UserMigration_20260707180000
{
    public function up(PDO $db): void
    {
        // Find the default AI model by slug.
        $stmt = $db->prepare("SELECT id FROM ai_service_model WHERE model_slug = ? LIMIT 1");
        $stmt->execute(['citadelquest/deepseek-v4-flash']);
        $model = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$model) {
            // Default model not present yet (model sync may not have run).
            // Skip gracefully; settings will remain at their previous values.
            return;
        }

        $modelId = $model['id'];
        $now = date('Y-m-d H:i:s');

        // 1. Remove deprecated document_summary settings from memoryExtract tool.
        $stmt = $db->prepare("SELECT id FROM ai_tool WHERE name = 'memoryExtract'");
        $stmt->execute();
        $memoryTool = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($memoryTool) {
            $deleteStmt = $db->prepare(
                "DELETE FROM ai_tool_settings WHERE tool_id = ? AND key IN ('document_summary_ai_model', 'document_summary_system_prompt')"
            );
            $deleteStmt->execute([$memoryTool['id']]);

            // 2. Set memoryExtract AI model settings to the default model.
            $updateStmt = $db->prepare(
                "UPDATE ai_tool_settings SET value = ?, updated_at = ? WHERE tool_id = ? AND key = ?"
            );
            $updateStmt->execute([$modelId, $now, $memoryTool['id'], 'extraction_ai_model']);
            $updateStmt->execute([$modelId, $now, $memoryTool['id'], 'relationship_analyzer_ai_model']);
        }

        // 3. Set fetchURL extraction model to the default model.
        $stmt = $db->prepare("SELECT id FROM ai_tool WHERE name = 'fetchURL'");
        $stmt->execute();
        $fetchTool = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($fetchTool) {
            $updateStmt = $db->prepare(
                "UPDATE ai_tool_settings SET value = ?, updated_at = ? WHERE tool_id = ? AND key = ?"
            );
            $updateStmt->execute([$modelId, $now, $fetchTool['id'], 'extraction_ai_model']);
        }

        // 4. Set subconsciousnessAgentAiModel for all spirits to the default model.
        $spirits = $db->query("SELECT id FROM spirits")->fetchAll(PDO::FETCH_ASSOC);
        if (count($spirits) > 0) {
            $selectIdStmt = $db->prepare(
                "SELECT id FROM spirit_settings WHERE spirit_id = ? AND key = ?"
            );
            $replaceStmt = $db->prepare(
                "INSERT OR REPLACE INTO spirit_settings (id, spirit_id, key, value, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );

            foreach ($spirits as $spirit) {
                $selectIdStmt->execute([$spirit['id'], 'subconsciousnessAgentAiModel']);
                $existing = $selectIdStmt->fetch(PDO::FETCH_ASSOC);
                $id = $existing['id'] ?? $this->generateUuid();

                $replaceStmt->execute([
                    $id,
                    $spirit['id'],
                    'subconsciousnessAgentAiModel',
                    $modelId,
                    $now,
                    $now
                ]);
            }
        }
    }

    public function down(PDO $db): void
    {
        $now = date('Y-m-d H:i:s');

        // 1. Reset memoryExtract AI model settings to empty (default fallback).
        $stmt = $db->prepare("SELECT id FROM ai_tool WHERE name = 'memoryExtract'");
        $stmt->execute();
        $memoryTool = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($memoryTool) {
            $updateStmt = $db->prepare(
                "UPDATE ai_tool_settings SET value = '', updated_at = ? WHERE tool_id = ? AND key IN ('extraction_ai_model', 'relationship_analyzer_ai_model')"
            );
            $updateStmt->execute([$now, $memoryTool['id']]);
        }

        // 2. Reset fetchURL extraction model setting to empty.
        $stmt = $db->prepare("SELECT id FROM ai_tool WHERE name = 'fetchURL'");
        $stmt->execute();
        $fetchTool = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($fetchTool) {
            $updateStmt = $db->prepare(
                "UPDATE ai_tool_settings SET value = '', updated_at = ? WHERE tool_id = ? AND key = 'extraction_ai_model'"
            );
            $updateStmt->execute([$now, $fetchTool['id']]);
        }

        // 3. Remove subconsciousnessAgentAiModel from all spirits.
        $db->prepare("DELETE FROM spirit_settings WHERE key = ?")
            ->execute(['subconsciousnessAgentAiModel']);

        // Note: document_summary_ai_model and document_summary_system_prompt are
        // not restored in down() because their original prompt content is not
        // preserved during the up() migration.
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
