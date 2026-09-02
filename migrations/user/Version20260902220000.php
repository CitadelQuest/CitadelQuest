<?php

/**
 * Migration: Update screenshotURL tool schema with SSRF policy, valid ranges,
 * and three-strategy capture routing documentation.
 *
 * Existing installations already have the tool from Version20260901180000,
 * but the original migration skips if the tool exists. This migration
 * updates the description and parameter descriptions in place.
 */
class UserMigration_20260902220000
{
    public function up(\PDO $db): void
    {
        $stmt = $db->prepare("SELECT id, parameters FROM ai_tool WHERE name = 'screenshotURL'");
        $stmt->execute();
        $tool = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$tool) {
            return;
        }

        $newDescription = 'Capture a screenshot of a web page. The screenshot is saved to the user\'s File Browser and displayed in the chat. The image is also sent to you as image input so you can see the page visually. Use this to: see what a website looks like, verify visual layout, capture dynamic JavaScript-rendered content, or provide visual context about a web page. For text content extraction, use fetchURL instead. Security: private/internal IP addresses (127.0.0.1, 10.x, 172.16-31.x, 192.168.x) are refused. Capture strategy is automatic: if width × page height ≤ 30M pixels, a single-pass fullPage capture is used; if larger, the page is captured in 4096px vertical segments and stitched; if page height exceeds 32768px (Chromium limit), the screenshot is truncated and reported honestly.';

        $parameters = json_decode($tool['parameters'], true);
        if (!is_array($parameters)) {
            $parameters = ['type' => 'object', 'properties' => [], 'required' => ['url']];
        }

        $updates = [
            'url' => 'The web URL to capture a screenshot of (http:// or https://). Private/internal IP addresses are refused for security.',
            'width' => 'Viewport width in pixels (optional, default: 1440, valid range: 320–2560; values outside this range are clamped and reported in the result as requestedWidth/widthNote)',
            'height' => 'Viewport height in pixels (optional, default: 1000, valid range: 240–2000; only affects initial viewport, fullPage captures the entire scrollable page)',
            'fullPage' => 'Capture the full scrollable page, not just the viewport (optional, default: true). Tall pages may be truncated at 32768px (Chromium limit) or captured in segments if width × height > 30M pixels.',
        ];

        foreach ($updates as $paramName => $newDesc) {
            if (isset($parameters['properties'][$paramName])) {
                $parameters['properties'][$paramName]['description'] = $newDesc;
            }
        }

        $stmt = $db->prepare("UPDATE ai_tool SET description = ?, parameters = ?, updated_at = ? WHERE id = ?");
        $stmt->execute([
            $newDescription,
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
