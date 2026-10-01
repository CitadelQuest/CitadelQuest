<?php

/**
 * Migration: Add the createPDF AI Tool.
 *
 * Enables Spirits to render HTML/CSS into a PDF document and save it to the
 * user's File Browser. Two engines:
 *  - dompdf (default): pure PHP, true vector PDF with selectable/searchable text
 *  - chromium: renders through the Obscura headless browser for full modern CSS
 *    fidelity (rasterized output — text is not selectable)
 *
 * This migration:
 *  1. Seeds the createPDF tool (active by default, category "file").
 *  2. Seeds its settings (Obscura binary/timeout + chromium viewport width).
 *  3. Enables createPDF on existing Spirits that have an explicit per-Spirit
 *     activeTools list (Spirits without one inherit the global is_active).
 *
 * Implemented in AIToolPdfService, dispatched via AIToolCallService.
 *
 * @see /docs/features/AI-TOOLS-createPDF.md
 */
class UserMigration_20261001120000
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
        // 1. Seed the tool (no-op if it already exists)
        $this->addTool($db, 'createPDF', $this->description(), $this->parameters(), 1, 'file', 40);

        // 2. Tool settings
        $this->addToolSettings($db, 'createPDF', [
            [
                'key' => 'obscura_binary_path',
                'value' => '/usr/local/bin/obscura',
                'type' => 'text',
                'label' => 'Obscura Binary Path',
                'description' => 'Filesystem path to the obscura binary (used by the chromium engine)',
                'display_order' => 10,
            ],
            [
                'key' => 'obscura_timeout',
                'value' => '60',
                'type' => 'number',
                'label' => 'Chromium Render Timeout (seconds)',
                'description' => 'Maximum time to wait for the chromium engine to render the PDF',
                'display_order' => 20,
            ],
            [
                'key' => 'chromium_viewport_width',
                'value' => '1200',
                'type' => 'number',
                'label' => 'Chromium Viewport Width (px)',
                'description' => 'Viewport width used by the chromium engine. Height is derived to match the page aspect ratio.',
                'display_order' => 30,
            ],
        ]);

        // 3. Enable on existing Spirits with an explicit activeTools list
        $this->syncCreatePdfInActiveTools($db, true);
    }

    public function down(\PDO $db): void
    {
        $this->syncCreatePdfInActiveTools($db, false);

        $stmt = $db->prepare("SELECT id FROM ai_tool WHERE name = 'createPDF'");
        $stmt->execute();
        $tool = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($tool) {
            $del = $db->prepare('DELETE FROM ai_tool_settings WHERE tool_id = ?');
            $del->execute([$tool['id']]);
        }
        $db->exec("DELETE FROM ai_tool WHERE name = 'createPDF'");
    }

    private function description(): string
    {
        return 'Create a PDF document from HTML/CSS and save it to the user\'s File Browser, with a preview and download button shown in the chat. '
            . 'Provide the markup either as an inline `html` string, or as `pathname` pointing to an existing .html file in the File Browser (recommended for large documents — the user can preview and edit the HTML first). '
            . 'Two engines: "dompdf" (default) produces a real PDF with selectable/searchable text and embedded fonts — use it for documents such as reports, letters, invoices, contracts, e-books; it supports CSS 2.1 and partial CSS3 but NOT flexbox/grid, so use tables or float-based layout. '
            . '"chromium" renders with the full modern CSS engine (flexbox, grid, web fonts) for pixel-perfect visual pages, but the output is rasterized — text is NOT selectable and files are larger; use it only when visual fidelity matters more than text. IMPORTANT for chromium: use px units only — physical CSS units (mm/cm/in/pt) are not resolved (a sized element falls back to auto, collapsing to its content height; an empty one disappears). Design for the render canvas reported in the tool result: it is as wide as the chromium viewport (default 1200 px) and as tall as the page ratio requires (≈ 1200 × 1697 px for A4 portrait). '
            . 'The document is saved as application/pdf and can be opened, downloaded and annotated like any other File Browser PDF.';
    }

    private function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'html' => [
                    'type' => 'string',
                    'description' => 'Inline HTML/CSS to render into a PDF. May be a full document or a fragment (a minimal HTML document is added automatically if omitted). Use either `html` or `pathname`, not both.'
                ],
                'pathname' => [
                    'type' => 'string',
                    'description' => 'Path to an existing .html file in the File Browser to render, e.g. "/documents/report.html". Alternative to `html` — better for large documents. Use either `html` or `pathname`, not both.'
                ],
                'filename' => [
                    'type' => 'string',
                    'description' => 'Output PDF filename (optional, default: auto-generated "document_<timestamp>.pdf"). The .pdf extension is added automatically if missing.'
                ],
                'savePath' => [
                    'type' => 'string',
                    'description' => 'Project path to save the PDF (optional, default: /uploads/ai/pdf).'
                ],
                'projectId' => [
                    'type' => 'string',
                    'description' => 'Project ID for file storage (optional, default: general).'
                ],
                'engine' => [
                    'type' => 'string',
                    'enum' => ['dompdf', 'chromium'],
                    'description' => 'Rendering engine (optional, default: dompdf). "dompdf" = selectable vector text, CSS 2.1 + partial CSS3. "chromium" = full modern CSS but rasterized output (no selectable text).'
                ],
                'pageSize' => [
                    'type' => 'string',
                    'description' => 'Page size (optional, default: A4). Common values: A4, A3, A5, Letter, Legal.'
                ],
                'orientation' => [
                    'type' => 'string',
                    'enum' => ['portrait', 'landscape'],
                    'description' => 'Page orientation (optional, default: portrait).'
                ],
                'margin' => [
                    'type' => 'string',
                    'description' => 'Page margin with a CSS unit (optional, default: 15mm), e.g. 15mm, 1in, 20px.'
                ]
            ]
        ];
    }

    /**
     * Add (or remove) the createPDF tool id from every Spirit's activeTools list.
     */
    private function syncCreatePdfInActiveTools(\PDO $db, bool $add): void
    {
        $stmt = $db->prepare("SELECT id FROM ai_tool WHERE name = 'createPDF'");
        $stmt->execute();
        $toolId = $stmt->fetchColumn();

        // Tool not seeded — nothing to sync.
        if (!$toolId) {
            return;
        }

        $rows = $db->query(
            "SELECT id, value FROM spirit_settings WHERE key = 'systemPrompt.config.activeTools'"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $update = $db->prepare('UPDATE spirit_settings SET value = ?, updated_at = ? WHERE id = ?');

        foreach ($rows as $row) {
            $ids = json_decode($row['value'], true);
            if (!is_array($ids)) {
                continue;
            }

            $has = in_array($toolId, $ids, true);

            if ($add && !$has) {
                $ids[] = $toolId;
            } elseif (!$add && $has) {
                $ids = array_values(array_filter($ids, fn($id) => $id !== $toolId));
            } else {
                continue;
            }

            $update->execute([
                json_encode(array_values($ids)),
                date('Y-m-d H:i:s'),
                $row['id'],
            ]);
        }
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
}
