<?php

namespace App\Service;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\CitadelVersion;

/**
 * Service for AI Tool web operations
 * 
 * Implements the fetchURL tool which:
 * 1. Fetches web content with safety limits
 * 2. Performs basic HTML cleanup
 * 3. Uses AI to extract meaningful content
 * 4. Caches results in .anno format
 * 
 * @see /docs/features/fetch-url-spirit.md
 */
class AIToolWebService
{
    private const MAX_CONTENT_SIZE = 1024 * 1024; // 1MB
    private const HTTP_TIMEOUT = 15; // seconds
    private const MAX_REDIRECTS = 5;
    private const CACHE_DURATION_HOURS = 24;
    private const MAX_OUTPUT_LENGTH = 50000; // characters

    public function __construct(
        private readonly ProjectFileService $projectFileService,
        private readonly Security $security,
        private readonly AiGatewayService $aiGatewayService,
        private readonly AiServiceRequestService $aiServiceRequestService,
        private readonly AiServiceModelService $aiServiceModelService,
        private readonly HttpClientInterface $httpClient,
        private readonly AnnoService $annoService
    ) {
    }

    /**
     * Fetch and extract content from a URL
     * 
     * @param array $arguments Tool arguments:
     *   - url: string (required) - The web URL to fetch
     *   - forceRefresh: bool (optional) - Force refresh even if cached
     *   - maxLength: int (optional) - Maximum content length to return
     * 
     * @return array Tool result with success status and content
     */
    public function fetchURL(array $arguments): array
    {
        $this->validateArguments($arguments, ['url']);
        
        $url = $arguments['url'];
        $forceRefresh = $arguments['forceRefresh'] ?? false;
        $maxLength = $arguments['maxLength'] ?? self::MAX_OUTPUT_LENGTH;
        $projectId = $arguments['projectId'] ?? 'general';
        $lang = $arguments['lang'] ?? 'English';
        
        // Validate URL
        if (!$this->isValidUrl($url)) {
            return [
                'success' => false,
                'error' => 'Invalid URL. Must be a valid http:// or https:// URL.'
            ];
        }
        
        try {
            // Check cache first (unless force refresh)
            if (!$forceRefresh) {
                $cached = $this->checkCache($url, $projectId);
                if ($cached) {
                    return [
                        'success' => true,
                        'url' => $url,
                        'title' => $cached['title'] ?? '',
                        'content' => $this->truncateContent($cached['content'], $maxLength),
                        'cached' => true,
                        'fetched_at' => $cached['fetched_at'] ?? null,
                        '_frontendData' => $this->buildFrontendData($url, $cached['title'] ?? '', true)
                    ];
                }
            }
            
            // Fetch the URL
            $fetchResult = $this->fetchWithLimits($url);
            if (!$fetchResult['success']) {
                return $fetchResult;
            }
            
            $html = $fetchResult['content'];
            $finalUrl = $fetchResult['url']; // May differ after redirects
            
            // Basic HTML cleanup and metadata extraction
            $cleaned = $this->basicHtmlCleanup($html);
            
            // AI post-processing to extract meaningful content
            $extractedContent = $this->aiExtractContent(
                $finalUrl,
                $cleaned['title'],
                $cleaned['description'],
                $cleaned['content'],
                $lang,
                $projectId
            );
            
            // Get usage stats for display
            $totalTokens = $extractedContent['totalTokens'] ?? null;
            $totalCost = $extractedContent['totalCost'] ?? null;
            
            if (!$extractedContent['success']) {
                // If AI extraction fails, return the basic cleaned content
                $extractedContent = [
                    'success' => true,
                    'content' => $cleaned['content']
                ];
            }
            
            // Save to cache
            $this->saveToCache($url, $projectId, [
                'title' => $cleaned['title'],
                'description' => $cleaned['description'],
                'content' => $extractedContent['content'],
                'fetched_at' => (new \DateTime())->format('c')
            ]);
            
            return [
                'success' => true,
                'url' => $finalUrl,
                'title' => $cleaned['title'],
                'content' => $this->truncateContent($extractedContent['content'], $maxLength),
                'cached' => false,
                'fetched_at' => (new \DateTime())->format('c'),
                '_frontendData' => $this->buildFrontendData($finalUrl, $cleaned['title'], false, $totalTokens, $totalCost)
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to fetch URL: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Validate URL format and protocol
     */
    private function isValidUrl(string $url): bool
    {
        $parsed = parse_url($url);
        
        if (!$parsed || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            return false;
        }
        
        // Only allow http and https
        if (!in_array(strtolower($parsed['scheme']), ['http', 'https'])) {
            return false;
        }
        
        return true;
    }

    /**
     * Check cache for existing content (uses AnnoService)
     */
    private function checkCache(string $url, string $projectId): ?array
    {
        $data = $this->annoService->readAnnotation(AnnoService::TYPE_URL, $url, $projectId, true);
        
        if (!$data) {
            return null;
        }
        
        $metadata = $this->annoService->getMetadata($data);
        $textContent = $this->annoService->getTextContent($data);
        
        return [
            'title' => $metadata['title'] ?? '',
            'description' => $metadata['description'] ?? '',
            'content' => trim($textContent),
            'fetched_at' => $metadata['fetched_at'] ?? null
        ];
    }

    /**
     * Save content to cache in .anno format (uses AnnoService)
     */
    private function saveToCache(string $url, string $projectId, array $content): void
    {
        $annoData = $this->annoService->buildUrlAnnotation(
            $url,
            $content['title'] ?? '',
            $content['description'] ?? '',
            $content['content'] ?? '',
            self::CACHE_DURATION_HOURS
        );
        
        $this->annoService->writeAnnotation(AnnoService::TYPE_URL, $url, $annoData, $projectId);
    }

    /**
     * Fetch URL with safety limits
     */
    private function fetchWithLimits(string $url): array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => self::HTTP_TIMEOUT,
                'max_redirects' => self::MAX_REDIRECTS,
                'headers' => [
                    'User-Agent' => 'CitadelQuest ' . CitadelVersion::VERSION . ' HTTP Client (Web Content Fetcher)',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ],
            ]);
            
            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                return [
                    'success' => false,
                    'error' => "HTTP error: $statusCode"
                ];
            }
            
            // Check content length header first
            $headers = $response->getHeaders();
            if (isset($headers['content-length'][0])) {
                $contentLength = (int) $headers['content-length'][0];
                if ($contentLength > self::MAX_CONTENT_SIZE) {
                    return [
                        'success' => false,
                        'error' => 'Content too large: ' . round($contentLength / 1024 / 1024, 2) . 'MB (max 1MB)'
                    ];
                }
            }
            
            // Get content with size limit
            $content = '';
            foreach ($this->httpClient->stream($response) as $chunk) {
                $content .= $chunk->getContent();
                if (strlen($content) > self::MAX_CONTENT_SIZE) {
                    return [
                        'success' => false,
                        'error' => 'Content too large (exceeded 1MB during download)'
                    ];
                }
            }
            
            // Get final URL after redirects
            $info = $response->getInfo();
            $finalUrl = $info['url'] ?? $url;
            
            return [
                'success' => true,
                'content' => $content,
                'url' => $finalUrl
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Fetch failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Basic HTML cleanup - extract text and metadata
     */
    private function basicHtmlCleanup(string $html): array
    {
        // Extract title
        $title = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            $title = html_entity_decode(trim($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        
        // Extract meta description
        $description = '';
        if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/is', $html, $matches)) {
            $description = html_entity_decode(trim($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } elseif (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']description["\'][^>]*>/is', $html, $matches)) {
            $description = html_entity_decode(trim($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        
        // Remove unwanted elements
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/<noscript[^>]*>.*?<\/noscript>/is', '', $html);
        $html = preg_replace('/<!--.*?-->/s', '', $html);
        
        // Remove common non-content elements (but keep their text for AI to filter)
        // We'll let the AI decide what's important
        
        // Convert to text
        $text = strip_tags($html);
        
        // Clean up whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = preg_replace('/\n\s*\n/', "\n\n", $text);
        $text = trim($text);
        
        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        return [
            'title' => $title,
            'description' => $description,
            'content' => $text
        ];
    }

    /**
     * Use AI to extract meaningful content from semi-cleaned HTML
     */
    private function aiExtractContent(
        string $url,
        string $title,
        string $description,
        string $content,
        string $lang,
        string $projectId
    ): array {
        // Get a fast AI model for extraction
        $aiServiceModel = null;
        $gateway = $this->aiGatewayService->findByName('CQ AI Gateway');
        if ($gateway) {
            // Try fast models first
            $aiServiceModel = $this->aiServiceModelService->findByModelSlug('citadelquest/grok-4.1-fast', $gateway->getId());
            if (!$aiServiceModel) {
                $aiServiceModel = $this->aiServiceModelService->findByModelSlug('citadelquest/kael', $gateway->getId());
            }
        }
        
        if (!$aiServiceModel) {
            // No AI model available, return raw content
            return [
                'success' => false,
                'error' => 'No AI model available for content extraction'
            ];
        }
        
        // Truncate content if too long for AI processing
        $maxInputLength = 30000; // Leave room for system prompt and response
        if (strlen($content) > $maxInputLength) {
            $content = substr($content, 0, $maxInputLength) . "\n\n[Content truncated due to length...]";
        }
        
        // Build system prompt
        $systemPrompt = $this->buildExtractorSystemPrompt();
        
        // Build user message
        $userMessage = "URL: $url\n";
        $userMessage .= "Title: $title\n";
        if ($description) {
            $userMessage .= "Description: $description\n";
        }
        $userMessage .= "\nRaw Content:\n$content";
        
        // Create messages array
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage]
        ];
        
        try {
            // Create AI service request
            $aiServiceRequest = $this->aiServiceRequestService->createRequest(
                $aiServiceModel->getId(),
                $messages,
                null,
                0.3, // Low temperature for consistent extraction
                null,
                []
            );
            
            // Send request to AI service
            $aiServiceResponse = $this->aiGatewayService->sendRequest(
                $aiServiceRequest,
                'fetchURL AI Tool - Content Extraction',
                $lang,
                $projectId
            );
            
            // Get the extracted content from response
            $message = $aiServiceResponse->getMessage();
            $extractedContent = $message['content'] ?? '';
            
            if (is_array($extractedContent)) {
                // Handle multimodal response format
                foreach ($extractedContent as $item) {
                    if (isset($item['type']) && $item['type'] === 'text') {
                        $extractedContent = $item['text'];
                        break;
                    }
                }
            }
            
            // Get usage data from full response
            $fullResponse = $aiServiceResponse->getFullResponse();
            $totalTokens = $aiServiceResponse->getTotalTokens();
            $totalCost = $fullResponse['total_cost_credits'] ?? null;
            
            return [
                'success' => true,
                'content' => $extractedContent,
                'totalTokens' => $totalTokens,
                'totalCost' => $totalCost
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'AI extraction failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Build system prompt for content extraction
     */
    private function buildExtractorSystemPrompt(): string
    {
        return <<<PROMPT
You are a web content extractor. Your task is to extract the meaningful main content from a web page and return it in clean, readable format.

## Instructions:
1. Extract ONLY the main article/content - ignore navigation, ads, footers, sidebars, cookie notices
2. Preserve the content structure (headings, lists, paragraphs)
3. Convert to clean Markdown format
4. Keep important links if they're part of the content or navigation/sitemap (format as [text](url))
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
<clean_system_prompt>
PROMPT;
    }

    /**
     * Build frontend data HTML for display
     */
    private function buildFrontendData(string $url, string $title, bool $cached, ?int $totalTokens = null, ?float $totalCost = null): string
    {
        $cacheIcon = $cached ? '<i class="mdi mdi-cached text-success me-1" title="From cache"></i>' : '<i class="mdi mdi-web text-info me-1" title="Freshly fetched"></i>';
        $displayTitle = htmlspecialchars($title ?: $url);
        $displayUrl = htmlspecialchars($url);
        
        // Build usage info string
        $usageInfo = '';
        if (!$cached && ($totalTokens || $totalCost)) {
            $usageInfo = '<div class="small text-muted mt-1 opacity-50">';
            if ($totalTokens) {
                $usageInfo .= '<i class="mdi mdi-tally-mark-5 me-1 text-cyber opacity-50" title="Tokens"></i>' . number_format($totalTokens);
            }
            if ($totalCost) {
                $costParts = explode('.', number_format($totalCost, 2));
                $costDisplay = $costParts[0] . '<span class="opacity-75">.' . ($costParts[1] ?? '00') . '</span>';
                $usageInfo .= '<i class="mdi mdi-circle-multiple-outline me-1 ms-2 text-cyber opacity-50" title="Credits"></i>' . $costDisplay;
            }
            $usageInfo .= '</div>';
        }
        
        return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <div class="d-flex align-items-center">
        $cacheIcon
        <a href="$displayUrl" target="_blank" rel="noopener noreferrer" class="text-cyber text-decoration-none">
            <i class="mdi mdi-open-in-new me-1"></i>$displayTitle
        </a>
    </div>
    $usageInfo
</div>
HTML;
    }

    /**
     * Truncate content to maximum length
     */
    private function truncateContent(string $content, int $maxLength): string
    {
        if (strlen($content) <= $maxLength) {
            return $content;
        }
        
        return substr($content, 0, $maxLength) . "\n\n[Content truncated at $maxLength characters]";
    }

    /**
     * Validate required arguments
     */
    private function validateArguments(array $arguments, array $required): void
    {
        foreach ($required as $arg) {
            if (!isset($arguments[$arg])) {
                throw new \InvalidArgumentException("Missing required argument: $arg");
            }
        }
    }
}
