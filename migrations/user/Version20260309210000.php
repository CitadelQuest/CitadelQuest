<?php

/**
 * Migration: Add resultFormat parameter to fetchURL AI Tool
 * 
 * Adds 'resultFormat' option: 'AI-friendly-content' (default) or 'raw-html-code'.
 * When 'raw-html-code', the tool returns pure raw HTML without cleanup or AI extraction.
 */
class UserMigration_20260309210000
{
    public function up(\PDO $db): void
    {
        // Get current fetchURL tool
        $stmt = $db->prepare("SELECT id, parameters FROM ai_tool WHERE name = 'fetchURL'");
        $stmt->execute();
        $tool = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$tool) {
            return; // fetchURL tool not found, skip
        }
        
        $parameters = json_decode($tool['parameters'], true);
        
        // Add resultFormat parameter
        $parameters['properties']['resultFormat'] = [
            'type' => 'string',
            'enum' => ['AI-friendly-content', 'raw-html-code'],
            'description' => "Result format: 'AI-friendly-content' (default) returns clean AI-extracted content. 'raw-html-code' returns pure raw HTML source code without any cleanup or AI processing."
        ];
        
        // Update tool parameters and description
        $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, description = ?, updated_at = ? WHERE id = ?");
        $stmt->execute([
            json_encode($parameters),
            'Fetch and read content from a web URL. Returns clean, extracted text content from web pages. Use this to research topics, read documentation, get current information (prices, opening hours, news), or access any public web content. Search the web using `https://search.brave.com/search?q={query}`. Results are cached to avoid repeated fetches. Use resultFormat: "raw-html-code" to get pure raw HTML source code (no cleanup, no AI extraction, no caching, no length limit).',
            date('Y-m-d H:i:s'),
            $tool['id']
        ]);
    }

    public function down(\PDO $db): void
    {
        // Remove resultFormat from parameters
        $stmt = $db->prepare("SELECT id, parameters FROM ai_tool WHERE name = 'fetchURL'");
        $stmt->execute();
        $tool = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$tool) {
            return;
        }
        
        $parameters = json_decode($tool['parameters'], true);
        unset($parameters['properties']['resultFormat']);
        
        $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, description = ?, updated_at = ? WHERE id = ?");
        $stmt->execute([
            json_encode($parameters),
            'Fetch and read content from a web URL. Returns clean, extracted text content from web pages. Use this to research topics, read documentation, get current information (prices, opening hours, news), or access any public web content. Search the web using `https://search.brave.com/search?q={query}`. Results are cached to avoid repeated fetches.',
            date('Y-m-d H:i:s'),
            $tool['id']
        ]);
    }
}
