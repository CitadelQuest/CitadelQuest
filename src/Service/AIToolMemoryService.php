<?php

namespace App\Service;

use App\Entity\MemoryNode;
use App\Entity\MemoryJob;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

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
    private $user;
    
    public function __construct(
        private readonly SpiritService $spiritService,
        private readonly AiGatewayService $aiGatewayService,
        private readonly AiServiceRequestService $aiServiceRequestService,
        private readonly AiServiceModelService $aiServiceModelService,
        private readonly ProjectFileService $projectFileService,
        private readonly SpiritConversationMessageService $spiritConversationMessageService,
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly AIToolWebService $aiToolWebService,
        private readonly LoggerInterface $logger,
        private readonly SettingsService $settingsService,
        private readonly AnnoService $annoService,
        private readonly NotificationService $notificationService,
        private readonly Security $security,
        private readonly CQMemoryPackService $packService
    ) {
        $this->user = $security->getUser();
    }

    /**
     * Open Spirit's root memory pack for operations
     * 
     * @return array Pack info with projectId, packsPath, rootPackName
     * @throws \RuntimeException if Spirit not found or pack cannot be opened
     */
    private function openSpiritPack(string $spiritId): array
    {
        $spirit = $this->spiritService->findById($spiritId);
        if (!$spirit) {
            throw new \RuntimeException('Spirit not found');
        }
        
        $memoryInfo = $this->spiritService->initSpiritMemory($spirit);
        
        $this->packService->open(
            $memoryInfo['projectId'],
            $memoryInfo['packsPath'],
            $memoryInfo['rootPackName']
        );
        
        return $memoryInfo;
    }

    /**
     * Build targetPack array for a Spirit (without opening the pack)
     * Used to delegate to pack-based methods like memoryExtractToPack()
     */
    public function getSpiritTargetPack(string $spiritId): array
    {
        $spirit = $this->spiritService->findById($spiritId);
        if (!$spirit) {
            throw new \RuntimeException('Spirit not found');
        }
        
        $memoryInfo = $this->spiritService->initSpiritMemory($spirit);
        
        return [
            'projectId' => $memoryInfo['projectId'],
            'path' => $memoryInfo['packsPath'],
            'name' => $memoryInfo['rootPackName']
        ];
    }

    /**
     * Store a new memory in the Spirit's memory pack
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
            $validCategories = MemoryNode::getValidCategories();
            if (!in_array($arguments['category'], $validCategories)) {
                return [
                    'success' => false,
                    'error' => 'Invalid category. Must be one of: ' . implode(', ', $validCategories)
                ];
            }

            // Get the Spirit ID
            $spiritId = $arguments['spiritId'] ?? null;
            if (!$spiritId) {
                return [
                    'success' => false,
                    'error' => 'No Spirit found'
                ];
            }

            // Extract optional arguments
            $importance = isset($arguments['importance']) ? (float)$arguments['importance'] : 0.5;
            $importance = max(0.0, min(1.0, $importance));
            
            $tags = $arguments['tags'] ?? [];
            if (is_string($tags)) {
                $tags = array_map('trim', explode(',', $tags));
            }
            
            $relatesTo = $arguments['relatesTo'] ?? null;
            $analyzeRelationships = $arguments['analyzeRelationships'] ?? true;

            // Open Spirit's root pack and store the memory
            $this->openSpiritPack($spiritId);
            
            $memory = $this->packService->storeNode(
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

            // Auto-analyze relationships if enabled (pack is already open)
            $relationshipAnalysis = null;
            if ($analyzeRelationships) {
                $relationshipAnalysis = $this->memoryAnalyzeRelationships([
                    'memoryId' => $memory->getId(),
                    '_packAlreadyOpen' => true
                ]);
            }

            $this->packService->close();

            return [
                'success' => true,
                'memoryId' => $memory->getId(),
                'summary' => $memory->getSummary(),
                'category' => $memory->getCategory(),
                'importance' => $memory->getImportance(),
                'tags' => $tags,
                'message' => 'Memory stored successfully',
                'relationshipAnalysis' => $relationshipAnalysis
            ];

        } catch (\Exception $e) {
            $this->packService->close();
            $this->logger->error('Error storing memory', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => 'Error storing memory: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Recall memories from the Spirit's memory pack
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
                return [
                    'success' => false,
                    'error' => 'No Spirit found'
                ];
            }

            // Extract optional arguments
            $category = $arguments['category'] ?? null;
            $tags = $arguments['tags'] ?? [];
            if (is_string($tags)) {
                $tags = array_map('trim', explode(',', $tags));
            }
            $limit = isset($arguments['limit']) ? (int)$arguments['limit'] : 10;
            $includeRelated = $arguments['includeRelated'] ?? true;

            // Open Spirit's root pack and recall memories
            $this->openSpiritPack($spiritId);
            
            $results = $this->packService->recall(
                $arguments['query'],
                $category,
                $tags,
                $limit,
                $includeRelated
            );
            
            $this->packService->close();

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
            $this->packService->close();
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

            $spiritId = $arguments['spiritId'] ?? null;
            if (!$spiritId) {
                return ['success' => false, 'error' => 'No Spirit found'];
            }

            $reason = $arguments['reason'] ?? null;

            // Open Spirit's pack, update, close
            $this->openSpiritPack($spiritId);
            $newMemory = $this->packService->updateNode(
                $arguments['memoryId'],
                $arguments['newContent'],
                $reason
            );
            $this->packService->close();

            $this->logger->info('Memory updated via AI tool', [
                'oldMemoryId' => $arguments['memoryId'],
                'newMemoryId' => $newMemory->getId()
            ]);

            return [
                'success' => true,
                'oldMemoryId' => $arguments['memoryId'],
                'newMemoryId' => $newMemory->getId(),
                'summary' => $newMemory->getSummary(),
                'message' => 'Memory updated successfully. New version created from old one.'
            ];

        } catch (\Exception $e) {
            $this->packService->close();
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

            $spiritId = $arguments['spiritId'] ?? null;
            if (!$spiritId) {
                return ['success' => false, 'error' => 'No Spirit found'];
            }

            $reason = $arguments['reason'] ?? null;

            // Open Spirit's pack, forget, close
            $this->openSpiritPack($spiritId);
            $success = $this->packService->forgetNode(
                $arguments['memoryId'],
                $reason
            );
            $this->packService->close();

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
            $this->packService->close();
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
            // Get the Spirit ID (required for Spirit context)
            $spiritId = $arguments['spiritId'] ?? null;
            if (!$spiritId) {
                return [
                    'success' => false,
                    'error' => 'No Spirit found'
                ];
            }

            $sourceType = $arguments['sourceType'] ?? null;
            $sourceRef = $arguments['sourceRef'] ?? null;
            $force = $arguments['force'] ?? false;

            // Resolve conversation aliases to actual conversation ID(s)
            if ($sourceRef && in_array($sourceType, ['spirit_conversation', 'conversation'], true)) {
                // Check for "all" alias — batch extraction of all Spirit conversations
                if ($this->isConversationAllAlias($sourceRef)) {
                    return $this->memoryExtractAllConversations($spiritId, $arguments);
                }
                $sourceRef = $this->resolveConversationAlias($sourceRef, $spiritId);
            }

            // Build targetPack from Spirit context
            $targetPack = $this->getSpiritTargetPack($spiritId);

            // Check for duplicate extraction in the pack
            if ($sourceType && $sourceRef && !$force) {
                $this->packService->open($targetPack['projectId'], $targetPack['path'], $targetPack['name']);
                $existing = $this->packService->hasExtractedFromSource($sourceType, $sourceRef);
                $this->packService->close();

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

            // Delegate to pack-based extraction (single source of truth)
            $result = $this->memoryExtractToPack([
                'targetPack' => $targetPack,
                'sourceType' => $sourceType ?? 'derived',
                'sourceRef' => $sourceRef,
                'content' => $arguments['content'] ?? null,
                'maxDepth' => $arguments['maxDepth'] ?? 3,
                'documentTitle' => $arguments['documentTitle'] ?? null,
            ]);

            // Send notification on synchronous completion
            if (($result['success'] ?? false) && !($result['async'] ?? false) && $this->user) {
                $docName = $sourceRef ?? 'content';
                $count = $result['storedCount'] ?? 0;
                $this->notificationService->createNotification(
                    $this->user,
                    '📚 Memory Extraction Complete<br/>' . $docName,
                    'Extraction complete. Created ' . $count . ' memory nodes.',
                    'success'
                );
            }

            return $result;

        } catch (\Exception $e) {
            $this->logger->error('Error extracting memories', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => 'Error extracting memories: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Detect obfuscated or missing data in content and build a contextual warning
     * to inject into user messages, preventing AI hallucination.
     * 
     * Returns empty string if no issues detected.
     */
    private function buildDataIntegrityWarning(string|array $content): string
    {
        $text = is_string($content) ? $content : ($content['text'] ?? '');
        if (empty($text)) {
            return '';
        }

        $warnings = [];

        // Detect Cloudflare email obfuscation
        if (preg_match('/\[email\s*protected\]/i', $text) || preg_match('/\[email&#160;protected\]/i', $text)) {
            $warnings[] = 'This content contains Cloudflare-obfuscated email(s) shown as "[email protected]". The real email address is NOT available. Do NOT guess or invent any email address — use "[email protected]" exactly as-is, or omit it entirely.';
        }

        // Detect /cdn-cgi/l/email-protection patterns (Cloudflare encoded emails in links)
        if (str_contains($text, '/cdn-cgi/l/email-protection')) {
            $warnings[] = 'This content contains Cloudflare email protection links (/cdn-cgi/l/email-protection). These are encrypted — the real email is NOT decodable from this data. Do NOT attempt to guess the email address.';
        }

        if (empty($warnings)) {
            return '';
        }

        return "\n\n⚠️ **DATA INTEGRITY WARNING** ⚠️\n" . implode("\n", $warnings) . "\nAny data you output MUST match the source exactly. Fabricated data is unacceptable.\n";
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
- Keep it detailed and complete

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
            "content": "Full memory content - self-contained and understandable alone. In original language.",
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

## Data Integrity (CRITICAL):
- NEVER fabricate, guess, or hallucinate any information. Every piece of data you extract MUST come directly from the provided source content.
- If specific data (email addresses, phone numbers, prices, names, dates) is missing, obfuscated, or unclear in the source — do NOT invent it. Omit it or note it as unavailable. Wrong data is worse than no data.
- Web content may contain Cloudflare-obfuscated emails (e.g. `[email protected]`). Never guess the real address. Information in extracted memories must be 100% coherent with the source.
<clean_system_prompt>
PROMPT;
    }

    /**
     * Build the user message for the Memory Extractor
     */
    private function buildMemoryExtractorUserMessage(array $arguments): array
    {
        if (isset($arguments['content']) && is_string($arguments['content'])) {

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
            
            // Inject contextual warning if content has obfuscated data
            $dataWarning = $this->buildDataIntegrityWarning($arguments['content']);
            if ($dataWarning) {
                $message .= $dataWarning . "\n";
            }

            $message .= "\n---\n\n";
            $message .= "**Content to analyze**:\n\n";
            $message .= $this->addLineNumbers($arguments['content']);
            $message .= "\n\n---\n\n";
            $message .= "Respond with ONLY the JSON object containing extracted memories, no other text.";
            
            return  [
                        [
                            'text' => $message,
                            'type' => 'text'
                        ]
                    ];

        } elseif (isset($arguments['content']) && is_array($arguments['content'])) {

            $message = "Extract memories from the attached content:\n\n";
        
            if (!empty($arguments['sourceType'])) {
                $message .= "**Source Type**: " . $arguments['sourceType'] . "\n";
            }
        
            if (!empty($arguments['sourceRef'])) {
                $message .= "**Source Reference**: " . $arguments['sourceRef'] . "\n";
            }
            
            if (!empty($arguments['context'])) {
                $message .= "**Additional Context**: " . $arguments['context'] . "\n";
            }
            
            $message .= "---\n\n";
            $message .= "Respond with ONLY the JSON object containing extracted memories, no other text.";
            
            return  [
                        [
                            'text' => $message,
                            'type' => 'text'
                        ],
                        [
                            'file' => [
                                'file_data' => $arguments['content']['file_data'],
                                'filename' => $arguments['content']['filename'],
                            ],
                            'type' => 'file'
                        ]
                    ];

        } else {

            return [
                [
                    'text' => 'No content or file ID provided',
                    'type' => 'text'
                ]
            ];

        }
    }

    /**
     * Generate an AI summary of document content
     * Uses the same AI model as memory extraction for consistency
     * 
     * @param string|array $content String content or array with file_data/filename for binary files
     */
    private function generateDocumentSummary(string|array $content, string $documentTitle, $aiServiceModel, ?string $jobId = null): ?string
    {
        try {
            $systemPrompt = <<<'PROMPT'
You are a Document Summarizer. Your task is to create a comprehensive yet concise summary of the provided document content.

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
Respond with ONLY the summary text, no formatting, no headers, no explanations.
PROMPT;

            // Build user message content based on content type
            if (is_string($content)) {
                // String content - include directly in message
                $userMessageContent = "Summarize the following document:\n\n";
                $userMessageContent .= "**Document Title**: {$documentTitle}\n\n";
                // Inject contextual warning if content has obfuscated data
                $dataWarning = $this->buildDataIntegrityWarning($content);
                if ($dataWarning) {
                    $userMessageContent .= $dataWarning . "\n";
                }
                $userMessageContent .= "---\n\n";
                $userMessageContent .= $this->addLineNumbers($content);
                $userMessageContent .= "\n\n---\n\n";
                $userMessageContent .= "Provide a comprehensive summary of this document.";
            } else {
                // Array content (binary file) - build multimodal message
                $userMessageContent = [
                    [
                        'type' => 'text',
                        'text' => "Summarize the following document:\n\n**Document Title**: {$documentTitle}\n\n---\n\nProvide a comprehensive summary of this document."
                    ],
                    [
                        'type' => 'file',
                        'file' => [
                            'file_data' => $content['file_data'],
                            'filename' => $content['filename'],
                        ]
                    ]
                ];
            }

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessageContent]
            ];

            $aiServiceRequest = $this->aiServiceRequestService->createRequest(
                $aiServiceModel->getId(),
                $messages,
                null,
                0.3,
                null,
                []
            );

            // Get user locale
            $userLocale = $this->settingsService->getUserLocale();

            $aiServiceResponse = $this->aiGatewayService->sendRequest(
                $aiServiceRequest,
                'memoryExtract AI Tool - Document Summary Sub-Agent',
                $userLocale['lang'],
                'general'
            );

            // Log AI usage to pack if open
            if ($this->packService->isOpen()) {
                $this->packService->logAiUsageFromResponse(
                    'Document Summary Sub-Agent',
                    $aiServiceResponse,
                    $jobId,
                    $aiServiceModel->getModelSlug()
                );
            }

            $summary = $this->extractResponseContent($aiServiceResponse);
            
            if (empty($summary)) {
                return null;
            }

            // Prepend document info to the summary
            return "Document: {$documentTitle}\n\n" . trim($summary);

        } catch (\Exception $e) {
            $this->logger->warning('Failed to generate document summary', [
                'error' => $e->getMessage(),
                'documentTitle' => $documentTitle
            ]);
            return null;
        }
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

    // ========================================
    // RELATIONSHIP ANALYSIS SUB-AGENT
    // ========================================

    /**
     * Analyze a memory and detect relationships to existing memories
     * Uses LLM Sub-Agent for intelligent semantic analysis
     * 
     * Can be called:
     * 1. Automatically after memoryStore (when analyzeRelationships=true)
     * 2. Manually by Spirit when needed
     * 3. During consolidation batch process
     * 
     * @param array $arguments [
     *   'memoryId' => string (required) - ID of the memory to analyze
     *   'categories' => array (optional) - Categories to compare against (default: all)
     *   'maxComparisons' => int (optional) - Max memories to compare per category (default: 50)
     * ]
     */
    public function memoryAnalyzeRelationships(array $arguments): array
    {
        $packOpenedHere = false;
        
        try {
            // Validate required arguments
            if (!isset($arguments['memoryId']) || empty($arguments['memoryId'])) {
                return [
                    'success' => false,
                    'error' => 'memoryId is required'
                ];
            }

            $memoryId = $arguments['memoryId'];
            $maxComparisons = $arguments['maxComparisons'] ?? 50;

            // Open Spirit's pack if not already open (when called from memoryStore, pack is already open)
            if (empty($arguments['_packAlreadyOpen'])) {
                $spiritId = $arguments['spiritId'] ?? null;
                if (!$spiritId) {
                    return [
                        'success' => false,
                        'error' => 'spiritId is required when pack is not already open'
                    ];
                }
                $this->openSpiritPack($spiritId);
                $packOpenedHere = true;
            }

            // Get the memory to analyze from the pack
            $memory = $this->packService->findNodeById($memoryId);
            if (!$memory) {
                if ($packOpenedHere) $this->packService->close();
                return [
                    'success' => false,
                    'error' => "Memory not found: {$memoryId}"
                ];
            }

            // Determine which categories to compare against
            $categoriesToCompare = $arguments['categories'] ?? null;
            if (!$categoriesToCompare) {
                $categoriesToCompare = $this->packService->getCategories();
            }

            // Collect existing memories to compare against
            $existingMemories = [];
            foreach ($categoriesToCompare as $category) {
                $categoryMemories = $this->packService->findByCategory($category);
                foreach ($categoryMemories as $existingMemory) {
                    if ($existingMemory->getId() === $memoryId) {
                        continue;
                    }
                    if (count($existingMemories) >= $maxComparisons * count($categoriesToCompare)) {
                        break 2;
                    }
                    $existingMemories[] = [
                        'id' => $existingMemory->getId(),
                        'content' => $existingMemory->getContent(),
                        'summary' => $existingMemory->getSummary(),
                        'category' => $existingMemory->getCategory(),
                        'importance' => $existingMemory->getImportance(),
                        'tags' => $this->packService->getTagsForNode($existingMemory->getId())
                    ];
                }
            }

            // If no existing memories to compare, nothing to do
            if (empty($existingMemories)) {
                if ($packOpenedHere) $this->packService->close();
                return [
                    'success' => true,
                    'message' => 'No existing memories to compare against',
                    'relationshipsCreated' => 0,
                    'relationships' => []
                ];
            }

            // Get AI model for the Relationship Analyzer Sub-Agent
            $aiServiceModel = null;
            $gateway = $this->aiGatewayService->findByName('CQ AI Gateway');
            if ($gateway) {
                $aiServiceModel = $this->aiServiceModelService->findByModelSlug('citadelquest/kael', $gateway->getId());
                if (!$aiServiceModel) {
                    $aiServiceModel = $this->aiServiceModelService->findByModelSlug('citadelquest/grok-4.1-fast', $gateway->getId());
                }
            }
            if (!$aiServiceModel) {
                if ($packOpenedHere) $this->packService->close();
                return [
                    'success' => false,
                    'error' => 'No AI service model configured for Relationship Analyzer'
                ];
            }

            // Build the system prompt for the Relationship Analyzer
            $systemPrompt = $this->buildRelationshipAnalyzerSystemPrompt();

            // Build user message with the memory and existing memories
            $userMessage = $this->buildRelationshipAnalyzerUserMessage($memory, $existingMemories);

            // Create messages array
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage]
            ];

            // Get user locale
            $userLocale = $this->settingsService->getUserLocale();

            // Create AI service request
            $aiServiceRequest = $this->aiServiceRequestService->createRequest(
                $aiServiceModel->getId(),
                $messages,
                null, // max tokens
                0.3   // lower temperature for more consistent analysis
            );

            // Send request to AI Gateway
            $aiServiceResponse = $this->aiGatewayService->sendRequest(
                $aiServiceRequest,
                'memoryAnalyzeRelationships AI Tool - Relationship Analyzer Sub-Agent',
                $userLocale['lang'],
                'general'
            );

            // Log AI usage to pack if open
            if ($this->packService->isOpen()) {
                $this->packService->logAiUsageFromResponse(
                    'Relationship Analyzer Sub-Agent',
                    $aiServiceResponse,
                    null,
                    $aiServiceModel->getModelSlug()
                );
            }

            // Parse the JSON response
            $analysisResult = $this->parseRelationshipAnalyzerResponse($aiServiceResponse);

            if (!$analysisResult) {
                $this->logger->warning('Failed to parse relationship analyzer response', [
                    'memoryId' => $memoryId,
                    'response' => $this->extractResponseContent($aiServiceResponse)
                ]);
                if ($packOpenedHere) $this->packService->close();
                return [
                    'success' => false,
                    'error' => 'Failed to parse relationship analysis response'
                ];
            }

            // Create the detected relationships
            $createdRelationships = [];
            $relationships = $analysisResult['relationships'] ?? [];

            foreach ($relationships as $rel) {
                if (!isset($rel['existingMemoryId']) || !isset($rel['type'])) {
                    continue;
                }

                $existingMemoryId = $rel['existingMemoryId'];
                $type = strtoupper($rel['type']);
                $strength = $rel['strength'] ?? 0.8;
                $context = $rel['context'] ?? null;

                // Validate relationship type
                $validTypes = MemoryNode::getValidRelationTypes();

                if (!in_array($type, $validTypes)) {
                    $this->logger->warning('Invalid relationship type from analyzer', [
                        'type' => $type,
                        'memoryId' => $memoryId
                    ]);
                    continue;
                }

                // Check if relationship already exists
                if ($this->packService->relationshipExists($memoryId, $existingMemoryId, $type)) {
                    continue;
                }

                // Create the relationship
                try {
                    $relationship = $this->packService->createRelationship(
                        $memoryId,
                        $existingMemoryId,
                        $type,
                        $strength,
                        $context
                    );

                    $createdRelationships[] = [
                        'id' => $relationship->getId(),
                        'sourceId' => $memoryId,
                        'targetId' => $existingMemoryId,
                        'type' => $type,
                        'strength' => $strength,
                        'context' => $context
                    ];
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to create relationship', [
                        'error' => $e->getMessage(),
                        'sourceId' => $memoryId,
                        'targetId' => $existingMemoryId,
                        'type' => $type
                    ]);
                }
            }

            $this->logger->info('Memory relationships analyzed', [
                'memoryId' => $memoryId,
                'memoriesCompared' => count($existingMemories),
                'relationshipsCreated' => count($createdRelationships)
            ]);

            if ($packOpenedHere) $this->packService->close();

            return [
                'success' => true,
                'memoryId' => $memoryId,
                'memoriesCompared' => count($existingMemories),
                'relationshipsDetected' => count($relationships),
                'relationshipsCreated' => count($createdRelationships),
                'relationships' => $createdRelationships,
                'analysis' => $analysisResult['analysis'] ?? null
            ];

        } catch (\Exception $e) {
            if ($packOpenedHere) $this->packService->close();
            $this->logger->error('Error analyzing memory relationships', [
                'error' => $e->getMessage(),
                'arguments' => $arguments
            ]);
            return [
                'success' => false,
                'error' => 'Error analyzing relationships: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Build the system prompt for the Relationship Analyzer Sub-Agent
     */
    private function buildRelationshipAnalyzerSystemPrompt(): string
    {
        return <<<'PROMPT'
You are the Relationship Analyzer Agent. Your task is to analyze a NEW memory 
and identify meaningful relationships to EXISTING memories.

## Relationship Types (use ONLY these exact values):
- RELATES_TO: General semantic connection (topics overlap, similar subject matter)
- REINFORCES: New memory supports, confirms, or provides evidence for existing memory
- CONTRADICTS: New memory conflicts with or opposes existing memory (CRITICAL to detect!)

NOTE: Do NOT use PART_OF — structural relationships are handled separately.

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
- Additional evidence supporting existing memory
- Confirmation of previously stored information
- Examples that validate existing knowledge

### RELATES_TO
Use when there's a clear topical connection but doesn't fit CONTRADICTS or REINFORCES.
Be selective - don't create weak relationships.

## Output Format (JSON only, no other text):
{
    "relationships": [
        {
            "existingMemoryId": "uuid-of-existing-memory",
            "type": "CONTRADICTS",
            "strength": 0.9,
            "context": "Brief explanation of why this relationship exists"
        }
    ],
    "analysis": "Brief summary of the relationship analysis performed"
}

## Rules:
1. ONLY output the JSON object, nothing else
2. Only create relationships where there's a clear, meaningful connection
3. Strength should be 0.5-1.0 (0.5 = weak connection, 1.0 = very strong)
4. Context should be concise but informative (under 100 characters)
5. Quality over quantity - fewer strong relationships is better than many weak ones
6. If no meaningful relationships found, return empty relationships array
7. Pay special attention to CONTRADICTS - these prevent "pattern-bastardisation"
<clean_system_prompt>
PROMPT;
    }

    /**
     * Build the user message for the Relationship Analyzer
     */
    private function buildRelationshipAnalyzerUserMessage(MemoryNode $newMemory, array $existingMemories): string
    {
        $message = "## NEW MEMORY TO ANALYZE\n\n";
        $message .= "**ID**: " . $newMemory->getId() . "\n";
        $message .= "**Category**: " . $newMemory->getCategory() . "\n";
        $message .= "**Content**: " . $newMemory->getContent() . "\n";
        if ($newMemory->getSummary()) {
            $message .= "**Summary**: " . $newMemory->getSummary() . "\n";
        }
        $tags = $this->packService->getTagsForNode($newMemory->getId());
        if (!empty($tags)) {
            $message .= "**Tags**: " . implode(', ', $tags) . "\n";
        }

        $message .= "\n---\n\n";
        $message .= "## EXISTING MEMORIES TO COMPARE AGAINST\n\n";

        foreach ($existingMemories as $index => $mem) {
            $message .= "### Memory " . ($index + 1) . "\n";
            $message .= "**ID**: " . $mem['id'] . "\n";
            $message .= "**Category**: " . $mem['category'] . "\n";
            $message .= "**Content**: " . $mem['content'] . "\n";
            if (!empty($mem['summary'])) {
                $message .= "**Summary**: " . $mem['summary'] . "\n";
            }
            if (!empty($mem['tags'])) {
                $message .= "**Tags**: " . implode(', ', $mem['tags']) . "\n";
            }
            $message .= "\n";
        }

        $message .= "---\n\n";
        $message .= "Analyze the NEW MEMORY and identify any meaningful relationships to the EXISTING MEMORIES.\n";
        $message .= "Respond with ONLY the JSON object, no other text.";

        return $message;
    }

    /**
     * Parse the Relationship Analyzer's JSON response
     */
    private function parseRelationshipAnalyzerResponse($aiServiceResponse): ?array
    {
        $content = $this->extractResponseContent($aiServiceResponse);

        if (empty($content)) {
            return null;
        }

        // Try to extract JSON from the response
        // First, try direct parse
        $decoded = json_decode($content, true);
        if ($decoded && isset($decoded['relationships']) && is_array($decoded['relationships'])) {
            return $decoded;
        }

        // Try to find JSON in markdown code block
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $content, $matches)) {
            $decoded = json_decode($matches[1], true);
            if ($decoded && isset($decoded['relationships']) && is_array($decoded['relationships'])) {
                return $decoded;
            }
        }

        // Try to find raw JSON object
        if (preg_match('/\{[\s\S]*"relationships"[\s\S]*\}/', $content, $matches)) {
            $decoded = json_decode($matches[0], true);
            if ($decoded && isset($decoded['relationships']) && is_array($decoded['relationships'])) {
                return $decoded;
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
                case 'conversation':
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
     * 
     * For PDF files, automatically checks for .anno annotations first (uses AnnoService)
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
            
            // For PDF files, check for annotations first (uses AnnoService)
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if ($extension === 'pdf') {
                $annoData = $this->annoService->readAnnotation(AnnoService::TYPE_PDF, $filename, $projectId, false);
                
                if ($annoData && $this->annoService->verifyPdfAnnotation($annoData, $filename)) {
                    $content = $this->annoService->getTextContent($annoData);
                    
                    return [
                        'success' => true,
                        'content' => $content,
                        'fileName' => $filename,
                        'filePath' => $path,
                        'usedAnnotation' => true
                    ];
                }
                
                // send binary PDF file - will be auto-parsed
                $content = $this->projectFileService->getFileContent($file->getId());
                return [
                    'success' => true,
                    'content' => [
                        'file_data' => $content,
                        'filename' => $filename
                    ],
                    'fileName' => $filename,
                    'filePath' => $path,
                    'usedAnnotation' => false
                ];
            }
            
            $content = $this->projectFileService->getFileContent($file->getId());
            
            // Check if content is binary/base64 (for non-PDF files)
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
     * Strip common sourceRef prefixes for conversation aliases
     */
    private function stripConversationPrefix(string $sourceRef): string
    {
        foreach (['spirit_conversation:', 'conversation:'] as $prefix) {
            if (str_starts_with($sourceRef, $prefix)) {
                return substr($sourceRef, strlen($prefix));
            }
        }
        return $sourceRef;
    }

    /**
     * Check if sourceRef is the "all" alias for batch conversation extraction
     */
    private function isConversationAllAlias(string $sourceRef): bool
    {
        return strtolower(trim($this->stripConversationPrefix($sourceRef))) === 'all';
    }

    /**
     * Resolve conversation alias (current/active/now) to the actual conversation ID
     * 
     * Strips any prefix (spirit_conversation:, conversation:) and checks if the
     * remaining value is an alias. If so, queries the latest conversation for the spirit.
     * Returns the resolved conversation ID (or the original value if not an alias).
     */
    private function resolveConversationAlias(string $sourceRef, string $spiritId): string
    {
        $ref = $this->stripConversationPrefix($sourceRef);

        // Check if the remaining value is an alias for "current conversation"
        if (in_array(strtolower(trim($ref)), ['current', 'active', 'now'], true)) {
            $db = $this->userDatabaseManager->getDatabaseConnection($this->user);
            $convId = $db->executeQuery(
                'SELECT id FROM spirit_conversation WHERE spirit_id = ? ORDER BY last_interaction DESC LIMIT 1',
                [$spiritId]
            )->fetchOne();

            if ($convId) {
                return $convId;
            }
        }

        // Always return stripped value (bare UUID) for consistent source_ref format
        return $ref;
    }

    /**
     * Extract memories from ALL Spirit conversations (batch operation)
     * 
     * Iterates through all conversations for the Spirit, skips already-extracted ones
     * (unless force=true), and triggers separate extraction for each new conversation.
     * Each conversation gets its own source_ref for proper duplicate tracking.
     */
    private function memoryExtractAllConversations(string $spiritId, array $originalArguments): array
    {
        $force = $originalArguments['force'] ?? false;
        $maxDepth = $originalArguments['maxDepth'] ?? 3;
        $targetPack = $this->getSpiritTargetPack($spiritId);

        // Get all conversations for this Spirit
        $db = $this->userDatabaseManager->getDatabaseConnection($this->user);
        $conversations = $db->executeQuery(
            'SELECT id, title, last_interaction FROM spirit_conversation WHERE spirit_id = ? ORDER BY last_interaction ASC',
            [$spiritId]
        )->fetchAllAssociative();

        if (empty($conversations)) {
            return [
                'success' => false,
                'error' => 'No conversations found for this Spirit.'
            ];
        }

        // Open pack once for duplicate checking
        $this->packService->open($targetPack['projectId'], $targetPack['path'], $targetPack['name']);

        $results = [];
        $skipped = 0;
        $queued = 0;
        $errors = 0;

        foreach ($conversations as $conv) {
            $convId = $conv['id'];
            $convTitle = $conv['title'] ?? $convId;

            // Check for duplicate extraction (per conversation)
            if (!$force) {
                $existing = $this->packService->hasExtractedFromSource('spirit_conversation', $convId);
                if ($existing) {
                    $skipped++;
                    $results[] = [
                        'conversationId' => $convId,
                        'title' => $convTitle,
                        'status' => 'skipped',
                        'reason' => "Already extracted ({$existing['count']} memories on {$existing['lastExtracted']})"
                    ];
                    continue;
                }
            }

            // Close pack before extraction (memoryExtractToPack will reopen it)
            $this->packService->close();

            // Extract this conversation
            $result = $this->memoryExtractToPack([
                'targetPack' => $targetPack,
                'sourceType' => 'spirit_conversation',
                'sourceRef' => $convId,
                'content' => null,
                'maxDepth' => $maxDepth,
                'documentTitle' => $convTitle,
            ]);

            // Reopen pack for next iteration's duplicate check
            $this->packService->open($targetPack['projectId'], $targetPack['path'], $targetPack['name']);

            if ($result['success'] ?? false) {
                $queued++;
                $results[] = [
                    'conversationId' => $convId,
                    'title' => $convTitle,
                    'status' => ($result['async'] ?? false) ? 'queued' : 'extracted',
                    'jobId' => $result['jobId'] ?? null,
                    'extractedCount' => $result['storedCount'] ?? $result['extractedCount'] ?? 0,
                ];
            } else {
                $errors++;
                $results[] = [
                    'conversationId' => $convId,
                    'title' => $convTitle,
                    'status' => 'error',
                    'error' => $result['error'] ?? 'Unknown error',
                ];
            }
        }

        $this->packService->close();

        // Send summary notification
        if ($this->user) {
            $total = count($conversations);
            $this->notificationService->createNotification(
                $this->user,
                '📚 Batch Conversation Extraction',
                "Processed {$total} conversations: {$queued} queued, {$skipped} skipped (already extracted), {$errors} errors.",
                $errors > 0 ? 'warning' : 'success'
            );
        }

        return [
            'success' => true,
            'batch' => true,
            'totalConversations' => count($conversations),
            'queued' => $queued,
            'skipped' => $skipped,
            'errors' => $errors,
            'results' => $results,
            'message' => "Batch extraction: {$queued} conversations queued for extraction, {$skipped} already extracted, {$errors} errors out of " . count($conversations) . " total."
        ];
    }

    /**
     * Load content from a spirit conversation
     * Format: conversation ID (raw or prefixed with "conversation:")
     * 
     * Filters out:
     * - First user message (system_prompt injected as first user message)
     * - tool_result type messages (raw tool output)
     * - tool_use type messages (assistant tool call metadata)
     * - frontendData keys inside content arrays
     * 
     * Adds metadata header with conversation title, spirit name, timestamps
     */
    private function loadFromConversation(string $conversationId): array
    {
        try {
            // Strip "conversation:" prefix if present (GUI sends this format)
            if (str_starts_with($conversationId, 'conversation:')) {
                $conversationId = substr($conversationId, strlen('conversation:'));
            }

            // Load conversation metadata directly (avoid circular dependency with SpiritConversationService)
            $db = $this->userDatabaseManager->getDatabaseConnection($this->user);
            $convData = $db->executeQuery(
                'SELECT id, spirit_id, title, created_at, last_interaction FROM spirit_conversation WHERE id = ?',
                [$conversationId]
            )->fetchAssociative();

            if (!$convData) {
                return [
                    'success' => false,
                    'error' => "Conversation not found: {$conversationId}"
                ];
            }

            $messages = $this->spiritConversationMessageService->getMessagesByConversation($conversationId);
            
            if (empty($messages)) {
                return [
                    'success' => false,
                    'error' => "Conversation has no messages: {$conversationId}"
                ];
            }

            // Get spirit name for metadata
            $spirit = $this->spiritService->findById($convData['spirit_id']);
            $spiritName = $spirit ? $spirit->getName() : 'Spirit';

            // Build metadata header
            $header = [];
            $header[] = '=== Spirit Conversation: "' . $convData['title'] . '" ===';
            $header[] = 'Spirit: ' . $spiritName 
                . ' | Created: ' . $convData['created_at'] 
                . ' | Last: ' . $convData['last_interaction'];
            $header[] = '---';

            // Build filtered conversation transcript
            $transcript = [];
            $isFirstUserMessage = true;
            $keptCount = 0;

            foreach ($messages as $msg) {
                $role = $msg->getRole();
                $type = $msg->getType();

                // Skip tool_result messages (raw tool output JSON)
                if ($type === 'tool_result') {
                    continue;
                }

                // Skip tool_use messages (assistant tool call metadata)
                if ($type === 'tool_use') {
                    continue;
                }

                // Skip tool role messages entirely
                if ($role === 'tool') {
                    continue;
                }

                // Skip the first user message (system_prompt injected as first user message)
                if ($role === 'user' && $isFirstUserMessage) {
                    $isFirstUserMessage = false;
                    continue;
                }
                if ($role === 'user') {
                    $isFirstUserMessage = false;
                }

                // Extract text content, filtering out frontendData
                $content = $msg->getContent();
                $textContent = $this->extractTextFromMessageContent($content);

                if (empty(trim($textContent))) {
                    continue;
                }

                // Format role label
                $roleLabel = $role === 'user' ? 'User' : $spiritName;
                $transcript[] = "[{$roleLabel}]: {$textContent}";
                $keptCount++;
            }

            if (empty($transcript)) {
                return [
                    'success' => false,
                    'error' => "No extractable messages in conversation: {$conversationId}"
                ];
            }

            // Combine header + transcript
            $fullContent = implode("\n", $header) . "\n" . implode("\n\n", $transcript);

            return [
                'success' => true,
                'content' => $fullContent,
                'conversationId' => $conversationId,
                'messageCount' => $keptCount,
                'title' => $convData['title']
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Error loading conversation: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Extract text content from a message content array, filtering out frontendData
     * 
     * Message content can be:
     * - A simple string (wrapped in array as single element)
     * - An array of content parts (multimodal: text, image_url, etc.)
     * - An array with frontendData keys to filter out
     */
    private function extractTextFromMessageContent(array $content): string
    {
        // Check if content is a simple string wrapped in the message's content array
        // Messages store content as JSON arrays; a plain text message is stored as the raw string
        // but getContent() returns it decoded - could be a string in an array or nested parts
        
        // If content has numeric keys, it's an array of parts (multimodal content)
        if (isset($content[0])) {
            $textParts = [];
            foreach ($content as $part) {
                if (!is_array($part)) {
                    // Simple string element
                    $textParts[] = (string)$part;
                    continue;
                }
                // Skip frontendData parts
                if (isset($part['frontendData'])) {
                    continue;
                }
                // Extract text from typed content parts
                if (isset($part['type']) && $part['type'] === 'text' && isset($part['text'])) {
                    $textParts[] = $part['text'];
                }
            }
            return implode("\n", $textParts);
        }

        // Associative array - could be a single content object
        // Filter out frontendData key
        if (isset($content['frontendData'])) {
            unset($content['frontendData']);
        }

        // If it has a 'text' key, use that
        if (isset($content['text'])) {
            return $content['text'];
        }

        // If it has a 'content' key (nested), extract from it
        if (isset($content['content']) && is_string($content['content'])) {
            return $content['content'];
        }

        // Fallback: try to get any string value
        foreach ($content as $key => $value) {
            if (is_string($value) && !empty($value) && $key !== 'role' && $key !== 'type') {
                return $value;
            }
        }

        return '';
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

    /**
     * Extract content blocks from a large content piece using AI
     * This is the core of the recursive/fractal extraction system
     * 
     * @param string $content The content to split into blocks
     * @param string $documentTitle Title/context for the content
     * @param object $aiServiceModel The AI model to use
     * @param int $startLineOffset Line offset for nested blocks (default 1)
     * @return array|null Array of blocks or null on failure
     */
    public function extractContentBlocks(string $content, string $documentTitle, $aiServiceModel, int $startLineOffset = 1, ?string $jobId = null): ?array
    {
        try {
            $systemPrompt = $this->buildContentBlockExtractorSystemPrompt();
            
            // Add line numbers to content (like getFileContent does)
            $contentWithLineNumbers = $this->addLineNumbers($content, $startLineOffset);
            $lines = explode("\n", $content);
            $totalLines = count($lines);
            
            $userMessage = "Split the following content into logical sections/blocks:\n\n";
            $userMessage .= "**Document Title**: {$documentTitle}\n\n";
            $userMessage .= "**Total Lines**: {$totalLines} (lines {$startLineOffset} to " . ($startLineOffset + $totalLines - 1) . ")\n\n";
            // Inject contextual warning if content has obfuscated data
            $dataWarning = $this->buildDataIntegrityWarning($content);
            if ($dataWarning) {
                $userMessage .= $dataWarning . "\n";
            }
            $userMessage .= "---\n\n";
            $userMessage .= $contentWithLineNumbers;
            $userMessage .= "\n\n---\n\n";
            $userMessage .= "Respond with ONLY the JSON object containing the blocks.";

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage]
            ];

            $userLocale = $this->settingsService->getUserLocale();

            $aiServiceRequest = $this->aiServiceRequestService->createRequest(
                $aiServiceModel->getId(),
                $messages,
                null,
                0.3
            );

            $aiServiceResponse = $this->aiGatewayService->sendRequest(
                $aiServiceRequest,
                'memoryExtract AI Tool - Content Block Extractor Sub-Agent',
                $userLocale['lang'],
                'general'
            );

            // Log AI usage to pack if open
            if ($this->packService->isOpen()) {
                $this->packService->logAiUsageFromResponse(
                    'Content Block Extractor Sub-Agent',
                    $aiServiceResponse,
                    $jobId,
                    $aiServiceModel->getModelSlug()
                );
            }

            return $this->parseContentBlockExtractorResponse($aiServiceResponse, $content, $startLineOffset);

        } catch (\Exception $e) {
            $this->logger->error('Failed to extract content blocks', [
                'error' => $e->getMessage(),
                'documentTitle' => $documentTitle
            ]);
            return null;
        }
    }

    /**
     * Build the system prompt for the Content Block Extractor
     */
    private function buildContentBlockExtractorSystemPrompt(): string
    {
        return <<<'PROMPT'
You are the Content Block Extractor Agent. Your task is to analyze content and split it into logical sections/blocks for hierarchical memory extraction.

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
- If data like email addresses is obfuscated (e.g. `[email protected]`), do NOT invent the real address.
PROMPT;
    }

    /**
     * Parse the Content Block Extractor's JSON response
     * Uses line numbers instead of character positions
     */
    private function parseContentBlockExtractorResponse($aiServiceResponse, string $originalContent, int $startLineOffset = 1): ?array
    {
        $responseContent = $this->extractResponseContent($aiServiceResponse);
        
        if (empty($responseContent)) {
            return null;
        }

        // Try to extract JSON from the response
        $json = $responseContent;
        
        // Remove markdown code blocks if present
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $json, $matches)) {
            $json = $matches[1];
        }
        
        $json = trim($json);
        
        $data = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['blocks'])) {
            $this->logger->warning('Failed to parse content block extractor response', [
                'error' => json_last_error_msg(),
                'response' => substr($responseContent, 0, 500)
            ]);
            return null;
        }

        // Split content into lines for extraction
        $lines = explode("\n", $originalContent);
        $totalLines = count($lines);
        $blocks = [];
        
        foreach ($data['blocks'] as $block) {
            // Get line numbers (1-indexed from AI, convert to 0-indexed for array access)
            $startLine = max(1, (int)($block['start_line'] ?? 1));
            $endLine = min($totalLines, (int)($block['end_line'] ?? $totalLines));
            
            // Ensure valid range
            if ($endLine < $startLine) {
                continue;
            }
            
            // Extract content for this block (lines are 0-indexed in array)
            $blockLines = array_slice($lines, $startLine - 1, $endLine - $startLine + 1);
            $blockContent = implode("\n", $blockLines);
            
            $blocks[] = [
                'title' => $block['title'] ?? 'Untitled Section',
                'summary' => $block['summary'] ?? '',
                'start_line' => $startLine,
                'end_line' => $endLine,
                'is_leaf' => (bool)($block['is_leaf'] ?? false),
                'content' => $blockContent,
                'tags' => $block['tags'] ?? []
            ];
        }

        if (empty($blocks)) {
            return null;
        }

        return [
            'blocks' => $blocks,
            'document_summary' => $data['document_summary'] ?? '',
            'document_tags' => $data['document_tags'] ?? []
        ];
    }

    /**
     * Add line numbers to content (like getFileContent does)
     */
    private function addLineNumbers(string $content, int $startLine = 1): string
    {
        $lines = explode("\n", $content);
        $numberedLines = [];
        foreach ($lines as $index => $line) {
            $lineNumber = $startLine + $index;
            $numberedLines[] = sprintf('%4d: %s', $lineNumber, $line);
        }
        return implode("\n", $numberedLines);
    }

    /**
     * Get the AI model for extraction operations
     */
    private function getExtractionModel()
    {
        $gateway = $this->aiGatewayService->findByName('CQ AI Gateway');
        if (!$gateway) {
            return null;
        }
        
        $model = $this->aiServiceModelService->findByModelSlug('citadelquest/kael', $gateway->getId());
        if (!$model) {
            $model = $this->aiServiceModelService->findByModelSlug('citadelquest/grok-4.1-fast', $gateway->getId());
        }
        
        return $model;
    }

    // Legacy startRecursiveExtractionJob removed
    // All extraction now uses startPackRecursiveExtractionJob()

    // ========================================
    // PACK-BASED EXTRACTION (Single Source of Truth)
    // ========================================

    /**
     * Extract memories from content and store in a Memory Pack
     * 
     * Single source of truth for all extraction - both Spirit and non-Spirit contexts.
     * All nodes, relationships, and jobs are stored in the target pack file.
     * 
     * @param array $arguments [
     *   'targetPack' => ['projectId' => string, 'path' => string, 'name' => string],
     *   'sourceType' => string (document|url|etc),
     *   'sourceRef' => string (projectId:path:filename format),
     *   'content' => string (optional, if not using sourceRef),
     *   'maxDepth' => int (default 3)
     * ]
     */
    public function memoryExtractToPack(array $arguments): array
    {
        try {
            $targetPack = $arguments['targetPack'];
            $sourceType = $arguments['sourceType'] ?? 'document';
            $sourceRef = $arguments['sourceRef'] ?? null;
            $maxDepth = $arguments['maxDepth'] ?? 3;

            // Try to auto-load content if not provided
            $content = $arguments['content'] ?? null;
            $loadResult = null;
            
            if (empty($content) && $sourceType && $sourceRef) {
                $loadResult = $this->loadContentFromSource($sourceType, $sourceRef);
                if (!$loadResult['success']) {
                    return $loadResult;
                }
                $content = $loadResult['content'];
            }

            if (empty($content)) {
                return [
                    'success' => false,
                    'error' => 'Content is required. Either provide content directly, or specify sourceType and sourceRef.'
                ];
            }

            // Generate sourceRef for text input if not provided
            if (empty($sourceRef) && $sourceType === 'derived') {
                $sourceRef = 'text-input:' . date('Y-m-d_H:i:s');
            }

            // Determine document title
            $documentTitle = $arguments['documentTitle'] ?? $sourceRef ?? 'Untitled Document';
            if (isset($loadResult['fileName'])) {
                $documentTitle = $loadResult['fileName'];
            } elseif (isset($loadResult['title'])) {
                $documentTitle = $loadResult['title'];
            }

            // Store original source content in the pack for portability
            $sourceContentToStore = is_string($content) ? $content : null;
            $contentLength = is_string($content) ? strlen($content) : 0;

            // Always use async extraction — job creation is instant (deferred init)
            $job = $this->startPackRecursiveExtractionJob(
                $targetPack,
                $content,
                $sourceType,
                $sourceRef ?? 'direct-content',
                $documentTitle,
                $maxDepth,
                $sourceContentToStore
            );

            return [
                'success' => true,
                'async' => true,
                'jobId' => $job->getId(),
                'targetPack' => $targetPack,
                'message' => "Extraction job queued ({$contentLength} chars). " .
                    "Processing will continue in background.",
                'initialProgress' => [
                    'progress' => $job->getProgress(),
                    'totalSteps' => $job->getTotalSteps(),
                    'type' => $job->getType()
                ],
                'rootNode' => null
            ];

        } catch (\Exception $e) {
            $this->logger->error('Error extracting to pack', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => 'Error extracting memories: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Start a recursive extraction job that stores to a Pack
     */
    public function startPackRecursiveExtractionJob(
        array $targetPack,
        string $content,
        string $sourceType,
        string $sourceRef,
        string $documentTitle,
        int $maxDepth = 3,
        ?string $sourceContentToStore = null
    ): MemoryJob {
        // Open pack
        $this->packService->open($targetPack['projectId'], $targetPack['path'], $targetPack['name']);

        // Store original source content in the pack for portability
        if ($sourceContentToStore && $sourceRef) {
            $this->packService->storeSource($sourceRef, $sourceType, $sourceContentToStore, $documentTitle);
        }

        // Create job immediately — all AI calls deferred to first job step (needs_init)
        // This makes job creation instant, preventing timeouts on batch operations
        $job = $this->packService->createJob(
            MemoryJob::TYPE_EXTRACT_RECURSIVE,
            [
                'target_pack' => $targetPack,
                'source_type' => $sourceType,
                'source_ref' => $sourceRef,
                'document_title' => $documentTitle,
                'max_depth' => $maxDepth,
                'needs_init' => true,
                'raw_content' => $content,
                'pending_blocks' => [],
                'processed_count' => 0,
                'current_depth' => 1,
                'extracted_node_ids' => []
            ]
        );

        $this->packService->updateJobProgress($job->getId(), 0, 1);
        $this->packService->startJob($job->getId());

        $this->logger->info('Started pack extraction job (deferred init)', [
            'jobId' => $job->getId(),
            'packName' => $targetPack['name'],
            'sourceRef' => $sourceRef,
            'contentLength' => strlen($content)
        ]);

        // Close pack - polling endpoint will reopen it for processing
        $this->packService->close();

        return $job;
    }

    /**
     * Process a single step of a pack extraction job
     * Called during polling
     */
    public function processPackExtractionJobStep(array $targetPack, string $jobId): bool
    {
        try {
            $this->packService->open($targetPack['projectId'], $targetPack['path'], $targetPack['name']);
            
            $job = $this->packService->findJobById($jobId);
            if (!$job) {
                $this->packService->close();
                return true; // Job not found, consider done
            }

            if ($job->getStatus() === MemoryJob::STATUS_COMPLETED || $job->getStatus() === MemoryJob::STATUS_FAILED) {
                $this->packService->close();
                return true; // Already done
            }

            $payload = $job->getPayload();
            
            // Get AI model
            $aiServiceModel = $this->getExtractionModel();
            if (!$aiServiceModel) {
                $this->packService->failJob($jobId, 'No AI model available');
                $this->packService->close();
                return true;
            }

            $maxDepth = $payload['max_depth'] ?? 3;

            // Deferred initialization: generate document summary + extract content blocks
            // This was moved out of startPackRecursiveExtractionJob to make job creation instant
            if (!empty($payload['needs_init'])) {
                $content = $payload['raw_content'] ?? '';
                $documentTitle = $payload['document_title'] ?? 'Untitled Document';
                $sourceRef = $payload['source_ref'] ?? 'direct-content';
                $sourceType = $payload['source_type'] ?? 'document';

                // Generate document summary (AI call)
                $summaryContent = $this->generateDocumentSummary($content, $documentTitle, $aiServiceModel, $jobId);
                if (!$summaryContent) {
                    $summaryContent = "Document: {$documentTitle}";
                }

                $totalLines = count(explode("\n", $content));

                // Extract initial content blocks (AI call)
                $blocks = $this->extractContentBlocks($content, $documentTitle, $aiServiceModel, 1, $jobId);

                $documentTags = array_merge(
                    ['document', 'root', 'summary'],
                    $blocks['document_tags'] ?? []
                );

                $rootNode = $this->packService->storeNode(
                    $summaryContent,
                    MemoryNode::CATEGORY_KNOWLEDGE,
                    0.9,
                    "Document: {$documentTitle}",
                    'document_summary',
                    $sourceRef,
                    $documentTags,
                    null,
                    '1:' . $totalLines
                );

                $pendingBlocks = [];
                if ($blocks && !empty($blocks['blocks'])) {
                    foreach ($blocks['blocks'] as $block) {
                        $block['parent_node_id'] = $rootNode->getId();
                        $block['depth'] = 1;
                        $pendingBlocks[] = $block;
                    }
                } else {
                    $pendingBlocks[] = [
                        'title' => $documentTitle,
                        'summary' => substr($content, 0, 100),
                        'start_line' => 1,
                        'end_line' => $totalLines,
                        'is_leaf' => true,
                        'content' => $content,
                        'parent_node_id' => $rootNode->getId(),
                        'depth' => 1,
                        'tags' => []
                    ];
                }

                // Update payload: remove raw_content (free memory), set up blocks
                $payload['needs_init'] = false;
                unset($payload['raw_content']);
                $payload['root_node_id'] = $rootNode->getId();
                $payload['pending_blocks'] = $pendingBlocks;
                $payload['processed_count'] = 1;
                $payload['extracted_node_ids'] = [$rootNode->getId()];

                $this->packService->updateJobProgress($jobId, 1, 1 + count($pendingBlocks));
                $this->packService->updateJobPayload($jobId, $payload);
                $this->packService->close();

                $this->logger->info('Initialized pack extraction job', [
                    'jobId' => $jobId,
                    'documentTitle' => $documentTitle,
                    'initialBlocks' => count($pendingBlocks)
                ]);

                return false; // More steps to process
            }

            $pendingBlocks = $payload['pending_blocks'] ?? [];
            $processedCount = $payload['processed_count'] ?? 0;

            // If no pending blocks, extraction is done
            if (empty($pendingBlocks)) {
                $extractedNodeIds = $payload['extracted_node_ids'] ?? [];
                
                $this->packService->completeJob($jobId, [
                    'total_memories' => $processedCount,
                    'message' => "Extraction complete. Created {$processedCount} memory nodes."
                ]);

                // Create relationship analysis job
                if (!empty($extractedNodeIds)) {
                    $analysisJob = $this->packService->createJob(
                        MemoryJob::TYPE_ANALYZE_RELATIONSHIPS,
                        [
                            'target_pack' => $targetPack,
                            'pending_node_ids' => $extractedNodeIds,
                            'processed_count' => 0,
                            'relationships_created' => 0,
                            'source_job_id' => $jobId,
                            'document_title' => $payload['document_title'] ?? null
                        ]
                    );
                    $this->packService->updateJobProgress($analysisJob->getId(), 0, count($extractedNodeIds));
                }

                $this->packService->close();
                return true;
            }

            // Process ONE block per step
            $block = array_shift($pendingBlocks);
            $blockContent = $block['content'];
            $blockTitle = $block['title'];
            $parentNodeId = $block['parent_node_id'] ?? null;
            $sourceRef = $payload['source_ref'];
            $sourceType = $payload['source_type'];
            $blockDepth = $block['depth'] ?? 1;

            // Generate summary for this block
            $summaryContent = $this->generateDocumentSummary($blockContent, $blockTitle, $aiServiceModel, $jobId);
            if (!$summaryContent) {
                $summaryContent = $block['summary'] ?? "Section: {$blockTitle}";
            }

            $sourceRange = $block['start_line'] . ':' . $block['end_line'];
            
            $blockTags = array_merge(
                ['document', 'section', 'depth-' . $blockDepth],
                $block['tags'] ?? []
            );

            $summaryNode = $this->packService->storeNode(
                $summaryContent,
                MemoryNode::CATEGORY_KNOWLEDGE,
                0.8,
                "Section: {$blockTitle}",
                $sourceType,
                $sourceRef,
                $blockTags,
                null,
                $sourceRange
            );

            // Create PART_OF relationship to parent
            if ($parentNodeId) {
                $this->packService->createRelationship(
                    $summaryNode->getId(),
                    $parentNodeId,
                    MemoryNode::RELATION_PART_OF,
                    0.9,
                    "Child section of parent document/section"
                );
            }

            $processedCount++;
            
            $extractedNodeIds = $payload['extracted_node_ids'] ?? [];
            $extractedNodeIds[] = $summaryNode->getId();

            // If not a leaf and not at max depth, extract sub-blocks
            $blockLines = count(explode("\n", $blockContent));
            if (!$block['is_leaf'] && $blockDepth < $maxDepth && $blockLines > 30) {
                $subBlocks = $this->extractContentBlocks($blockContent, $blockTitle, $aiServiceModel, $block['start_line'], $jobId);
                
                if ($subBlocks && count($subBlocks['blocks']) > 1) {
                    foreach ($subBlocks['blocks'] as $subBlock) {
                        $subBlock['parent_node_id'] = $summaryNode->getId();
                        $subBlock['depth'] = $blockDepth + 1;
                        $pendingBlocks[] = $subBlock;
                    }
                }
            }

            // Update job payload
            $payload['pending_blocks'] = $pendingBlocks;
            $payload['processed_count'] = $processedCount;
            $payload['extracted_node_ids'] = $extractedNodeIds;
            $payload['current_block_title'] = $blockTitle;
            
            // Update job in pack DB
            $this->packService->updateJobProgress($jobId, $processedCount, $processedCount + count($pendingBlocks));
            
            // Update payload
            $this->packService->updateJobPayload($jobId, $payload);

            $this->packService->close();

            $this->logger->info('Processed pack extraction job step', [
                'jobId' => $jobId,
                'blockTitle' => $blockTitle,
                'processedCount' => $processedCount,
                'pendingCount' => count($pendingBlocks)
            ]);

            return empty($pendingBlocks);

        } catch (\Exception $e) {
            $this->logger->error('Error processing pack extraction job step', [
                'jobId' => $jobId,
                'error' => $e->getMessage()
            ]);
            try {
                $this->packService->failJob($jobId, $e->getMessage());
            } catch (\Exception $e2) {
                // Ignore
            }
            $this->packService->close();
            return true;
        }
    }


    /**
     * Process a single step of pack relationship analysis job
     */
    public function processPackRelationshipAnalysisJobStep(array $targetPack, string $jobId): bool
    {
        try {
            $this->packService->open($targetPack['projectId'], $targetPack['path'], $targetPack['name']);
            
            $job = $this->packService->findJobById($jobId);
            if (!$job || $job->getStatus() === MemoryJob::STATUS_COMPLETED || $job->getStatus() === MemoryJob::STATUS_FAILED) {
                $this->packService->close();
                return true;
            }

            $payload = $job->getPayload();
            $pendingNodeIds = $payload['pending_node_ids'] ?? [];
            $processedCount = $payload['processed_count'] ?? 0;
            $relationshipsCreated = $payload['relationships_created'] ?? 0;

            if (empty($pendingNodeIds)) {
                $this->packService->completeJob($jobId, [
                    'nodes_analyzed' => $processedCount,
                    'relationships_created' => $relationshipsCreated,
                    'message' => "Relationship analysis complete. Analyzed {$processedCount} nodes, created {$relationshipsCreated} relationships."
                ]);
                $this->packService->close();
                return true;
            }

            // Process ONE node per step
            $nodeId = array_shift($pendingNodeIds);
            $newRelationships = 0;

            try {
                $result = $this->memoryAnalyzeRelationships([
                    'memoryId' => $nodeId,
                    '_packAlreadyOpen' => true
                ]);
                if ($result['success'] ?? false) {
                    $newRelationships = $result['relationshipsCreated'] ?? 0;
                }
            } catch (\Exception $e) {
                $this->logger->warning('Failed to analyze relationships for pack node', [
                    'nodeId' => $nodeId,
                    'error' => $e->getMessage()
                ]);
            }

            $processedCount++;
            $relationshipsCreated += $newRelationships;

            // Update job
            $payload['pending_node_ids'] = $pendingNodeIds;
            $payload['processed_count'] = $processedCount;
            $payload['relationships_created'] = $relationshipsCreated;
            
            $this->packService->updateJobProgress($jobId, $processedCount, $processedCount + count($pendingNodeIds));
            
            // Update payload
            $this->packService->updateJobPayload($jobId, $payload);

            $this->packService->close();
            return empty($pendingNodeIds);

        } catch (\Exception $e) {
            $this->logger->error('Error processing pack relationship analysis job step', [
                'jobId' => $jobId,
                'error' => $e->getMessage()
            ]);
            try {
                $this->packService->failJob($jobId, $e->getMessage());
            } catch (\Exception $e2) {}
            $this->packService->close();
            return true;
        }
    }
}
