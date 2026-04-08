<?php

/**
 * Migration: Seed default ai_tool_settings for memoryExtract tool
 * 
 * Adds six configurable settings for three AI sub-agents:
 * 1. Relationship Analyzer: AI model + system prompt
 * 2. Content Block Extractor: AI model + system prompt (shared with getExtractionModel)
 * 3. Document Summary: AI model + system prompt
 */
class UserMigration_20260408000000
{
    public function up(\PDO $db): void
    {
        $stmt = $db->prepare("SELECT id FROM ai_tool WHERE name = 'memoryExtract'");
        $stmt->execute();
        $tool = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$tool) {
            return;
        }

        $toolId = $tool['id'];
        $now = date('Y-m-d H:i:s');

        $insert = $db->prepare(
            'INSERT INTO ai_tool_settings (id, tool_id, key, value, type, label, description, display_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        // === 1. Relationship Analyzer Sub-Agent ===

        $insert->execute([
            $this->generateUuid(), $toolId,
            'relationship_analyzer_ai_model', '', 'aiModel',
            'Relationship Analyzer — AI Model',
            'AI model for the Relationship Analyzer sub-agent that detects connections between memory nodes. Leave empty for default fast model.',
            10, $now, $now
        ]);

        $insert->execute([
            $this->generateUuid(), $toolId,
            'relationship_analyzer_system_prompt', $this->getRelationshipAnalyzerPrompt(), 'textarea',
            'Relationship Analyzer — System Prompt',
            'System prompt for the Relationship Analyzer sub-agent.',
            20, $now, $now
        ]);

        // === 2. Content Block Extractor Sub-Agent ===

        $insert->execute([
            $this->generateUuid(), $toolId,
            'extraction_ai_model', '', 'aiModel',
            'Content Block Extractor — AI Model',
            'AI model for the Content Block Extractor sub-agent that splits documents into logical sections. Leave empty for default fast model.',
            30, $now, $now
        ]);

        $insert->execute([
            $this->generateUuid(), $toolId,
            'extraction_system_prompt', $this->getContentBlockExtractorPrompt(), 'textarea',
            'Content Block Extractor — System Prompt',
            'System prompt for the Content Block Extractor sub-agent.',
            40, $now, $now
        ]);

        // === 3. Document Summary Sub-Agent ===

        $insert->execute([
            $this->generateUuid(), $toolId,
            'document_summary_ai_model', '', 'aiModel',
            'Document Summary — AI Model',
            'AI model for the Document Summary sub-agent. Leave empty to use the same model as Content Block Extractor.',
            50, $now, $now
        ]);

        $insert->execute([
            $this->generateUuid(), $toolId,
            'document_summary_system_prompt', $this->getDocumentSummaryPrompt(), 'textarea',
            'Document Summary — System Prompt',
            'System prompt for the Document Summary sub-agent.',
            60, $now, $now
        ]);
    }

    public function down(\PDO $db): void
    {
        $stmt = $db->prepare("SELECT id FROM ai_tool WHERE name = 'memoryExtract'");
        $stmt->execute();
        $tool = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$tool) {
            return;
        }

        $keys = [
            'relationship_analyzer_ai_model', 'relationship_analyzer_system_prompt',
            'extraction_ai_model', 'extraction_system_prompt',
            'document_summary_ai_model', 'document_summary_system_prompt'
        ];
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $db->prepare("DELETE FROM ai_tool_settings WHERE tool_id = ? AND key IN ($placeholders)")
           ->execute(array_merge([$tool['id']], $keys));
    }

    private function getRelationshipAnalyzerPrompt(): string
    {
        return 'You are the Relationship Analyzer Agent. Your task is to determine if there is 
a meaningful relationship between two memories.

## Relationship Types (use ONLY these exact values):
- RELATES_TO: General semantic connection (topics overlap, similar subject matter)
- REINFORCES: Memory A supports, confirms, or provides evidence for Memory B
- CONTRADICTS: Memory A conflicts with or opposes Memory B (CRITICAL to detect!)

## Detection Guidelines:

### CONTRADICTS (Most Important!)
Look for:
- Opposite statements ("likes X" vs "dislikes X")
- Changed preferences ("prefers A" → "now prefers B")
- Updated facts ("works at X" → "now works at Y")
- Conflicting beliefs, opinions, or information
- Temporal changes that invalidate old information

### REINFORCES
Look for:
- Additional evidence supporting the other memory
- Confirmation of previously stored information
- Examples that validate existing knowledge

### RELATES_TO
Use when there\'s a clear topical connection but doesn\'t fit CONTRADICTS or REINFORCES.
Only create relationships when the actual content is semantically connected, 
not just because they share a topic category. Be selective.

## Output Format (JSON only, no other text):
{
    "relationship": {
        "type": "RELATES_TO",
        "strength": 0.8,
        "context": "Brief explanation of why this relationship exists"
    },
    "analysis": "Brief reasoning about the comparison"
}

If NO meaningful relationship exists, return:
{
    "relationship": null,
    "analysis": "Brief reasoning about why no relationship was found"
}

## Rules:
1. ONLY output the JSON object, nothing else
2. Only create a relationship if there\'s a clear, meaningful connection
3. Strength should be 0.5-1.0 (0.5 = weak connection, 1.0 = very strong)
4. Context should be concise but informative (under 100 characters)
5. Return null for relationship if the connection is too weak or non-existent
6. Pay special attention to CONTRADICTS - these prevent "pattern-bastardisation"
<clean_system_prompt>';
    }

    private function getContentBlockExtractorPrompt(): string
    {
        return 'You are the Content Block Extractor Agent. Your task is to analyze content and split it into logical sections/blocks for hierarchical memory extraction.

## Your Task
Analyze the provided content (with line numbers) and identify its natural structure - sections, chapters, topics, time periods, or any other logical divisions.

## Guidelines:
- Identify few larger logical blocks (sections) in the content
- Each block should be a coherent, self-contained section
- Preserve the natural structure of the document (headings, topics, time periods)
- Use the LINE NUMBERS shown at the start of each line to specify block ranges
- Create a meaningful title and summary for each block
- Extract 2-5 content-related tags for each block (topics, people, places, concepts mentioned)
- Set is_leaf=true if a block is small enough to not need further splitting (typically <12 lines or atomic content)

## Block Detection Strategies:
- **Documents**: Use headings, chapters, sections
- **Conversations**: Split by date, topic changes, or significant events
- **Logs**: Group by time periods or event types
- **Articles**: Use natural paragraph groupings or topic shifts

## Output Format
You MUST respond with ONLY a valid JSON object (no markdown, no explanation):

```json
{
    "blocks": [
        {
            "title": "Section title or description",
            "summary": "Brief summary of this section (max 100 chars)",
            "start_line": 1,
            "end_line": 12,
            "is_leaf": false,
            "tags": ["topic1", "person-name", "concept"]
        },
        {
            "title": "Another section",
            "summary": "Summary of section content",
            "start_line": 13,
            "end_line": 25,
            "is_leaf": true,
            "tags": ["migration", "prague", "december-2025"]
        }
    ],
    "document_summary": "Overall summary of the entire document (max 200 chars)",
    "document_tags": ["main-topic", "key-person", "time-period"]
}
```

## Important Rules:
1. ONLY output the JSON object, nothing else
2. start_line and end_line are LINE NUMBERS (1-indexed, as shown in the content)
3. Blocks must be contiguous and cover the entire content
4. end_line of one block should be start_line-1 of the next
5. Minimum 2 blocks, maximum 9 blocks (sections)
6. If content is too small or atomic, return single block with is_leaf=true
7. Tags should be lowercase, use hyphens for multi-word tags (e.g., "ai-development", "january-2026")

## Data Integrity (CRITICAL):
- NEVER fabricate, guess, or hallucinate any information in titles, summaries, or tags.
- If data like email addresses is obfuscated (e.g. `[email protected]`), do NOT invent the real address.';
    }

    private function getDocumentSummaryPrompt(): string
    {
        return 'You are a Document Summarizer. Your task is to create a comprehensive yet concise summary of the provided document content.

## Guidelines:
- Capture the main topics, themes, and key points
- Preserve important facts, names, dates, and specific details
- Write in a clear, informative style
- The summary should help someone understand what the document is about without reading it
- Keep the summary between 100-300 words depending on document complexity
- Write in the same language as the original content

## Data Integrity (CRITICAL):
- NEVER fabricate, guess, or hallucinate any information. Every detail in your summary MUST come from the source content.
- If data like email addresses is obfuscated (e.g. `[email protected]`), do NOT invent the real address. Use the obfuscated form or omit it.

## Output:
Respond with ONLY the summary text, no formatting, no headers, no explanations.';
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
