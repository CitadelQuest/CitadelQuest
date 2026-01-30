<?php

/**
 * Migration: Spirit Memory v3 - Add memoryExtract AI Tool
 * 
 * Adds the memoryExtract tool which uses a Sub-Agent AI to extract
 * discrete memory nodes from raw content (conversations, documents,
 * legacy .md memory files, etc.)
 * 
 * Features:
 * - Auto-load content from sourceType+sourceRef (saves an AI tool call!)
 * - Duplicate prevention: won't re-extract same source unless force=true
 * 
 * @see /docs/features/spirit-memory-v3.md
 */
class UserMigration_20260129100000
{
    public function up(\PDO $db): void
    {
        // memoryExtract tool - Sub-Agent for extracting memories from content
        $this->addTool($db, 'memoryExtract',
            'Extract and store memories from content using AI analysis. Can auto-load content from files or conversations - no need to call getFileContent first! Prevents duplicate extraction of same source. Use for: migrating legacy .md memory files, processing conversation transcripts, extracting knowledge from documents.',
            [
                'type' => 'object',
                'properties' => [
                    'content' => [
                        'type' => 'string',
                        'description' => 'Raw content to extract memories from. OPTIONAL if sourceType+sourceRef provided (content will be auto-loaded).'
                    ],
                    'sourceType' => [
                        'type' => 'string',
                        'enum' => ['document', 'legacy_memory', 'spirit_conversation', 'url', 'derived'],
                        'description' => 'Type of source. For auto-loading: document/legacy_memory (files), spirit_conversation (chat history). Default: derived'
                    ],
                    'sourceRef' => [
                        'type' => 'string',
                        'description' => 'Reference to source. Format depends on sourceType: for document/legacy_memory use "projectId:path:filename" (e.g., "general:/spirit/Bilbo/memory:conversations.md"), for spirit_conversation use conversation ID.'
                    ],
                    'context' => [
                        'type' => 'string',
                        'description' => 'Additional context about the content to help with extraction (optional)'
                    ],
                    'force' => [
                        'type' => 'boolean',
                        'description' => 'Force re-extraction even if this source was already processed (optional, default: false)'
                    ]
                ],
                'required' => []
            ],
            1 // Active by default
        );
    }

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

    /**
     * Helper method to add a tool if it doesn't exist
     */
    private function addTool(\PDO $db, string $name, string $description, array $parameters, int $isActive = 0): void
    {
        $stmt = $db->prepare("SELECT id FROM ai_tool WHERE name = ?");
        $stmt->execute([$name]);
        if (!$stmt->fetch(\PDO::FETCH_ASSOC)) {
            $stmt = $db->prepare(
                'INSERT INTO ai_tool (id, name, description, parameters, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $this->generateUuid(),
                $name,
                $description,
                json_encode($parameters),
                $isActive,
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s')
            ]);
        }
    }

    public function down(\PDO $db): void
    {
        $db->exec("DELETE FROM ai_tool WHERE name = 'memoryExtract'");
    }
}
