<?php

/**
 * Migration: Add fetchURL AI Tool
 * 
 * This tool enables Spirits to fetch and read content from web URLs.
 * It uses AI post-processing to extract meaningful content from HTML.
 * 
 * @see /docs/features/fetch-url-spirit.md
 */
class UserMigration_20260118050000
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
        // Add fetchURL tool
        $this->addTool($db, 'fetchURL', 
            'Fetch and read content from a web URL. Returns clean, extracted text content from web pages. Use this to research topics, read documentation, get current information (prices, opening hours, news), or access any public web content. Results are cached to avoid repeated fetches.',
            [
                'type' => 'object',
                'properties' => [
                    'url' => [
                        'type' => 'string',
                        'description' => 'The web URL to fetch (must be http:// or https://)'
                    ],
                    'forceRefresh' => [
                        'type' => 'boolean',
                        'description' => 'Force refresh even if cached version exists (optional, default: false)'
                    ],
                    'maxLength' => [
                        'type' => 'integer',
                        'description' => 'Maximum content length to return in characters (optional, default: 50000)'
                    ]
                ],
                'required' => ['url']
            ],
            1 // Active by default
        );
    }
    
    /**
     * Helper method to add a tool if it doesn't exist
     */
    private function addTool(\PDO $db, string $name, string $description, array $parameters, int $isActive = 0): void
    {
        // Check if tool exists
        $stmt = $db->prepare("SELECT id FROM ai_tool WHERE name = ?");
        $stmt->execute([$name]);
        if (!$stmt->fetch(\PDO::FETCH_ASSOC)) {
            // Insert tool
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
        // Remove the fetchURL tool
        $db->exec("DELETE FROM ai_tool WHERE name = 'fetchURL'");
    }
}
