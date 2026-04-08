<?php

/**
 * Migration: Seed default ai_tool_settings for fetchURL tool
 * 
 * Adds two configurable settings:
 * - extraction_ai_model: AI model used for content extraction (type: aiModel)
 * - extraction_system_prompt: System prompt for content extraction (type: textarea)
 */
class UserMigration_20260407230000
{
    public function up(\PDO $db): void
    {
        // Find the fetchURL tool
        $stmt = $db->prepare("SELECT id FROM ai_tool WHERE name = 'fetchURL'");
        $stmt->execute();
        $tool = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$tool) {
            return; // fetchURL tool not found, skip
        }

        $toolId = $tool['id'];
        $now = date('Y-m-d H:i:s');

        $defaultSystemPrompt = 'You are a web content extractor. Your task is to extract the meaningful main content from a web page and return it in clean, readable format.

## Instructions:
1. Extract ONLY the main article/content - ignore navigation, ads, footers, sidebars, cookie notices
2. Preserve the content structure (headings, lists, paragraphs)
3. Convert to clean Markdown format
4. Keep important links if they\'re part of the content or navigation/sitemap (format as [text](url))
5. Remove all tracking parameters from URLs
6. If the page is a list/index, extract the list items with their descriptions
7. For product pages: extract name, price, description, availability
8. For articles: extract title, author (if visible), date (if visible), main content
9. For documentation: preserve code examples and technical details
10. Maximum output: 50000 characters

## Output Format:
Return ONLY the extracted content in Markdown format. No explanations, no meta-commentary, no "Here is the extracted content:" prefix. Just the clean content.

## Important:
- If the content is in a non-English language, keep it in that language
- Preserve any important numbers, dates, prices, contact information
- If the page appears to be an error page or login wall, state that briefly

## Data Integrity (CRITICAL):
- NEVER fabricate, guess, or hallucinate any information. Every piece of data in your output MUST come directly from the source HTML content.
- Email addresses are often obfuscated by Cloudflare protection (e.g. `[email protected]`). If the actual email address is not visible in the source, do NOT invent one. Instead write: `[email protected]` or omit it entirely. Wrong data is worse than no data.
- Same applies to phone numbers, prices, names, or any other specific data — if it\'s not clearly present in the source, do not make it up.';

        // Insert extraction AI model setting (empty = use default fast model logic)
        $stmt = $db->prepare(
            'INSERT INTO ai_tool_settings (id, tool_id, key, value, type, label, description, display_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        // Setting 1: AI Content Extraction Model
        $stmt->execute([
            $this->generateUuid(),
            $toolId,
            'extraction_ai_model',
            '', // empty = use default fast model fallback
            'aiModel',
            'AI Content Extraction Model',
            'AI model used for extracting clean content from web pages. Leave empty to use the default fast model.',
            10,
            $now,
            $now
        ]);

        // Setting 2: Extraction System Prompt
        $stmt->execute([
            $this->generateUuid(),
            $toolId,
            'extraction_system_prompt',
            $defaultSystemPrompt,
            'textarea',
            'Content Extraction System Prompt',
            'System prompt sent to the AI model when extracting content from fetched web pages.',
            20,
            $now,
            $now
        ]);
    }

    public function down(\PDO $db): void
    {
        $stmt = $db->prepare("SELECT id FROM ai_tool WHERE name = 'fetchURL'");
        $stmt->execute();
        $tool = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$tool) {
            return;
        }

        $db->prepare("DELETE FROM ai_tool_settings WHERE tool_id = ? AND key IN ('extraction_ai_model', 'extraction_system_prompt')")
           ->execute([$tool['id']]);
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
};
