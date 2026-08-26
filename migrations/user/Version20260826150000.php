<?php

/**
 * Migration: Seed default ai_tool_settings for spiritCall tool (S2S safeguards).
 *
 * Moves the previously hardcoded Spirit-to-Spirit safety limits into
 * user-configurable AI Tool Settings (Settings -> AI Tools -> spiritCall cog):
 * - s2s.maxCallsPerTurn:         max spiritCall consultations per top-level turn (was SpiritCallContext::MAX_CALLS_PER_TURN = 5)
 * - s2s.maxDepth:                max nested consultation depth (was SpiritCallContext::MAX_DEPTH = 2)
 * - s2s.calleeMaxOutputFallback: callee max output tokens when its model exposes no limit (was CALLEE_MAX_OUTPUT_FALLBACK = 8192)
 * - s2s.calleeTemperature:       callee turn temperature (was CALLEE_TEMPERATURE = 0.7)
 * - s2s.calleeToolTemperature:   callee tool-loop temperature (was CALLEE_TOOL_TEMPERATURE = 0.5)
 *
 * Empty/invalid values fall back to the code defaults.
 */
class UserMigration_20260826150000
{
    public function up(\PDO $db): void
    {
        $stmt = $db->prepare('SELECT id FROM ai_tool WHERE name = ?');
        $stmt->execute(['spiritCall']);
        $tool = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$tool) {
            return; // spiritCall tool not found, skip
        }

        $this->seedSettings($db, $tool['id'], [
            ['s2s.maxCallsPerTurn', '5', 'number', 'Max Consultations per Turn', 'Safety budget: maximum number of spiritCall consultations allowed within a single chat turn (all nested calls combined). Default: 5.', 10],
            ['s2s.maxDepth', '2', 'number', 'Max Consultation Depth', 'Safety limit: how deep nested Spirit-to-Spirit consultations may go (A -> B -> C = depth 2). Default: 2.', 20],
            ['s2s.calleeMaxOutputFallback', '8192', 'number', 'Callee Max Output Fallback (tokens)', 'Max output tokens for the consulted Spirit when its AI model does not expose a max-output capacity. Default: 8192.', 30],
            ['s2s.calleeTemperature', '0.7', 'number', 'Callee Temperature', 'Temperature for the consulted Spirit\'s main AI responses. Default: 0.7.', 40],
            ['s2s.calleeToolTemperature', '0.5', 'number', 'Callee Tool Temperature', 'Temperature for the consulted Spirit\'s responses while it is executing tools. Default: 0.5.', 50],
        ]);
    }

    public function down(\PDO $db): void
    {
        $stmt = $db->prepare('SELECT id FROM ai_tool WHERE name = ?');
        $stmt->execute(['spiritCall']);
        $tool = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$tool) {
            return;
        }

        $db->prepare("DELETE FROM ai_tool_settings WHERE tool_id = ? AND key IN ('s2s.maxCallsPerTurn', 's2s.maxDepth', 's2s.calleeMaxOutputFallback', 's2s.calleeTemperature', 's2s.calleeToolTemperature')")
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
