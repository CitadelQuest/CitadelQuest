<?php

namespace App\Service;

use App\Entity\SpiritMemoryNode;
use Psr\Log\LoggerInterface;

/**
 * Service for AI Tool memory operations
 * 
 * Implements memory tools including the memoryExtract Sub-Agent which:
 * 1. Receives raw content OR loads it from sourceType+sourceRef
 * 2. Checks for duplicate extractions (same source already processed)
 * 3. Calls a specialized LLM to extract discrete memory nodes
 * 4. Parses JSON response with extracted memories
 * 5. Stores each memory in the Spirit's knowledge graph
 * 
 * Supported sourceTypes for auto-loading:
 * - document: File from ProjectFileService (sourceRef = "projectId:path/filename")
 * - spirit_conversation: Conversation from DB (sourceRef = conversation ID)
 * - url: Web content via fetchURL (sourceRef = URL)
 * - legacy_memory: Legacy .md file (sourceRef = "projectId:path/filename")
 * 
 * @see /docs/features/spirit-memory-v3.md
 */
class AIToolMemoryService
{
    public function __construct(
        private readonly SpiritMemoryService $spiritMemoryService,
        private readonly SpiritService $spiritService,
        private readonly AiGatewayService $aiGatewayService,
        private readonly AiServiceRequestService $aiServiceRequestService,
        private readonly AiServiceModelService $aiServiceModelService,
        private readonly ProjectFileService $projectFileService,
        private readonly SpiritConversationMessageService $spiritConversationMessageService,
        private readonly AIToolWebService $aiToolWebService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Store a new memory in the Spirit's knowledge graph
     */
    public function memoryStore(array $arguments): array
    {
        try {
            // Validate required arguments
            if (!isset($arguments['content']) || empty($arguments['content'])) {
                return [
                    'success' => false,
                    'error' => 'Content is required'
                ];
            }

            if (!isset($arguments['category']) || empty($arguments['category'])) {
                return [
                    'success' => false,
                    'error' => 'Category is required'
                ];
            }

            // Validate category
            $validCategories = SpiritMemoryNode::getValidCategories();
            if (!in_array($arguments['category'], $validCategories)) {
                return [
                    'success' => false,
                    'error' => 'Invalid category. Must be one of: ' . implode(', ', $validCategories)
                ];
            }

            // Get the Spirit ID (use primary spirit if not specified)
            $spiritId = $arguments['spiritId'] ?? null;
            if (!$spiritId) {
                $spirit = $this->spiritService->getUserSpirit();
                if (!$spirit) {
                    return [
                        'success' => false,
                        'error' => 'No Spirit found'
                    ];
                }
                $spiritId = $spirit->getId();
            }

            // Extract optional arguments
            $importance = isset($arguments['importance']) ? (float)$arguments['importance'] : 0.5;
            $importance = max(0.0, min(1.0, $importance));
            
            $tags = $arguments['tags'] ?? [];
            if (is_string($tags)) {
                $tags = array_map('trim', explode(',', $tags));
            }
            
            $relatesTo = $arguments['relatesTo'] ?? null;

            // Store the memory
            $memory = $this->spiritMemoryService->store(
                $spiritId,
                $arguments['content'],
                $arguments['category'],
                $importance,
                null, // Auto-generate summary
                'conversation', // Source type
                null, // Source ref
                $tags,
                $relatesTo
            );

            $this->logger->info('Memory stored via AI tool', [
                'memoryId' => $memory->getId(),
                'category' => $arguments['category']
            ]);

            return [
                'success' => true,
                'memoryId' => $memory->getId(),
                'summary' => $memory->getSummary(),
                'category' => $memory->getCategory(),
                'importance' => $memory->getImportance(),
                'tags' => $tags,
                'message' => 'Memory stored successfully'
            ];

        } catch (\Exception $e) {
            $this->logger->error('Error storing memory', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => 'Error storing memory: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Recall memories from the Spirit's knowledge graph
     */
    public function memoryRecall(array $arguments): array
    {
        try {
            // Validate required arguments
            if (!isset($arguments['query']) || empty($arguments['query'])) {
                return [
                    'success' => false,
                    'error' => 'Query is required'
                ];
            }

            // Get the Spirit ID
            $spiritId = $arguments['spiritId'] ?? null;
            if (!$spiritId) {
                $spirit = $this->spiritService->getUserSpirit();
                if (!$spirit) {
                    return [
                        'success' => false,
                        'error' => 'No Spirit found'
                    ];
                }
                $spiritId = $spirit->getId();
            }

            // Extract optional arguments
            $category = $arguments['category'] ?? null;
            $tags = $arguments['tags'] ?? [];
            if (is_string($tags)) {
                $tags = array_map('trim', explode(',', $tags));
            }
            $limit = isset($arguments['limit']) ? (int)$arguments['limit'] : 10;
            $includeRelated = $arguments['includeRelated'] ?? true;

            // Recall memories
            $results = $this->spiritMemoryService->recall(
                $spiritId,
                $arguments['query'],
                $category,
                $tags,
                $limit,
                $includeRelated
            );

            // Format results for AI consumption
            $memories = [];
            foreach ($results as $result) {
                $node = $result['node'];
                $memories[] = [
                    'id' => $node->getId(),
                    'content' => $node->getContent(),
                    'summary' => $node->getSummary(),
                    'category' => $node->getCategory(),
                    'importance' => $node->getImportance(),
                    'createdAt' => $node->getCreatedAt()->format('Y-m-d H:i:s'),
                    'tags' => $result['tags'] ?? [],
                    'score' => $result['score'] ?? 0,
                    'isRelated' => $result['isRelated'] ?? false
                ];
            }

            return [
                'success' => true,
                'query' => $arguments['query'],
                'count' => count($memories),
                'memories' => $memories,
                'message' => count($memories) > 0 
                    ? 'Found ' . count($memories) . ' memories' 
                    : 'No memories found matching the query'
            ];

        } catch (\Exception $e) {
            $this->logger->error('Error recalling memories', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => 'Error recalling memories: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update an existing memory
     */
    public function memoryUpdate(array $arguments): array
    {
        try {
            // Validate required arguments
            if (!isset($arguments['memoryId']) || empty($arguments['memoryId'])) {
                return [
                    'success' => false,
                    'error' => 'Memory ID is required'
                ];
            }

            if (!isset($arguments['newContent']) || empty($arguments['newContent'])) {
                return [
                    'success' => false,
                    'error' => 'New content is required'
                ];
            }

            $reason = $arguments['reason'] ?? null;

            // Update the memory
            $newMemory = $this->spiritMemoryService->update(
                $arguments['memoryId'],
                $arguments['newContent'],
                $reason
            );

            $this->logger->info('Memory updated via AI tool', [
                'oldMemoryId' => $arguments['memoryId'],
                'newMemoryId' => $newMemory->getId()
            ]);

            return [
                'success' => true,
                'oldMemoryId' => $arguments['memoryId'],
                'newMemoryId' => $newMemory->getId(),
                'summary' => $newMemory->getSummary(),
                'message' => 'Memory updated successfully. Old memory preserved with EVOLVED_INTO relationship.'
            ];

        } catch (\Exception $e) {
            $this->logger->error('Error updating memory', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => 'Error updating memory: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Forget (soft delete) a memory
     */
    public function memoryForget(array $arguments): array
    {
        try {
            // Validate required arguments
            if (!isset($arguments['memoryId']) || empty($arguments['memoryId'])) {
                return [
                    'success' => false,
                    'error' => 'Memory ID is required'
                ];
            }

            $reason = $arguments['reason'] ?? null;

            // Forget the memory
            $success = $this->spiritMemoryService->forget(
                $arguments['memoryId'],
                $reason
            );

            if (!$success) {
                return [
                    'success' => false,
                    'error' => 'Memory not found'
                ];
            }

            $this->logger->info('Memory forgotten via AI tool', [
                'memoryId' => $arguments['memoryId'],
                'reason' => $reason
            ]);

            return [
                'success' => true,
                'memoryId' => $arguments['memoryId'],
                'message' => 'Memory marked as forgotten (soft deleted)'
            ];

        } catch (\Exception $e) {
            $this->logger->error('Error forgetting memory', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => 'Error forgetting memory: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Extract memories from raw content using AI Sub-Agent
     * 
     * This tool can either:
     * 1. Receive raw content directly via 'content' parameter
     * 2. Auto-load content from sourceType+sourceRef (saves an AI tool call!)
     * 
     * Supported sourceTypes for auto-loading:
     * - document: File from ProjectFileService (sourceRef = "projectId:path:filename")
     * - legacy_memory: Legacy .md file (sourceRef = "projectId:path:filename")  
     * - spirit_conversation: Conversation from DB (sourceRef = conversation ID)
     * - url: Web content (sourceRef = URL) - not yet implemented
     * 
     * Duplicate prevention: If sourceType+sourceRef already extracted, returns existing info.
     * Use 'force' = true to re-extract anyway.
     * 
     * @param array $arguments Tool arguments:
     *   - content: string (optional if sourceType+sourceRef provided) - Raw content
     *   - sourceType: string (optional) - document|legacy_memory|spirit_conversation|url|derived
     *   - sourceRef: string (optional) - Reference to source (format depends on sourceType)
     *   - context: string (optional) - Additional context about the content
     *   - force: bool (optional) - Force re-extraction even if already processed
     * 
     * @return array Tool result with success status and extracted memories
     */
    public function memoryExtract(array $arguments): array
    {
        try {
            // Get the Spirit ID first (needed for duplicate check)
            $spiritId = $arguments['spiritId'] ?? null;
            if (!$spiritId) {
                $spirit = $this->spiritService->getUserSpirit();
                if (!$spirit) {
                    return [
                        'success' => false,
                        'error' => 'No Spirit found'
                    ];
                }
                $spiritId = $spirit->getId();
            }

            $sourceType = $arguments['sourceType'] ?? null;
            $sourceRef = $arguments['sourceRef'] ?? null;
            $force = $arguments['force'] ?? false;

            // Check for duplicate extraction (if sourceType and sourceRef provided)
            if ($sourceType && $sourceRef && !$force) {
                $existing = $this->spiritMemoryService->hasExtractedFromSource($spiritId, $sourceType, $sourceRef);
                if ($existing) {
                    return [
                        'success' => true,
                        'alreadyExtracted' => true,
                        'existingCount' => $existing['count'],
                        'lastExtracted' => $existing['lastExtracted'],
                        'message' => "This source has already been processed. Found {$existing['count']} existing memories extracted on {$existing['lastExtracted']}. Use 'force: true' to re-extract."
                    ];
                }
            }

            // Try to auto-load content if not provided but sourceType+sourceRef are
            $content = $arguments['content'] ?? null;
            
            if (empty($content) && $sourceType && $sourceRef) {
                $loadResult = $this->loadContentFromSource($sourceType, $sourceRef);
                if (!$loadResult['success']) {
                    return $loadResult;
                }
                $content = $loadResult['content'];
                
                // Add loaded info to context
                if (!isset($arguments['context'])) {
                    $arguments['context'] = '';
                }
                $arguments['context'] .= ($arguments['context'] ? "\n" : '') . 
                    "Content auto-loaded from {$sourceType}: {$sourceRef}";
            }

            // Validate we have content now
            if (empty($content)) {
                return [
                    'success' => false,
                    'error' => 'Content is required. Either provide content directly, or specify sourceType and sourceRef to auto-load.'
                ];
            }
            
            // Store content in arguments for the user message builder
            $arguments['content'] = $content;

            // Get AI model for the Memory Extractor Sub-Agent
            $aiServiceModel = null;
            $gateway = $this->aiGatewayService->findByName('CQ AI Gateway');
            if ($gateway) {
                // Try fast models first for efficiency
                $aiServiceModel = $this->aiServiceModelService->findByModelSlug('citadelquest/grok-4.1-fast', $gateway->getId());
                if (!$aiServiceModel) {
                    $aiServiceModel = $this->aiServiceModelService->findByModelSlug('citadelquest/kael', $gateway->getId());
                }
            }
            if (!$aiServiceModel) {
                return [
                    'success' => false,
                    'error' => 'No AI service model configured for Memory Extractor'
                ];
            }

            // Build the system prompt for the Memory Extractor
            $systemPrompt = $this->buildMemoryExtractorSystemPrompt();
            
            // Build user message with the content
            $userMessage = $this->buildMemoryExtractorUserMessage($arguments);
            
            // Create messages array
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage]
            ];
            
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
                'memoryExtract AI Tool - Memory Extraction Sub-Agent', 
                'English', 
                'general'
            );
            
            // Parse the JSON response
            $extractedMemories = $this->parseMemoryExtractorResponse($aiServiceResponse);
            
            // If parsing failed, retry once with error feedback
            if ($extractedMemories === null) {
                $failedContent = $this->extractResponseContent($aiServiceResponse);
                
                // Build retry messages with error feedback
                $retryMessages = $messages;
                $retryMessages[] = ['role' => 'assistant', 'content' => $failedContent];
                $retryMessages[] = ['role' => 'user', 'content' => 
                    "Your response was not valid JSON and could not be parsed. " .
                    "Please respond with ONLY a valid JSON object - no markdown code blocks, no explanation text. " .
                    "The JSON must contain 'memories' as an array. Start directly with { and end with }."
                ];
                
                // Create retry request
                $retryAiServiceRequest = $this->aiServiceRequestService->createRequest(
                    $aiServiceModel->getId(),
                    $retryMessages,
                    null,
                    0.2, // Even lower temperature for retry
                    null,
                    []
                );
                
                // Send retry request
                $retryResponse = $this->aiGatewayService->sendRequest(
                    $retryAiServiceRequest,
                    'memoryExtract AI Tool - Memory Extraction (Retry)',
                    'English',
                    'general'
                );
                
                $extractedMemories = $this->parseMemoryExtractorResponse($retryResponse);
                
                if ($extractedMemories === null) {
                    return [
                        'success' => false,
                        'error' => 'Failed to parse Memory Extractor response after retry. The AI did not return valid JSON.'
                    ];
                }
            }

            // Store each extracted memory
            $storedMemories = [];
            $sourceType = $arguments['sourceType'] ?? 'derived';
            $sourceRef = $arguments['sourceRef'] ?? null;

            foreach ($extractedMemories as $memoryData) {
                try {
                    $memory = $this->spiritMemoryService->store(
                        $spiritId,
                        $memoryData['content'],
                        $memoryData['category'] ?? 'knowledge',
                        $memoryData['importance'] ?? 0.5,
                        $memoryData['summary'] ?? null,
                        $sourceType,
                        $sourceRef,
                        $memoryData['tags'] ?? []
                    );

                    $storedMemories[] = [
                        'id' => $memory->getId(),
                        'content' => $memory->getContent(),
                        'summary' => $memory->getSummary(),
                        'category' => $memory->getCategory(),
                        'importance' => $memory->getImportance(),
                        'tags' => $memoryData['tags'] ?? []
                    ];
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to store extracted memory', [
                        'error' => $e->getMessage(),
                        'content' => substr($memoryData['content'] ?? '', 0, 100)
                    ]);
                }
            }

            $this->logger->info('Memories extracted via AI tool', [
                'spiritId' => $spiritId,
                'extractedCount' => count($extractedMemories),
                'storedCount' => count($storedMemories)
            ]);

            return [
                'success' => true,
                'extractedCount' => count($extractedMemories),
                'storedCount' => count($storedMemories),
                'memories' => $storedMemories,
                'message' => 'Successfully extracted and stored ' . count($storedMemories) . ' memories from the provided content.'
            ];

        } catch (\Exception $e) {
            $this->logger->error('Error extracting memories', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => 'Error extracting memories: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Build the system prompt for the Memory Extractor LLM
     */
    private function buildMemoryExtractorSystemPrompt(): string
    {
        return <<<'PROMPT'
You are the Memory Extractor Agent - an expert at analyzing content and extracting discrete, meaningful pieces of information that should be remembered long-term.

## Your Task
Analyze the provided content and extract individual memory nodes. Each memory should be a self-contained piece of information that could be useful in future conversations.

## Memory Categories
- `conversation` - Summary of an interaction or dialogue
- `thought` - Reflections, insights, or internal observations
- `knowledge` - General information or learned concepts
- `fact` - Specific factual information about the user or world
- `preference` - User preferences, likes, dislikes, habits

## Extraction Guidelines

### What TO Extract:
- User preferences and habits
- Important facts about the user (name, job, interests, family)
- Significant events or milestones mentioned
- Opinions and beliefs expressed
- Skills, expertise, or knowledge areas
- Relationships and connections mentioned
- Goals, plans, or aspirations
- Emotional states or concerns expressed
- Specific requests or instructions for future reference

### What NOT TO Extract:
- Trivial or temporary information (e.g., "user said hello")
- Duplicate information (if similar content exists, skip it)
- Vague or unclear statements
- Information that's only relevant to the immediate context
- Technical details of the conversation itself

### Importance Scoring (0.0 to 1.0):
- **0.9-1.0**: Critical information (user's name, major life events, core values)
- **0.7-0.8**: Important preferences and facts (job, interests, family members)
- **0.5-0.6**: Useful context (opinions, minor preferences)
- **0.3-0.4**: Nice to know (casual mentions, temporary states)
- **0.1-0.2**: Low priority (might be useful someday)

### Summary Guidelines:
- Keep summaries under 50 characters
- Make them searchable and descriptive
- Use keywords that would help find this memory later

## Output Format
You MUST respond with ONLY a valid JSON object (no markdown, no explanation):

```json
{
    "memories": [
        {
            "content": "Full memory content - self-contained and understandable alone",
            "summary": "Short searchable summary (max 50 chars)",
            "category": "fact|preference|knowledge|thought|conversation",
            "importance": 0.7,
            "tags": ["relevant", "searchable", "tags"]
        }
    ],
    "skipped": "Brief explanation of what was intentionally not extracted and why"
}
```

## Important Rules
1. ONLY output the JSON object, nothing else
2. Each memory must be self-contained and understandable without context
3. Assign appropriate importance based on long-term relevance
4. Use specific, searchable tags (2-5 tags per memory)
5. If no meaningful memories can be extracted, return empty memories array
6. Quality over quantity - fewer good memories is better than many trivial ones
PROMPT;
    }

    /**
     * Build the user message for the Memory Extractor
     */
    private function buildMemoryExtractorUserMessage(array $arguments): string
    {
        $message = "Extract memories from the following content:\n\n";
        
        if (!empty($arguments['sourceType'])) {
            $message .= "**Source Type**: " . $arguments['sourceType'] . "\n";
        }
        
        if (!empty($arguments['sourceRef'])) {
            $message .= "**Source Reference**: " . $arguments['sourceRef'] . "\n";
        }
        
        if (!empty($arguments['context'])) {
            $message .= "**Additional Context**: " . $arguments['context'] . "\n";
        }
        
        $message .= "\n---\n\n";
        $message .= "**Content to analyze**:\n\n";
        $message .= $arguments['content'];
        $message .= "\n\n---\n\n";
        $message .= "Respond with ONLY the JSON object containing extracted memories, no other text.";
        
        return $message;
    }

    /**
     * Extract content string from AI service response
     */
    private function extractResponseContent($aiServiceResponse): string
    {
        $message = $aiServiceResponse->getMessage();
        $content = $message['content'] ?? '';
        
        if (is_array($content)) {
            foreach ($content as $item) {
                if (isset($item['type']) && $item['type'] === 'text') {
                    return $item['text'];
                }
            }
            return '';
        }
        
        return is_string($content) ? $content : '';
    }

    /**
     * Parse the Memory Extractor's JSON response
     */
    private function parseMemoryExtractorResponse($aiServiceResponse): ?array
    {
        $message = $aiServiceResponse->getMessage();
        $content = $message['content'] ?? '';
        
        if (is_array($content)) {
            // Handle multimodal response format
            foreach ($content as $item) {
                if (isset($item['type']) && $item['type'] === 'text') {
                    $content = $item['text'];
                    break;
                }
            }
        }
        
        if (!is_string($content)) {
            return null;
        }
        
        // Try to extract JSON from the response
        // First, try direct parse
        $decoded = json_decode($content, true);
        if ($decoded && isset($decoded['memories']) && is_array($decoded['memories'])) {
            return $decoded['memories'];
        }
        
        // Try to find JSON in markdown code block
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $content, $matches)) {
            $decoded = json_decode($matches[1], true);
            if ($decoded && isset($decoded['memories']) && is_array($decoded['memories'])) {
                return $decoded['memories'];
            }
        }
        
        // Try to find raw JSON object
        if (preg_match('/\{[\s\S]*"memories"[\s\S]*\}/', $content, $matches)) {
            $decoded = json_decode($matches[0], true);
            if ($decoded && isset($decoded['memories']) && is_array($decoded['memories'])) {
                return $decoded['memories'];
            }
        }
        
        return null;
    }

    /**
     * Load content from a source based on sourceType and sourceRef
     * 
     * Supported formats:
     * - document/legacy_memory: "projectId:path:filename" (e.g., "general:/spirit/Bilbo/memory:conversations.md")
     * - spirit_conversation: conversation ID
     * - url: URL string (not yet implemented)
     * 
     * @return array ['success' => bool, 'content' => string, 'error' => string]
     */
    private function loadContentFromSource(string $sourceType, string $sourceRef): array
    {
        try {
            switch ($sourceType) {
                case 'document':
                case 'legacy_memory':
                    return $this->loadFromProjectFile($sourceRef);
                    
                case 'spirit_conversation':
                    return $this->loadFromConversation($sourceRef);
                    
                case 'url':
                    return $this->loadFromUrl($sourceRef);
                    
                default:
                    return [
                        'success' => false,
                        'error' => "Unknown sourceType: {$sourceType}. Supported: document, legacy_memory, spirit_conversation, url"
                    ];
            }
        } catch (\Exception $e) {
            $this->logger->error('Error loading content from source', [
                'sourceType' => $sourceType,
                'sourceRef' => $sourceRef,
                'error' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'error' => 'Error loading content: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Load content from a project file
     * Format: "projectId:path:filename" (e.g., "general:/spirit/Bilbo/memory:conversations.md")
     */
    private function loadFromProjectFile(string $sourceRef): array
    {
        // Parse sourceRef format: "projectId:path:filename"
        $parts = explode(':', $sourceRef, 3);
        
        if (count($parts) < 3) {
            return [
                'success' => false,
                'error' => 'Invalid sourceRef format for document. Expected "projectId:path:filename" (e.g., "general:/spirit/Bilbo/memory:conversations.md")'
            ];
        }
        
        [$projectId, $path, $filename] = $parts;
        
        try {
            $file = $this->projectFileService->findByPathAndName($projectId, $path, $filename);
            
            if (!$file) {
                return [
                    'success' => false,
                    'error' => "File not found: {$path}/{$filename} in project {$projectId}"
                ];
            }
            
            if ($file->isDirectory()) {
                return [
                    'success' => false,
                    'error' => 'Cannot extract memories from a directory'
                ];
            }
            
            $content = $this->projectFileService->getFileContent($file->getId());
            
            // Check if content is binary/base64
            if (strpos($content, 'data:') === 0) {
                return [
                    'success' => false,
                    'error' => 'Cannot extract memories from binary files'
                ];
            }
            
            return [
                'success' => true,
                'content' => $content,
                'fileName' => $filename,
                'filePath' => $path
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Error loading file: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Load content from a spirit conversation
     * Format: conversation ID
     */
    private function loadFromConversation(string $conversationId): array
    {
        try {
            $messages = $this->spiritConversationMessageService->getMessagesByConversation($conversationId);
            
            if (empty($messages)) {
                return [
                    'success' => false,
                    'error' => "Conversation not found or has no messages: {$conversationId}"
                ];
            }
            
            // Build conversation transcript
            $transcript = [];
            
            foreach ($messages as $msg) {
                $role = $msg->getRole();
                $content = $msg->getContent();
                
                // Handle array content (multimodal)
                if (is_array($content)) {
                    $textParts = [];
                    foreach ($content as $part) {
                        if (isset($part['type']) && $part['type'] === 'text') {
                            $textParts[] = $part['text'];
                        }
                    }
                    $content = implode("\n", $textParts);
                } elseif (!is_string($content)) {
                    $content = json_encode($content);
                }
                
                $transcript[] = "[{$role}]: {$content}";
            }
            
            return [
                'success' => true,
                'content' => implode("\n\n", $transcript),
                'conversationId' => $conversationId,
                'messageCount' => count($messages)
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Error loading conversation: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Load content from a URL using fetchURL tool
     * Format: URL string (e.g., "https://example.com/article")
     */
    private function loadFromUrl(string $url): array
    {
        try {
            // Use fetchURL to get the content (it handles caching, extraction, etc.)
            $result = $this->aiToolWebService->fetchURL([
                'url' => $url,
                'forceRefresh' => false,
                'projectId' => 'general'
            ]);
            
            if (!$result['success']) {
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'Failed to fetch URL content'
                ];
            }
            
            // Build content with metadata
            $content = '';
            if (!empty($result['title'])) {
                $content .= "# {$result['title']}\n\n";
            }
            $content .= "Source: {$url}\n";
            if (!empty($result['fetched_at'])) {
                $content .= "Fetched: {$result['fetched_at']}\n";
            }
            $content .= "\n---\n\n";
            $content .= $result['content'] ?? '';
            
            return [
                'success' => true,
                'content' => $content,
                'url' => $result['url'] ?? $url,
                'title' => $result['title'] ?? '',
                'cached' => $result['cached'] ?? false
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Error fetching URL: ' . $e->getMessage()
            ];
        }
    }
}
