<?php

/**
 * Migration: Add screenshotURL AI Tool + Obscura settings
 *
 * This tool enables Spirits to capture screenshots of web pages using
 * the Obscura headless browser engine (Rust, no Chromium required).
 * Screenshots are saved to the user's File Browser and sent to AI vision
 * via the multimodal tool output pipeline.
 *
 * Also adds Obscura-related settings to fetchURL tool for renderJS mode.
 */
class UserMigration_20260901180000
{
    /**
     * Generate a UUID v4
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public function up(\PDO $db): void
    {
        // 1. Add screenshotURL tool
        $this->addTool($db, 'screenshotURL',
            'Capture a screenshot of a web page. The screenshot is saved to the user\'s File Browser and displayed in the chat. The image is also sent to you as image input so you can see the page visually. Use this to: see what a website looks like, verify visual layout, capture dynamic JavaScript-rendered content, or provide visual context about a web page. For text content extraction, use fetchURL instead. Security: private/internal IP addresses (127.0.0.1, 10.x, 172.16-31.x, 192.168.x) are refused. Capture strategy is automatic: if width × page height ≤ 30M pixels, a single-pass fullPage capture is used; if larger, the page is captured in 4096px vertical segments and stitched; if page height exceeds 32768px (Chromium limit), the screenshot is truncated and reported honestly.',
            [
                'type' => 'object',
                'properties' => [
                    'url' => [
                        'type' => 'string',
                        'description' => 'The web URL to capture a screenshot of (http:// or https://). Private/internal IP addresses are refused for security.'
                    ],
                    'projectId' => [
                        'type' => 'string',
                        'description' => 'Project ID for file storage (optional, default: general)'
                    ],
                    'width' => [
                        'type' => 'integer',
                        'description' => 'Viewport width in pixels (optional, default: 1440, valid range: 320–2560; values outside this range are clamped and reported in the result as requestedWidth/widthNote)'
                    ],
                    'height' => [
                        'type' => 'integer',
                        'description' => 'Viewport height in pixels (optional, default: 1000, valid range: 240–2000; only affects initial viewport, fullPage captures the entire scrollable page)'
                    ],
                    'fullPage' => [
                        'type' => 'boolean',
                        'description' => 'Capture the full scrollable page, not just the viewport (optional, default: true). Tall pages may be truncated at 32768px (Chromium limit) or captured in segments if width × height > 30M pixels.'
                    ],
                    'waitUntil' => [
                        'type' => 'string',
                        'description' => 'Wait condition before screenshot: "load", "domcontentloaded", or "networkidle0" (optional, default: "networkidle0")'
                    ],
                    'savePath' => [
                        'type' => 'string',
                        'description' => 'Project path to save the screenshot (optional, default: /uploads/ai/screenshots)'
                    ],
                    'filename' => [
                        'type' => 'string',
                        'description' => 'Filename for the screenshot (optional, auto-generated if not provided)'
                    ],
                    'forceRefresh' => [
                        'type' => 'boolean',
                        'description' => 'Skip cache and always take a fresh screenshot (optional, default: false)'
                    ]
                ],
                'required' => ['url']
            ],
            1, // Active by default
            'web',
            20 // After fetchURL (order 10)
        );

        // 2. Add screenshotURL tool settings
        $this->addToolSettings($db, 'screenshotURL', [
            [
                'key' => 'obscura_binary_path',
                'value' => '/usr/local/bin/obscura',
                'type' => 'text',
                'label' => 'Obscura Binary Path',
                'description' => 'Filesystem path to the obscura binary',
                'display_order' => 10,
            ],
            [
                'key' => 'obscura_timeout',
                'value' => '30',
                'type' => 'number',
                'label' => 'Obscura Timeout (seconds)',
                'description' => 'Maximum time to wait for page load and screenshot capture',
                'display_order' => 20,
            ],
        ]);

        // 3. Add renderJS parameter to fetchURL tool
        $stmt = $db->prepare("SELECT id, parameters FROM ai_tool WHERE name = 'fetchURL'");
        $stmt->execute();
        $tool = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($tool) {
            $parameters = json_decode($tool['parameters'], true);
            if (!isset($parameters['properties']['renderJS'])) {
                $parameters['properties']['renderJS'] = [
                    'type' => 'boolean',
                    'description' => 'When true, uses Obscura headless browser to render JavaScript and extract content. Required for SPAs (React, Vue, Angular) and JavaScript-heavy pages. Falls back to basic HTTP fetch if Obscura is not installed. (optional, default: false)'
                ];
                $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, updated_at = ? WHERE id = ?");
                $stmt->execute([json_encode($parameters), date('Y-m-d H:i:s'), $tool['id']]);
            }
        }

        // 4. Add Obscura settings to fetchURL tool as well
        $this->addToolSettings($db, 'fetchURL', [
            [
                'key' => 'obscura_binary_path',
                'value' => '/usr/local/bin/obscura',
                'type' => 'text',
                'label' => 'Obscura Binary Path',
                'description' => 'Filesystem path to the obscura binary (used for renderJS mode)',
                'display_order' => 30,
            ],
            [
                'key' => 'obscura_timeout',
                'value' => '30',
                'type' => 'number',
                'label' => 'Obscura Timeout (seconds)',
                'description' => 'Maximum time to wait for page render in renderJS mode',
                'display_order' => 40,
            ],
        ]);
    }

    /**
     * Helper method to add a tool if it doesn't exist
     */
    private function addTool(\PDO $db, string $name, string $description, array $parameters, int $isActive = 0, string $category = 'general', int $displayOrder = 0): void
    {
        $stmt = $db->prepare("SELECT id FROM ai_tool WHERE name = ?");
        $stmt->execute([$name]);
        if (!$stmt->fetch(\PDO::FETCH_ASSOC)) {
            $stmt = $db->prepare(
                'INSERT INTO ai_tool (id, name, description, parameters, is_active, category, display_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $this->generateUuid(),
                $name,
                $description,
                json_encode($parameters),
                $isActive,
                $category,
                $displayOrder,
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s')
            ]);
        }
    }

    /**
     * Helper method to add tool settings if they don't exist
     */
    private function addToolSettings(\PDO $db, string $toolName, array $settings): void
    {
        $stmt = $db->prepare("SELECT id FROM ai_tool WHERE name = ?");
        $stmt->execute([$toolName]);
        $tool = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$tool) {
            return;
        }

        $toolId = $tool['id'];

        foreach ($settings as $setting) {
            // Check if setting already exists
            $checkStmt = $db->prepare("SELECT id FROM ai_tool_settings WHERE tool_id = ? AND key = ?");
            $checkStmt->execute([$toolId, $setting['key']]);
            if (!$checkStmt->fetch(\PDO::FETCH_ASSOC)) {
                $insertStmt = $db->prepare(
                    'INSERT INTO ai_tool_settings (id, tool_id, key, value, type, label, description, display_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $insertStmt->execute([
                    $this->generateUuid(),
                    $toolId,
                    $setting['key'],
                    $setting['value'],
                    $setting['type'],
                    $setting['label'],
                    $setting['description'],
                    $setting['display_order'],
                    date('Y-m-d H:i:s'),
                    date('Y-m-d H:i:s')
                ]);
            }
        }
    }

    public function down(\PDO $db): void
    {
        // Remove screenshotURL tool and its settings
        $stmt = $db->prepare("SELECT id FROM ai_tool WHERE name = 'screenshotURL'");
        $stmt->execute();
        $tool = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($tool) {
            $delStmt = $db->prepare("DELETE FROM ai_tool_settings WHERE tool_id = ?");
            $delStmt->execute([$tool['id']]);
        }
        $db->exec("DELETE FROM ai_tool WHERE name = 'screenshotURL'");

        // Remove renderJS parameter from fetchURL
        $stmt = $db->prepare("SELECT id, parameters FROM ai_tool WHERE name = 'fetchURL'");
        $stmt->execute();
        $tool = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($tool) {
            $parameters = json_decode($tool['parameters'], true);
            unset($parameters['properties']['renderJS']);
            $stmt = $db->prepare("UPDATE ai_tool SET parameters = ? WHERE id = ?");
            $stmt->execute([json_encode($parameters), $tool['id']]);
        }

        // Remove Obscura settings from fetchURL
        $stmt = $db->prepare("SELECT id FROM ai_tool WHERE name = 'fetchURL'");
        $stmt->execute();
        $tool = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($tool) {
            $delStmt = $db->prepare("DELETE FROM ai_tool_settings WHERE tool_id = ? AND key IN ('obscura_binary_path', 'obscura_timeout')");
            $delStmt->execute([$tool['id']]);
        }
    }
}
