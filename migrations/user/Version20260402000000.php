<?php

/**
 * Migration: Add downloadPath and downloadFilename parameters to fetchURL AI Tool
 * 
 * When both are provided, fetchURL fetches the URL and saves the raw content
 * as a file via ProjectFileService instead of returning text content.
 * Especially useful for downloading images and binary files.
 */
class UserMigration_20260402000000
{
    public function up(\PDO $db): void
    {
        $stmt = $db->prepare("SELECT id, parameters, description FROM ai_tool WHERE name = 'fetchURL'");
        $stmt->execute();
        $tool = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$tool) {
            return;
        }

        $parameters = json_decode($tool['parameters'], true);

        $parameters['properties']['downloadPath'] = [
            'type' => 'string',
            'description' => 'Optional. Directory path where the file should be saved (e.g. "/profile", "/images"). When set together with downloadFilename, the URL content is downloaded and saved as a file instead of returning text content.',
        ];
        $parameters['properties']['downloadFilename'] = [
            'type' => 'string',
            'description' => 'Optional. Filename for the downloaded file (e.g. "photo.jpg", "document.pdf"). Required together with downloadPath.',
        ];

        $newDescription = 'Fetch and read content from a web URL. Returns clean, extracted text content from web pages. Use this to research topics, read documentation, get current information (prices, opening hours, news), or access any public web content. Search the web using `https://search.brave.com/search?q={query}`. Results are cached to avoid repeated fetches. Use resultFormat: "raw-html-code" to get pure raw HTML source code (no cleanup, no AI extraction, no caching, no length limit). To download a file (image, PDF, etc.) and save it, set downloadPath + downloadFilename — the file will be fetched and saved to the user\'s File Browser.';

        $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, description = ?, updated_at = ? WHERE id = ?");
        $stmt->execute([
            json_encode($parameters),
            $newDescription,
            date('Y-m-d H:i:s'),
            $tool['id']
        ]);
    }

    public function down(\PDO $db): void
    {
        $stmt = $db->prepare("SELECT id, parameters FROM ai_tool WHERE name = 'fetchURL'");
        $stmt->execute();
        $tool = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$tool) {
            return;
        }

        $parameters = json_decode($tool['parameters'], true);
        unset($parameters['properties']['downloadPath']);
        unset($parameters['properties']['downloadFilename']);

        $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, description = ?, updated_at = ? WHERE id = ?");
        $stmt->execute([
            json_encode($parameters),
            'Fetch and read content from a web URL. Returns clean, extracted text content from web pages. Use this to research topics, read documentation, get current information (prices, opening hours, news), or access any public web content. Search the web using `https://search.brave.com/search?q={query}`. Results are cached to avoid repeated fetches. Use resultFormat: "raw-html-code" to get pure raw HTML source code (no cleanup, no AI extraction, no caching, no length limit).',
            date('Y-m-d H:i:s'),
            $tool['id']
        ]);
    }
}
