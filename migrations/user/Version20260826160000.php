<?php

/**
 * Migration: Seed default ai_tool_settings for spiritCreateOrEditImage tool.
 *
 * Makes the image generation/editing AI model user-configurable per-tool
 * (Settings -> AI Tools -> spiritCreateOrEditImage cog):
 * - image_ai_model: AI model used for image creation/editing (type: aiModel)
 *
 * Empty value keeps the existing fallback chain:
 * ai.secondary_ai_service_model_id (Settings -> AI) -> citadelquest/gemini-2.5-flash-image.
 */
class UserMigration_20260826160000
{
    public function up(\PDO $db): void
    {
        $stmt = $db->prepare('SELECT id FROM ai_tool WHERE name = ?');
        $stmt->execute(['spiritCreateOrEditImage']);
        $tool = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$tool) {
            return; // spiritCreateOrEditImage tool not found, skip
        }

        $this->seedSettings($db, $tool['id'], [
            ['image_ai_model', '', 'aiModel', 'Image Editor AI Model', 'AI model used for image creation and editing. Leave empty to use the secondary AI model from Settings -> AI (or the built-in default).', 10],
        ]);
    }

    public function down(\PDO $db): void
    {
        $stmt = $db->prepare('SELECT id FROM ai_tool WHERE name = ?');
        $stmt->execute(['spiritCreateOrEditImage']);
        $tool = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$tool) {
            return;
        }

        $db->prepare("DELETE FROM ai_tool_settings WHERE tool_id = ? AND key IN ('image_ai_model')")
           ->execute([$tool['id']]);
    }

    private function seedSettings(\PDO $db, string $toolId, array $settings): void
    {
        foreach ($settings as [$key, $value, $type, $label, $description, $displayOrder]) {
            $stmt = $db->prepare('SELECT id FROM ai_tool_settings WHERE tool_id = ? AND key = ?');
            $stmt->execute([$toolId, $key]);
            if ($stmt->fetch(\PDO::FETCH_ASSOC)) {
                continue;
            }
            $stmt = $db->prepare(
                'INSERT INTO ai_tool_settings (id, tool_id, key, value, type, label, description, display_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $this->generateUuid(), $toolId, $key, $value, $type, $label, $description, $displayOrder,
                date('Y-m-d H:i:s'), date('Y-m-d H:i:s')
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
