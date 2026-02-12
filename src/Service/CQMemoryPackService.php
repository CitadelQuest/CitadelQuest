<?php

namespace App\Service;

use App\Entity\MemoryNode;
use App\Entity\MemoryRelationship;
use App\Entity\MemoryTag;
use App\Entity\MemoryJob;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Service for managing CQ Memory Pack (.cqmpack) standalone SQLite files
 * 
 * A CQ Memory Pack is a portable, self-contained SQLite database file
 * containing memory nodes, relationships, tags, jobs, and metadata.
 */
class CQMemoryPackService
{
    public const PACK_VERSION = '1.0';
    public const FILE_EXTENSION = 'cqmpack';
    
    private ?Connection $connection = null;
    private ?string $currentPackPath = null;
    
    public function __construct(
        private readonly ProjectFileService $projectFileService,
        private readonly Security $security,
        private readonly ParameterBagInterface $params,
        private readonly LoggerInterface $logger
    ) {}
    
    /**
     * Get absolute filesystem path for SQLite connection
     */
    private function getAbsolutePath(string $projectId, string $path, string $name): string
    {
        $user = $this->security->getUser();
        $baseDir = $this->params->get('kernel.project_dir') . '/var/user_data/' . $user->getId() . '/p';
        $relativePath = trim($path, '/');
        
        if ($relativePath) {
            return $baseDir . '/' . $projectId . '/' . $relativePath . '/' . $name;
        }
        return $baseDir . '/' . $projectId . '/' . $name;
    }
    
    /**
     * Create a new memory pack file
     * 
     * @param string $projectId Project ID
     * @param string $path Directory path (e.g., '/spirit/memory/packs')
     * @param string $name Pack filename (without extension)
     * @param array $metadata Optional metadata
     */
    public function create(string $projectId, string $path, string $name, array $metadata = []): bool
    {
        // Ensure file has correct extension
        if (pathinfo($name, PATHINFO_EXTENSION) !== self::FILE_EXTENSION) {
            $name .= '.' . self::FILE_EXTENSION;
        }
        
        // Check if file already exists
        if ($this->projectFileService->findByPathAndName($projectId, $path, $name)) {
            throw new \RuntimeException("Pack file already exists: {$name}");
        }
        
        // Ensure parent directory path exists via ProjectFileService (filesystem + DB)
        $pathParts = explode('/', trim($path, '/'));
        $currentPath = '/';
        foreach ($pathParts as $part) {
            if (!$this->projectFileService->findByPathAndName($projectId, $currentPath, $part)) {
                $this->projectFileService->createDirectory($projectId, $currentPath, $part);
            }
            $currentPath = rtrim($currentPath, '/') . '/' . $part;
        }
        
        // Get absolute path for SQLite
        $absolutePath = $this->getAbsolutePath($projectId, $path, $name);
        
        // Create and initialize the database
        $this->openConnection($absolutePath);
        $this->initializeSchema();
        
        // Set default metadata
        $defaultMetadata = [
            'version' => self::PACK_VERSION,
            'name' => basename($name, '.' . self::FILE_EXTENSION),
            'description' => '',
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ];
        
        $metadata = array_merge($defaultMetadata, $metadata);
        
        foreach ($metadata as $key => $value) {
            $this->setMetadata($key, $value);
        }
        
        // Close connection to flush changes to disk
        $this->close();
        
        // Register the pack file with ProjectFileService (database record only, don't overwrite file)
        $this->projectFileService->registerExistingFile($projectId, $path, $name, 'application/x-sqlite3');
        
        $this->logger->info('Created new memory pack', ['projectId' => $projectId, 'path' => $path, 'name' => $name]);
        
        return true;
    }
    
    /**
     * Open an existing memory pack file
     * 
     * @param string $projectId Project ID
     * @param string $path Directory path
     * @param string $name Pack filename
     */
    public function open(string $projectId, string $path, string $name): bool
    {
        $file = $this->projectFileService->findByPathAndName($projectId, $path, $name);
        if (!$file) {
            throw new \RuntimeException("Pack file not found: {$name}");
        }
        
        $absolutePath = $this->getAbsolutePath($projectId, $path, $name);
        $this->openConnection($absolutePath);
        
        // Verify it's a valid pack by checking for metadata table
        try {
            $version = $this->getMetadata('version');
            if (!$version) {
                throw new \RuntimeException("Invalid pack file: missing version metadata");
            }
        } catch (\Exception $e) {
            $this->close();
            throw new \RuntimeException("Invalid pack file: {$e->getMessage()}");
        }
        
        // Migrate: ensure memory_sources table exists for older packs
        $this->migrateSchema();
        
        $this->logger->info('Opened memory pack', ['projectId' => $projectId, 'path' => $path, 'name' => $name]);
        
        return true;
    }
    
    /**
     * Apply schema migrations for older pack files
     */
    private function migrateSchema(): void
    {
        $db = $this->getConnection();
        
        // Check if memory_sources table exists
        $result = $db->executeQuery(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='memory_sources'"
        );
        if (!$result->fetchAssociative()) {
            $db->executeStatement("
                CREATE TABLE IF NOT EXISTS memory_sources (
                    source_ref TEXT NOT NULL,
                    source_type TEXT NOT NULL,
                    content TEXT NOT NULL,
                    title TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (source_ref, source_type)
                )
            ");
            $this->logger->info('Migrated pack: added memory_sources table');
        }
        
        // Check if ai_usage_log table exists
        $result = $db->executeQuery(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='ai_usage_log'"
        );
        if (!$result->fetchAssociative()) {
            $db->executeStatement("
                CREATE TABLE IF NOT EXISTS ai_usage_log (
                    id TEXT PRIMARY KEY,
                    job_id TEXT,
                    purpose TEXT NOT NULL,
                    model_slug TEXT,
                    input_tokens INTEGER,
                    output_tokens INTEGER,
                    total_tokens INTEGER,
                    total_cost_credits REAL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (job_id) REFERENCES memory_jobs(id) ON DELETE SET NULL
                )
            ");
            $this->logger->info('Migrated pack: added ai_usage_log table');
        }
        
        // Check if depth column exists on memory_nodes
        $result = $db->executeQuery("PRAGMA table_info(memory_nodes)");
        $columns = array_column($result->fetchAllAssociative(), 'name');
        if (!in_array('depth', $columns)) {
            $db->executeStatement("ALTER TABLE memory_nodes ADD COLUMN depth INTEGER DEFAULT NULL");
            $this->logger->info('Migrated pack: added depth column to memory_nodes');
        }
        
        // FTS5 full-text search index migration
        $hasFTS5Table = (bool)$db->executeQuery(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='memory_nodes_fts'"
        )->fetchAssociative();
        
        $hasFTS5Triggers = count($db->executeQuery(
            "SELECT name FROM sqlite_master WHERE type='trigger' AND name LIKE 'memory_nodes_fts_%'"
        )->fetchAllAssociative()) >= 5; // 3 on memory_nodes + 2 on memory_tags
        
        // Check if existing FTS5 table has 'tags' column (v2 schema)
        $hasFTS5Tags = false;
        if ($hasFTS5Table) {
            $schema = $db->executeQuery(
                "SELECT sql FROM sqlite_master WHERE type='table' AND name='memory_nodes_fts'"
            )->fetchOne();
            $hasFTS5Tags = $schema && str_contains((string)$schema, 'tags');
        }
        
        if (!$hasFTS5Table || !$hasFTS5Triggers || !$hasFTS5Tags) {
            // Drop old FTS5 table/triggers (may be missing tags column or tag triggers)
            if ($hasFTS5Table) {
                $db->executeStatement("DROP TABLE IF EXISTS memory_nodes_fts");
            }
            $db->executeStatement("DROP TRIGGER IF EXISTS memory_nodes_fts_insert");
            $db->executeStatement("DROP TRIGGER IF EXISTS memory_nodes_fts_update");
            $db->executeStatement("DROP TRIGGER IF EXISTS memory_nodes_fts_delete");
            $db->executeStatement("DROP TRIGGER IF EXISTS memory_nodes_fts_tag_insert");
            $db->executeStatement("DROP TRIGGER IF EXISTS memory_nodes_fts_tag_delete");
            
            // Create fresh FTS5 table + triggers (content + summary + tags)
            $this->initializeFTS5($db);
            
            // Populate FTS5 index from existing active nodes (including their tags)
            $db->executeStatement("
                INSERT INTO memory_nodes_fts(rowid, content, summary, tags)
                SELECT mn.rowid, mn.content, COALESCE(mn.summary, ''),
                       COALESCE((SELECT GROUP_CONCAT(mt.tag, ' ') FROM memory_tags mt WHERE mt.memory_id = mn.id), '')
                FROM memory_nodes mn
                WHERE mn.is_active = 1
            ");
            
            $count = $db->executeQuery('SELECT COUNT(*) FROM memory_nodes_fts')->fetchOne();
            $this->logger->info('Migrated pack: added FTS5 index (with tags), populated with ' . $count . ' nodes');
        }
    }
    
    /**
     * Close the current connection
     */
    public function close(): void
    {
        if ($this->connection) {
            $this->connection->close();
            $this->connection = null;
            $this->currentPackPath = null;
        }
    }
    
    /**
     * Get the current pack path
     */
    public function getCurrentPackPath(): ?string
    {
        return $this->currentPackPath;
    }
    
    /**
     * Check if a pack is currently open
     */
    public function isOpen(): bool
    {
        return $this->connection !== null;
    }
    
    // ========================================
    // Database Connection
    // ========================================
    
    private function openConnection(string $packPath): void
    {
        $this->close(); // Close any existing connection
        
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $packPath,
        ]);
        
        $this->currentPackPath = $packPath;
        
        // Enable foreign keys
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');
    }
    
    private function getConnection(): Connection
    {
        if (!$this->connection) {
            throw new \RuntimeException("No pack file is open");
        }
        return $this->connection;
    }
    
    // ========================================
    // Schema Management
    // ========================================
    
    private function initializeSchema(): void
    {
        $db = $this->getConnection();
        
        // Memory nodes table (no spirit_id!)
        $db->executeStatement("
            CREATE TABLE IF NOT EXISTS memory_nodes (
                id TEXT PRIMARY KEY,
                content TEXT NOT NULL,
                summary TEXT,
                category TEXT NOT NULL,
                importance REAL DEFAULT 0.5,
                confidence REAL DEFAULT 1.0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_accessed DATETIME,
                access_count INTEGER DEFAULT 0,
                source_type TEXT,
                source_ref TEXT,
                source_range TEXT,
                is_active INTEGER DEFAULT 1,
                depth INTEGER DEFAULT NULL
            )
        ");
        
        // Relationships table
        $db->executeStatement("
            CREATE TABLE IF NOT EXISTS memory_relationships (
                id TEXT PRIMARY KEY,
                source_id TEXT NOT NULL,
                target_id TEXT NOT NULL,
                type TEXT NOT NULL,
                strength REAL DEFAULT 1.0,
                context TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (source_id) REFERENCES memory_nodes(id) ON DELETE CASCADE,
                FOREIGN KEY (target_id) REFERENCES memory_nodes(id) ON DELETE CASCADE
            )
        ");
        
        // Tags table
        $db->executeStatement("
            CREATE TABLE IF NOT EXISTS memory_tags (
                id TEXT PRIMARY KEY,
                memory_id TEXT NOT NULL,
                tag TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (memory_id) REFERENCES memory_nodes(id) ON DELETE CASCADE
            )
        ");
        
        // Jobs table
        $db->executeStatement("
            CREATE TABLE IF NOT EXISTS memory_jobs (
                id TEXT PRIMARY KEY,
                type TEXT NOT NULL,
                status TEXT DEFAULT 'pending',
                payload TEXT,
                result TEXT,
                progress INTEGER DEFAULT 0,
                total_steps INTEGER DEFAULT 0,
                error TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                started_at DATETIME,
                completed_at DATETIME
            )
        ");
        
        // Metadata table (key-value store for pack info)
        $db->executeStatement("
            CREATE TABLE IF NOT EXISTS memory_metadata (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )
        ");
        
        // Source content storage (original content used for extraction)
        $db->executeStatement("
            CREATE TABLE IF NOT EXISTS memory_sources (
                source_ref TEXT NOT NULL,
                source_type TEXT NOT NULL,
                content TEXT NOT NULL,
                title TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (source_ref, source_type)
            )
        ");
        
        // Consolidation log
        $db->executeStatement("
            CREATE TABLE IF NOT EXISTS memory_consolidation_log (
                id TEXT PRIMARY KEY,
                action TEXT NOT NULL,
                affected_ids TEXT NOT NULL,
                details TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // AI usage log (tracks cost of all AI sub-agent calls within this pack)
        $db->executeStatement("
            CREATE TABLE IF NOT EXISTS ai_usage_log (
                id TEXT PRIMARY KEY,
                job_id TEXT,
                purpose TEXT NOT NULL,
                model_slug TEXT,
                input_tokens INTEGER,
                output_tokens INTEGER,
                total_tokens INTEGER,
                total_cost_credits REAL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (job_id) REFERENCES memory_jobs(id) ON DELETE SET NULL
            )
        ");
        
        // Indexes for performance
        $db->executeStatement("CREATE INDEX IF NOT EXISTS idx_memory_nodes_category ON memory_nodes(category)");
        $db->executeStatement("CREATE INDEX IF NOT EXISTS idx_memory_nodes_importance ON memory_nodes(importance)");
        $db->executeStatement("CREATE INDEX IF NOT EXISTS idx_memory_nodes_last_accessed ON memory_nodes(last_accessed)");
        $db->executeStatement("CREATE INDEX IF NOT EXISTS idx_memory_nodes_is_active ON memory_nodes(is_active)");
        $db->executeStatement("CREATE INDEX IF NOT EXISTS idx_memory_relationships_source ON memory_relationships(source_id)");
        $db->executeStatement("CREATE INDEX IF NOT EXISTS idx_memory_relationships_target ON memory_relationships(target_id)");
        $db->executeStatement("CREATE INDEX IF NOT EXISTS idx_memory_relationships_type ON memory_relationships(type)");
        $db->executeStatement("CREATE INDEX IF NOT EXISTS idx_memory_tags_tag ON memory_tags(tag)");
        $db->executeStatement("CREATE INDEX IF NOT EXISTS idx_memory_tags_memory ON memory_tags(memory_id)");
        $db->executeStatement("CREATE INDEX IF NOT EXISTS idx_memory_jobs_status ON memory_jobs(status)");
        $db->executeStatement("CREATE INDEX IF NOT EXISTS idx_memory_jobs_type ON memory_jobs(type)");
        
        // FTS5 full-text search index for Memory Recall
        $this->initializeFTS5($db);
    }
    
    /**
     * Initialize FTS5 full-text search virtual table and sync triggers
     * 
     * Creates the FTS5 index on memory_nodes (content + summary + tags) with
     * Porter stemming and Unicode support. Triggers keep the index in
     * sync automatically on INSERT, UPDATE (content/summary/is_active),
     * and DELETE operations on memory_nodes, and on INSERT/DELETE on memory_tags.
     */
    private function initializeFTS5(Connection $db): void
    {
        // FTS5 virtual table with Porter stemming + Unicode tokenizer
        // Indexes content, summary AND tags for comprehensive search
        $db->executeStatement("
            CREATE VIRTUAL TABLE IF NOT EXISTS memory_nodes_fts 
            USING fts5(
                content, 
                summary, 
                tags,
                tokenize='porter unicode61'
            )
        ");
        
        // Trigger: sync FTS5 on INSERT (only active nodes, tags empty at creation)
        $db->executeStatement("
            CREATE TRIGGER IF NOT EXISTS memory_nodes_fts_insert 
            AFTER INSERT ON memory_nodes 
            WHEN NEW.is_active = 1
            BEGIN
                INSERT INTO memory_nodes_fts(rowid, content, summary, tags) 
                VALUES (NEW.rowid, NEW.content, COALESCE(NEW.summary, ''), '');
            END
        ");
        
        // Trigger: sync FTS5 on UPDATE (handles content/summary changes AND soft-delete via is_active)
        $db->executeStatement("
            CREATE TRIGGER IF NOT EXISTS memory_nodes_fts_update 
            AFTER UPDATE ON memory_nodes
            BEGIN
                DELETE FROM memory_nodes_fts WHERE rowid = OLD.rowid;
                INSERT INTO memory_nodes_fts(rowid, content, summary, tags)
                SELECT NEW.rowid, NEW.content, COALESCE(NEW.summary, ''),
                       COALESCE((SELECT GROUP_CONCAT(tag, ' ') FROM memory_tags WHERE memory_id = NEW.id), '')
                WHERE NEW.is_active = 1;
            END
        ");
        
        // Trigger: sync FTS5 on DELETE (hard delete)
        $db->executeStatement("
            CREATE TRIGGER IF NOT EXISTS memory_nodes_fts_delete 
            AFTER DELETE ON memory_nodes
            BEGIN
                DELETE FROM memory_nodes_fts WHERE rowid = OLD.rowid;
            END
        ");
        
        // Trigger: sync FTS5 when a tag is added
        $db->executeStatement("
            CREATE TRIGGER IF NOT EXISTS memory_nodes_fts_tag_insert 
            AFTER INSERT ON memory_tags
            BEGIN
                DELETE FROM memory_nodes_fts WHERE rowid = (SELECT rowid FROM memory_nodes WHERE id = NEW.memory_id);
                INSERT INTO memory_nodes_fts(rowid, content, summary, tags)
                SELECT mn.rowid, mn.content, COALESCE(mn.summary, ''),
                       COALESCE((SELECT GROUP_CONCAT(tag, ' ') FROM memory_tags WHERE memory_id = NEW.memory_id), '')
                FROM memory_nodes mn WHERE mn.id = NEW.memory_id AND mn.is_active = 1;
            END
        ");
        
        // Trigger: sync FTS5 when a tag is removed
        $db->executeStatement("
            CREATE TRIGGER IF NOT EXISTS memory_nodes_fts_tag_delete 
            AFTER DELETE ON memory_tags
            BEGIN
                DELETE FROM memory_nodes_fts WHERE rowid = (SELECT rowid FROM memory_nodes WHERE id = OLD.memory_id);
                INSERT INTO memory_nodes_fts(rowid, content, summary, tags)
                SELECT mn.rowid, mn.content, COALESCE(mn.summary, ''),
                       COALESCE((SELECT GROUP_CONCAT(tag, ' ') FROM memory_tags WHERE memory_id = OLD.memory_id), '')
                FROM memory_nodes mn WHERE mn.id = OLD.memory_id AND mn.is_active = 1;
            END
        ");
    }
    
    // ========================================
    // Metadata Operations
    // ========================================
    
    public function setMetadata(string $key, string $value): void
    {
        $db = $this->getConnection();
        
        $db->executeStatement(
            'INSERT OR REPLACE INTO memory_metadata (key, value) VALUES (?, ?)',
            [$key, $value]
        );
        
        // Update the updated_at timestamp
        if ($key !== 'updated_at') {
            $db->executeStatement(
                'INSERT OR REPLACE INTO memory_metadata (key, value) VALUES (?, ?)',
                ['updated_at', date('c')]
            );
        }
    }
    
    public function getMetadata(string $key): ?string
    {
        $db = $this->getConnection();
        
        $result = $db->executeQuery(
            'SELECT value FROM memory_metadata WHERE key = ?',
            [$key]
        );
        
        $row = $result->fetchAssociative();
        return $row ? $row['value'] : null;
    }
    
    public function getAllMetadata(): array
    {
        $db = $this->getConnection();
        
        $result = $db->executeQuery('SELECT key, value FROM memory_metadata');
        $rows = $result->fetchAllAssociative();
        
        $metadata = [];
        foreach ($rows as $row) {
            $metadata[$row['key']] = $row['value'];
        }
        
        return $metadata;
    }
    
    // ========================================
    // Source Content Operations
    // ========================================
    
    /**
     * Store original source content used for extraction
     * Uses INSERT OR REPLACE so re-extractions update the stored content
     */
    public function storeSource(string $sourceRef, string $sourceType, string $content, ?string $title = null): void
    {
        $db = $this->getConnection();
        
        $db->executeStatement(
            'INSERT OR REPLACE INTO memory_sources (source_ref, source_type, content, title, created_at) VALUES (?, ?, ?, ?, ?)',
            [$sourceRef, $sourceType, $content, $title, date('Y-m-d H:i:s')]
        );
    }
    
    /**
     * Get stored source content by source_ref and source_type
     */
    public function getSource(string $sourceRef, string $sourceType): ?array
    {
        $db = $this->getConnection();
        
        $result = $db->executeQuery(
            'SELECT * FROM memory_sources WHERE source_ref = ? AND source_type = ?',
            [$sourceRef, $sourceType]
        );
        
        $row = $result->fetchAssociative();
        return $row ?: null;
    }
    
    /**
     * Get stored source content by source_ref only (any source_type)
     */
    public function getSourceByRef(string $sourceRef): ?array
    {
        $db = $this->getConnection();
        
        $result = $db->executeQuery(
            'SELECT * FROM memory_sources WHERE source_ref = ? LIMIT 1',
            [$sourceRef]
        );
        
        $row = $result->fetchAssociative();
        return $row ?: null;
    }
    
    // ========================================
    // AI Usage Log Operations
    // ========================================
    
    /**
     * Log an AI sub-agent call's usage data in the pack
     */
    public function logAiUsage(
        string $purpose,
        ?string $jobId = null,
        ?string $modelSlug = null,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?int $totalTokens = null,
        ?float $totalCostCredits = null
    ): void {
        $db = $this->getConnection();
        
        $id = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
        
        $db->executeStatement(
            'INSERT INTO ai_usage_log (id, job_id, purpose, model_slug, input_tokens, output_tokens, total_tokens, total_cost_credits, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id,
                $jobId,
                $purpose,
                $modelSlug,
                $inputTokens,
                $outputTokens,
                $totalTokens,
                $totalCostCredits,
                date('Y-m-d H:i:s')
            ]
        );
    }
    
    /**
     * Log AI usage from an AiServiceResponse object (convenience method)
     */
    public function logAiUsageFromResponse(
        string $purpose,
        $aiServiceResponse,
        ?string $jobId = null,
        ?string $modelSlug = null
    ): void {
        $fullResponse = $aiServiceResponse->getFullResponse();
        $totalCost = isset($fullResponse['total_cost_credits']) ? (float)$fullResponse['total_cost_credits'] : null;
        
        $this->logAiUsage(
            $purpose,
            $jobId,
            $modelSlug,
            $aiServiceResponse->getInputTokens(),
            $aiServiceResponse->getOutputTokens(),
            $aiServiceResponse->getTotalTokens(),
            $totalCost
        );
    }
    
    /**
     * Get aggregated AI usage summary for this pack
     */
    public function getAiUsageSummary(): array
    {
        $db = $this->getConnection();
        
        $result = $db->executeQuery("
            SELECT 
                COUNT(*) as total_calls,
                SUM(input_tokens) as total_input_tokens,
                SUM(output_tokens) as total_output_tokens,
                SUM(total_tokens) as total_tokens,
                SUM(total_cost_credits) as total_cost_credits
            FROM ai_usage_log
        ");
        
        $summary = $result->fetchAssociative();
        
        // Also get per-purpose breakdown
        $result = $db->executeQuery("
            SELECT 
                purpose,
                COUNT(*) as calls,
                SUM(total_tokens) as tokens,
                SUM(total_cost_credits) as cost_credits
            FROM ai_usage_log
            GROUP BY purpose
            ORDER BY cost_credits DESC
        ");
        
        $summary['by_purpose'] = $result->fetchAllAssociative();
        
        return $summary;
    }
    
    // ========================================
    // Node Operations
    // ========================================
    
    public function storeNode(
        string $content,
        string $category = MemoryNode::CATEGORY_KNOWLEDGE,
        float $importance = 0.5,
        ?string $summary = null,
        ?string $sourceType = null,
        ?string $sourceRef = null,
        array $tags = [],
        ?string $relatesTo = null,
        ?string $sourceRange = null,
        ?int $depth = null
    ): MemoryNode {
        $db = $this->getConnection();
        
        $node = new MemoryNode($content, $category, $importance, $summary);
        $node->setSourceType($sourceType);
        $node->setSourceRef($sourceRef);
        $node->setSourceRange($sourceRange);
        $node->setDepth($depth);
        
        // Auto-generate summary if not provided
        if (!$summary && strlen($content) > 100) {
            $node->setSummary(substr($content, 0, 100) . '...');
        }
        
        $db->executeStatement(
            'INSERT INTO memory_nodes 
            (id, content, summary, category, importance, confidence, created_at, last_accessed, access_count, source_type, source_ref, source_range, is_active, depth) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $node->getId(),
                $node->getContent(),
                $node->getSummary(),
                $node->getCategory(),
                $node->getImportance(),
                $node->getConfidence(),
                $node->getCreatedAt()->format('Y-m-d H:i:s'),
                null,
                0,
                $node->getSourceType(),
                $node->getSourceRef(),
                $node->getSourceRange(),
                1,
                $node->getDepth()
            ]
        );
        
        // Add tags (deduplicate first)
        foreach (array_unique($tags) as $tag) {
            $this->addTag($node->getId(), $tag);
        }
        
        // Create relationship if relatesTo is specified
        if ($relatesTo) {
            $relatedMemory = $this->findByKeyword($relatesTo, 1);
            if (!empty($relatedMemory)) {
                $this->createRelationship(
                    $node->getId(),
                    $relatedMemory[0]->getId(),
                    MemoryNode::RELATION_RELATES_TO
                );
            }
        }
        
        $this->logger->debug('Stored memory node', ['id' => $node->getId()]);
        
        return $node;
    }
    
    public function findNodeById(string $id): ?MemoryNode
    {
        $db = $this->getConnection();
        
        $result = $db->executeQuery(
            'SELECT * FROM memory_nodes WHERE id = ?',
            [$id]
        );
        
        $row = $result->fetchAssociative();
        if (!$row) {
            return null;
        }
        
        return MemoryNode::fromArray($row);
    }
    
    public function findAllNodes(bool $activeOnly = true): array
    {
        $db = $this->getConnection();
        
        $sql = 'SELECT * FROM memory_nodes';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY created_at DESC';
        
        $result = $db->executeQuery($sql);
        
        $nodes = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $nodes[] = MemoryNode::fromArray($row);
        }
        
        return $nodes;
    }
    
    /**
     * FTS5 full-text search with BM25 ranking
     * 
     * Returns memory nodes ranked by text relevance (BM25), with optional
     * category and tag filtering. Falls back to LIKE search if FTS5 is
     * not available (e.g., SQLite compiled without FTS5 extension).
     * 
     * @param string $query Search query (natural language or keywords)
     * @param string|null $category Optional category filter
     * @param array $tags Optional tag filter (matches any)
     * @param int $limit Maximum results to return
     * @return array Array of ['node' => MemoryNode, 'fts_rank' => float]
     */
    public function searchFTS5(
        string $query,
        ?string $category = null,
        array $tags = [],
        int $limit = 500
    ): array {
        $db = $this->getConnection();
        
        // Check if FTS5 table exists
        if (!$this->hasFTS5()) {
            // Fallback to LIKE-based search
            return $this->searchLikeFallback($query, $category, $tags, $limit);
        }
        
        // Sanitize query for FTS5 (escape special chars, handle multi-word)
        $ftsQuery = $this->buildFTS5Query($query);
        
        if (empty($ftsQuery)) {
            return [];
        }
        
        // Build SQL with FTS5 MATCH + optional filters
        $sql = "
            SELECT mn.*, fts.rank AS fts_rank
            FROM memory_nodes_fts fts
            JOIN memory_nodes mn ON fts.rowid = mn.rowid
            WHERE memory_nodes_fts MATCH ?
              AND mn.is_active = 1
        ";
        $params = [$ftsQuery];
        
        if ($category) {
            $sql .= " AND mn.category = ?";
            $params[] = $category;
        }
        
        if (!empty($tags)) {
            $placeholders = implode(',', array_fill(0, count($tags), '?'));
            $sql .= " AND mn.id IN (SELECT memory_id FROM memory_tags WHERE tag IN ({$placeholders}))";
            $params = array_merge($params, $tags);
        }
        
        // FTS5 rank is negative (closer to 0 = better match), so ORDER BY rank ASC
        $sql .= " ORDER BY fts.rank LIMIT ?";
        $params[] = $limit;
        
        try {
            $result = $db->executeQuery($sql, $params);
            $rows = $result->fetchAllAssociative();
        } catch (\Exception $e) {
            $this->logger->warning('FTS5 search failed, falling back to LIKE', [
                'query' => $query,
                'error' => $e->getMessage()
            ]);
            return $this->searchLikeFallback($query, $category, $tags, $limit);
        }
        
        $results = [];
        foreach ($rows as $row) {
            $ftsRank = (float)$row['fts_rank'];
            unset($row['fts_rank']);
            $results[] = [
                'node' => MemoryNode::fromArray($row),
                'fts_rank' => $ftsRank
            ];
        }
        
        return $results;
    }
    
    /**
     * Build FTS5 query from natural language input
     * 
     * Handles multi-word queries, removes FTS5 special characters,
     * and constructs a query that searches across content and summary columns.
     */
    private function buildFTS5Query(string $query): string
    {
        // Remove FTS5 special operators that could cause syntax errors
        // Note: hyphens must be removed — FTS5 interprets them as column prefix operators
        // e.g. "pzp-insurance" → column "pzp", term "insurance" → "no such column" error
        $cleaned = preg_replace('/[^\p{L}\p{N}\s_]/u', ' ', $query);
        $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned));
        
        if (empty($cleaned)) {
            return '';
        }
        
        // Split into words and build OR query for broader matching
        $words = array_filter(array_map('trim', explode(' ', $cleaned)));
        
        if (empty($words)) {
            return '';
        }
        
        if (count($words) === 1) {
            // Single word: match prefix for flexibility (e.g., "cook" matches "cooking")
            return $words[0] . '*';
        }
        
        // Multi-word: try phrase match first OR individual terms with prefix
        // "{content summary}: word1 word2" searches both columns
        $phraseMatch = '"' . implode(' ', $words) . '"';
        $termMatches = array_map(fn($w) => $w . '*', $words);
        
        return $phraseMatch . ' OR ' . implode(' OR ', $termMatches);
    }
    
    /**
     * Check if FTS5 virtual table exists in current pack
     */
    public function hasFTS5(): bool
    {
        try {
            $db = $this->getConnection();
            $result = $db->executeQuery(
                "SELECT name FROM sqlite_master WHERE type='table' AND name='memory_nodes_fts'"
            );
            return (bool)$result->fetchAssociative();
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * LIKE-based fallback search when FTS5 is not available
     */
    private function searchLikeFallback(
        string $query,
        ?string $category = null,
        array $tags = [],
        int $limit = 500
    ): array {
        $db = $this->getConnection();
        
        $sql = "SELECT * FROM memory_nodes WHERE is_active = 1 AND (content LIKE ? OR summary LIKE ? OR id IN (SELECT memory_id FROM memory_tags WHERE tag LIKE ?))";
        $params = ["%{$query}%", "%{$query}%", "%{$query}%"];
        
        if ($category) {
            $sql .= " AND category = ?";
            $params[] = $category;
        }
        
        if (!empty($tags)) {
            $placeholders = implode(',', array_fill(0, count($tags), '?'));
            $sql .= " AND id IN (SELECT memory_id FROM memory_tags WHERE tag IN ({$placeholders}))";
            $params = array_merge($params, $tags);
        }
        
        $sql .= " ORDER BY importance DESC, created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $result = $db->executeQuery($sql, $params);
        
        $results = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $results[] = [
                'node' => MemoryNode::fromArray($row),
                'fts_rank' => 0.0
            ];
        }
        
        return $results;
    }
    
    public function findByKeyword(string $keyword, int $limit = 10): array
    {
        // Use FTS5 search if available, otherwise fall back to LIKE
        $ftsResults = $this->searchFTS5($keyword, null, [], $limit);
        
        if (!empty($ftsResults)) {
            return array_map(fn($r) => $r['node'], $ftsResults);
        }
        
        // Final fallback: direct LIKE query
        $db = $this->getConnection();
        
        $result = $db->executeQuery(
            "SELECT * FROM memory_nodes 
             WHERE is_active = 1 
             AND (content LIKE ? OR summary LIKE ? OR id IN (SELECT memory_id FROM memory_tags WHERE tag LIKE ?))
             ORDER BY importance DESC, created_at DESC
             LIMIT ?",
            ["%{$keyword}%", "%{$keyword}%", "%{$keyword}%", $limit]
        );
        
        $nodes = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $nodes[] = MemoryNode::fromArray($row);
        }
        
        return $nodes;
    }
    
    public function findByCategory(string $category, bool $activeOnly = true): array
    {
        $db = $this->getConnection();
        
        $sql = 'SELECT * FROM memory_nodes WHERE category = ?';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY importance DESC, created_at DESC';
        
        $result = $db->executeQuery($sql, [$category]);
        
        $nodes = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $nodes[] = MemoryNode::fromArray($row);
        }
        
        return $nodes;
    }
    
    /**
     * Scored recall with recency × importance × relevance weighting
     * 
     * Uses FTS5 BM25 ranking for relevance scoring when available,
     * providing continuous relevance scores instead of binary LIKE matching.
     * Falls back to LIKE-based search if FTS5 is not available.
     */
    public function recall(
        string $query,
        ?string $category = null,
        array $tags = [],
        int $limit = 10,
        bool $includeRelated = true,
        float $recencyWeight = 0.2,
        float $importanceWeight = 0.4,
        float $relevanceWeight = 0.4,
        float $connectednessWeight = 0.0
    ): array {
        $db = $this->getConnection();
        
        // Try FTS5-powered recall first
        $useFTS5 = $this->hasFTS5();
        $rows = [];
        
        if ($useFTS5) {
            $ftsQuery = $this->buildFTS5Query($query);
            
            if (!empty($ftsQuery)) {
                // FTS5 query: rank is BM25 score (negative, closer to 0 = better)
                $sql = "
                    SELECT mn.*, fts.rank AS fts_rank
                    FROM memory_nodes_fts fts
                    JOIN memory_nodes mn ON fts.rowid = mn.rowid
                    WHERE memory_nodes_fts MATCH ?
                      AND mn.is_active = 1
                ";
                $params = [$ftsQuery];
                
                if ($category) {
                    $sql .= " AND mn.category = ?";
                    $params[] = $category;
                }
                
                if (!empty($tags)) {
                    $placeholders = implode(',', array_fill(0, count($tags), '?'));
                    $sql .= " AND mn.id IN (SELECT memory_id FROM memory_tags WHERE tag IN ({$placeholders}))";
                    $params = array_merge($params, $tags);
                }
                
                $sql .= " ORDER BY fts.rank LIMIT ?";
                $params[] = $limit * 2;
                
                try {
                    $result = $db->executeQuery($sql, $params);
                    $rows = $result->fetchAllAssociative();
                } catch (\Exception $e) {
                    $this->logger->warning('FTS5 recall failed, falling back to LIKE', [
                        'query' => $query, 'error' => $e->getMessage()
                    ]);
                    $useFTS5 = false;
                }
            }
        }
        
        // Fallback to LIKE-based search
        if (!$useFTS5 || empty($rows)) {
            $sql = "SELECT *, 
                    (CASE WHEN content LIKE ? OR summary LIKE ? THEN 1.0
                          WHEN id IN (SELECT memory_id FROM memory_tags WHERE tag LIKE ?) THEN 0.8
                          ELSE 0.0 END) as relevance_score
                    FROM memory_nodes 
                    WHERE is_active = 1
                      AND (content LIKE ? OR summary LIKE ? OR id IN (SELECT memory_id FROM memory_tags WHERE tag LIKE ?))";
            $params = ["%{$query}%", "%{$query}%", "%{$query}%", "%{$query}%", "%{$query}%", "%{$query}%"];
            
            if ($category) {
                $sql .= " AND category = ?";
                $params[] = $category;
            }
            
            if (!empty($tags)) {
                $placeholders = implode(',', array_fill(0, count($tags), '?'));
                $sql .= " AND id IN (SELECT memory_id FROM memory_tags WHERE tag IN ({$placeholders}))";
                $params = array_merge($params, $tags);
            }
            
            $sql .= " ORDER BY relevance_score DESC, importance DESC, created_at DESC LIMIT ?";
            $params[] = $limit * 2;
            
            $result = $db->executeQuery($sql, $params);
            $rows = $result->fetchAllAssociative();
        }
        
        $scoredResults = [];
        $now = new \DateTime();
        
        // Batch-count relationships per node for connectedness scoring
        $relCounts = [];
        if ($connectednessWeight > 0 && !empty($rows)) {
            $nodeIds = array_column($rows, 'id');
            $placeholders = implode(',', array_fill(0, count($nodeIds), '?'));
            $relResult = $db->executeQuery(
                "SELECT node_id, COUNT(*) as rel_count FROM (
                    SELECT source_id AS node_id FROM memory_relationships WHERE source_id IN ({$placeholders})
                    UNION ALL
                    SELECT target_id AS node_id FROM memory_relationships WHERE target_id IN ({$placeholders})
                ) GROUP BY node_id",
                array_merge($nodeIds, $nodeIds)
            );
            foreach ($relResult->fetchAllAssociative() as $rc) {
                $relCounts[$rc['node_id']] = (int)$rc['rel_count'];
            }
        }
        $maxRelCount = !empty($relCounts) ? max($relCounts) : 1;
        
        // Normalize FTS5 ranks to 0-1 relevance score
        $maxAbsRank = 0.0;
        if ($useFTS5 && !empty($rows)) {
            foreach ($rows as $row) {
                if (isset($row['fts_rank'])) {
                    $maxAbsRank = max($maxAbsRank, abs((float)$row['fts_rank']));
                }
            }
        }
        
        foreach ($rows as $row) {
            // Compute relevance score
            if ($useFTS5 && isset($row['fts_rank'])) {
                // FTS5 BM25: negative score, closer to 0 = better
                // Normalize to 0-1 range (1 = best match)
                $absRank = abs((float)$row['fts_rank']);
                $relevanceScore = $maxAbsRank > 0 ? (1.0 - ($absRank / ($maxAbsRank * 1.5))) : 0.5;
                $relevanceScore = max(0.1, min(1.0, $relevanceScore));
                unset($row['fts_rank']);
            } else {
                $relevanceScore = isset($row['relevance_score']) ? (float)$row['relevance_score'] : 0.0;
                unset($row['relevance_score']);
            }
            
            $node = MemoryNode::fromArray($row);
            
            $daysSinceCreation = max(1, $now->diff($node->getCreatedAt())->days);
            $recencyScore = 1.0 / (1 + log($daysSinceCreation));
            
            $importanceScore = $node->getImportance();
            
            // Connectedness score: normalized relationship count (0-1)
            $connectednessScore = 0.0;
            if ($connectednessWeight > 0) {
                $nodeRelCount = $relCounts[$node->getId()] ?? 0;
                $connectednessScore = $maxRelCount > 0 ? min(1.0, $nodeRelCount / $maxRelCount) : 0.0;
            }
            
            $finalScore = ($relevanceScore * $relevanceWeight) +
                          ($importanceScore * $importanceWeight) +
                          ($recencyScore * $recencyWeight) +
                          ($connectednessScore * $connectednessWeight);
            
            $scoredResults[] = [
                'node' => $node,
                'score' => $finalScore,
                'tags' => $this->getTagsForNode($node->getId())
            ];
            
            $this->incrementAccessCount($node->getId());
        }
        
        usort($scoredResults, fn($a, $b) => $b['score'] <=> $a['score']);
        $scoredResults = array_slice($scoredResults, 0, $limit);
        
        if ($includeRelated && !empty($scoredResults)) {
            $relatedMemories = [];
            foreach ($scoredResults as $result) {
                $related = $this->getRelatedNodes($result['node']->getId(), 2);
                foreach ($related as $rel) {
                    $relNode = $rel['node'];
                    $relId = $relNode->getId();
                    if (!isset($relatedMemories[$relId])) {
                        $relatedMemories[$relId] = [
                            'node' => $relNode,
                            'score' => 0,
                            'tags' => $rel['tags'] ?? $this->getTagsForNode($relId),
                            'isRelated' => true
                        ];
                    }
                }
            }
            foreach ($relatedMemories as $relId => $relData) {
                $alreadyIncluded = false;
                foreach ($scoredResults as $sr) {
                    if ($sr['node']->getId() === $relId) {
                        $alreadyIncluded = true;
                        break;
                    }
                }
                if (!$alreadyIncluded) {
                    $scoredResults[] = $relData;
                }
            }
        }
        
        return $scoredResults;
    }
    
    /**
     * Get distinct categories used in this pack
     */
    public function getCategories(): array
    {
        $db = $this->getConnection();
        
        $result = $db->executeQuery(
            'SELECT DISTINCT category FROM memory_nodes WHERE is_active = 1 ORDER BY category'
        );
        
        return array_column($result->fetchAllAssociative(), 'category');
    }
    
    /**
     * Check if memories have already been extracted from a specific source
     */
    public function hasExtractedFromSource(string $sourceType, string $sourceRef): ?array
    {
        $db = $this->getConnection();
        
        $result = $db->executeQuery(
            "SELECT COUNT(*) as count, MAX(created_at) as last_extracted 
             FROM memory_nodes 
             WHERE source_type = ? AND source_ref = ? AND is_active = 1",
            [$sourceType, $sourceRef]
        );
        
        $row = $result->fetchAssociative();
        
        if ($row && (int)$row['count'] > 0) {
            return [
                'count' => (int)$row['count'],
                'lastExtracted' => $row['last_extracted']
            ];
        }
        
        return null;
    }
    
    public function updateNode(string $nodeId, string $newContent, ?string $reason = null): MemoryNode
    {
        $db = $this->getConnection();
        
        $oldNode = $this->findNodeById($nodeId);
        if (!$oldNode) {
            throw new \RuntimeException('Memory node not found');
        }
        
        // Create new node with updated content
        $newNode = $this->storeNode(
            $newContent,
            $oldNode->getCategory(),
            $oldNode->getImportance(),
            null,
            'derived',
            $nodeId,
            $this->getTagsForNode($nodeId)
        );
        
        // Log consolidation action
        $this->logConsolidation('update', [$oldNode->getId(), $newNode->getId()], [
            'reason' => $reason,
            'oldContent' => substr($oldNode->getContent(), 0, 100),
            'newContent' => substr($newContent, 0, 100)
        ]);
        
        return $newNode;
    }
    
    public function forgetNode(string $nodeId, ?string $reason = null): bool
    {
        $db = $this->getConnection();
        
        $node = $this->findNodeById($nodeId);
        if (!$node) {
            return false;
        }
        
        $db->executeStatement(
            'UPDATE memory_nodes SET is_active = 0 WHERE id = ?',
            [$nodeId]
        );
        
        $this->logConsolidation('forget', [$nodeId], [
            'reason' => $reason,
            'content' => substr($node->getContent(), 0, 100)
        ]);
        
        return true;
    }
    
    public function deleteNode(string $nodeId): bool
    {
        $db = $this->getConnection();
        
        // Delete tags first
        $db->executeStatement('DELETE FROM memory_tags WHERE memory_id = ?', [$nodeId]);
        
        // Delete relationships
        $db->executeStatement(
            'DELETE FROM memory_relationships WHERE source_id = ? OR target_id = ?',
            [$nodeId, $nodeId]
        );
        
        // Delete the node
        $db->executeStatement('DELETE FROM memory_nodes WHERE id = ?', [$nodeId]);
        
        return true;
    }
    
    /**
     * Delete a node and all its PART_OF descendant children (not parents/root).
     * Children are nodes where: child.source_id -> node.id with type PART_OF
     * Returns array of all deleted node IDs (including the target node).
     */
    public function deleteNodeWithChildren(string $nodeId): array
    {
        $db = $this->getConnection();
        $deletedIds = [];
        
        // Collect all descendant node IDs via PART_OF relationships (BFS)
        $queue = [$nodeId];
        $visited = [$nodeId => true];
        
        while (!empty($queue)) {
            $currentId = array_shift($queue);
            $deletedIds[] = $currentId;
            
            // Find children: nodes that have PART_OF relationship pointing TO currentId
            // In extraction flow: child (source_id) -> parent (target_id) with type PART_OF
            $children = $db->executeQuery(
                'SELECT source_id FROM memory_relationships WHERE target_id = ? AND type = ?',
                [$currentId, MemoryNode::RELATION_PART_OF]
            )->fetchAllAssociative();
            
            foreach ($children as $child) {
                $childId = $child['source_id'];
                if (!isset($visited[$childId])) {
                    $visited[$childId] = true;
                    $queue[] = $childId;
                }
            }
        }
        
        // Delete all collected nodes, their tags and relationships
        foreach ($deletedIds as $id) {
            $this->deleteNode($id);
        }
        
        $this->logger->info('Deleted node with children', [
            'rootNodeId' => $nodeId,
            'totalDeleted' => count($deletedIds)
        ]);
        
        return $deletedIds;
    }
    
    public function incrementAccessCount(string $nodeId): void
    {
        $db = $this->getConnection();
        
        $db->executeStatement(
            'UPDATE memory_nodes SET access_count = access_count + 1, last_accessed = ? WHERE id = ?',
            [date('Y-m-d H:i:s'), $nodeId]
        );
    }
    
    // ========================================
    // Relationship Operations
    // ========================================
    
    public function createRelationship(
        string $sourceId,
        string $targetId,
        string $type,
        float $strength = 1.0,
        ?string $context = null
    ): MemoryRelationship {
        $db = $this->getConnection();
        
        $relationship = new MemoryRelationship($sourceId, $targetId, $type, $strength, $context);
        
        $db->executeStatement(
            'INSERT INTO memory_relationships (id, source_id, target_id, type, strength, context, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $relationship->getId(),
                $relationship->getSourceId(),
                $relationship->getTargetId(),
                $relationship->getType(),
                $relationship->getStrength(),
                $relationship->getContext(),
                $relationship->getCreatedAt()->format('Y-m-d H:i:s')
            ]
        );
        
        return $relationship;
    }
    
    /**
     * Delete a relationship by ID.
     * Used by Option B gating to remove intermediate-level edges during cross-doc drill-down.
     */
    public function deleteRelationship(string $relationshipId): void
    {
        $db = $this->getConnection();
        $db->executeStatement('DELETE FROM memory_relationships WHERE id = ?', [$relationshipId]);
    }

    public function findAllRelationships(): array
    {
        $db = $this->getConnection();
        
        $result = $db->executeQuery(
            "SELECT r.* FROM memory_relationships r
             JOIN memory_nodes n ON r.source_id = n.id
             WHERE n.is_active = 1"
        );
        
        $relationships = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $relationships[] = MemoryRelationship::fromArray($row);
        }
        
        return $relationships;
    }
    
    public function getRelatedNodes(string $nodeId, int $limit = 5): array
    {
        $db = $this->getConnection();
        
        $result = $db->executeQuery(
            "SELECT n.*, r.type as relation_type, r.strength as relation_strength
             FROM memory_nodes n
             JOIN memory_relationships r ON n.id = r.target_id
             WHERE r.source_id = ? AND n.is_active = 1
             LIMIT ?",
            [$nodeId, $limit]
        );
        
        $related = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $related[] = [
                'node' => MemoryNode::fromArray($row),
                'relationType' => $row['relation_type'],
                'relationStrength' => (float)$row['relation_strength'],
                'tags' => $this->getTagsForNode($row['id']),
                'score' => (float)$row['relation_strength']
            ];
        }
        
        return $related;
    }
    
    /**
     * Phase 4b: Get all 1-hop neighbors of a node via semantic relationships (bidirectional).
     * Excludes PART_OF relationships (structural, not semantic).
     * Returns neighbors with relationship type, strength, context, and node summary (short title).
     * 
     * Used by Memory Agent Graph Expansion to pre-fetch graph context during pre-send.
     */
    public function getNodeNeighborhood(string $nodeId, int $limit = 5): array
    {
        $db = $this->getConnection();
        
        // Outgoing: this node → neighbor
        $outgoing = $db->executeQuery(
            "SELECT n.id, n.summary, n.content, n.category, n.importance, n.source_ref, n.source_range,
                    r.type as relation_type, r.strength as relation_strength, r.context as relation_context,
                    'outgoing' as direction
             FROM memory_nodes n
             JOIN memory_relationships r ON n.id = r.target_id
             WHERE r.source_id = ? AND r.type != 'PART_OF' AND n.is_active = 1
             ORDER BY r.strength DESC
             LIMIT ?",
            [$nodeId, $limit]
        )->fetchAllAssociative();
        
        // Incoming: neighbor → this node
        $incoming = $db->executeQuery(
            "SELECT n.id, n.summary, n.content, n.category, n.importance, n.source_ref, n.source_range,
                    r.type as relation_type, r.strength as relation_strength, r.context as relation_context,
                    'incoming' as direction
             FROM memory_nodes n
             JOIN memory_relationships r ON n.id = r.source_id
             WHERE r.target_id = ? AND r.type != 'PART_OF' AND n.is_active = 1
             ORDER BY r.strength DESC
             LIMIT ?",
            [$nodeId, $limit]
        )->fetchAllAssociative();
        
        // Merge, deduplicate by node ID, sort by strength
        $neighbors = [];
        $seen = [];
        foreach (array_merge($outgoing, $incoming) as $row) {
            if (in_array($row['id'], $seen)) continue;
            $seen[] = $row['id'];
            $neighbors[] = [
                'id' => $row['id'],
                'summary' => $row['summary'],
                'content' => $row['content'],
                'category' => $row['category'],
                'importance' => (float) $row['importance'],
                'sourceRef' => $row['source_ref'],
                'sourceRange' => $row['source_range'],
                'relationType' => $row['relation_type'],
                'relationStrength' => (float) $row['relation_strength'],
                'relationContext' => $row['relation_context'],
                'direction' => $row['direction'],
            ];
        }
        
        // Sort by strength descending, limit
        usort($neighbors, fn($a, $b) => $b['relationStrength'] <=> $a['relationStrength']);
        return array_slice($neighbors, 0, $limit);
    }
    
    /**
     * Find the root node for a given node.
     * Looks up the node with same source_ref and depth=0.
     * Returns the root MemoryNode, or null if the node is itself root or standalone.
     */
    public function findRootNode(string $nodeId): ?MemoryNode
    {
        $db = $this->getConnection();
        
        // Get the node's source_ref
        $node = $db->executeQuery(
            'SELECT source_ref, depth FROM memory_nodes WHERE id = ?',
            [$nodeId]
        )->fetchAssociative();
        
        if (!$node || empty($node['source_ref'])) {
            return null;
        }
        
        // Already root
        if (($node['depth'] ?? null) === 0 || $node['depth'] === '0') {
            return null;
        }
        
        // Find root: same source_ref, depth=0
        $row = $db->executeQuery(
            'SELECT * FROM memory_nodes WHERE source_ref = ? AND depth = 0 AND is_active = 1 LIMIT 1',
            [$node['source_ref']]
        )->fetchAssociative();
        
        return $row ? MemoryNode::fromArray($row) : null;
    }

    /**
     * Get all node IDs structurally related to a given node:
     * - All ancestors (recursive PART_OF walk up)
     * - All descendants (recursive PART_OF walk down)
     * - All siblings and their descendants (parent's other children, recursive)
     * Used to exclude structurally-related nodes from relationship analysis.
     */
    public function getStructurallyRelatedNodeIds(string $nodeId): array
    {
        $relatedIds = [];
        
        // Walk up: all ancestors
        $this->collectAncestors($nodeId, $relatedIds);
        
        // Walk down: all descendants
        $this->collectDescendants($nodeId, $relatedIds);
        
        // Siblings: parent's other children (and their descendants)
        $db = $this->getConnection();
        $parentResult = $db->executeQuery(
            "SELECT target_id FROM memory_relationships WHERE source_id = ? AND type = 'PART_OF'",
            [$nodeId]
        );
        foreach ($parentResult->fetchAllAssociative() as $row) {
            $parentId = $row['target_id'];
            // Get all children of this parent
            $childResult = $db->executeQuery(
                "SELECT source_id FROM memory_relationships WHERE target_id = ? AND type = 'PART_OF'",
                [$parentId]
            );
            foreach ($childResult->fetchAllAssociative() as $childRow) {
                $siblingId = $childRow['source_id'];
                if ($siblingId === $nodeId) continue; // skip self
                if (!in_array($siblingId, $relatedIds)) {
                    $relatedIds[] = $siblingId;
                    // Also exclude sibling's descendants
                    $this->collectDescendants($siblingId, $relatedIds);
                }
            }
        }
        
        return array_unique($relatedIds);
    }
    
    private function collectAncestors(string $nodeId, array &$collected): void
    {
        $db = $this->getConnection();
        $result = $db->executeQuery(
            "SELECT target_id FROM memory_relationships WHERE source_id = ? AND type = 'PART_OF'",
            [$nodeId]
        );
        foreach ($result->fetchAllAssociative() as $row) {
            $parentId = $row['target_id'];
            if (!in_array($parentId, $collected)) {
                $collected[] = $parentId;
                $this->collectAncestors($parentId, $collected);
            }
        }
    }
    
    private function collectDescendants(string $nodeId, array &$collected): void
    {
        $db = $this->getConnection();
        $result = $db->executeQuery(
            "SELECT source_id FROM memory_relationships WHERE target_id = ? AND type = 'PART_OF'",
            [$nodeId]
        );
        foreach ($result->fetchAllAssociative() as $row) {
            $childId = $row['source_id'];
            if (!in_array($childId, $collected)) {
                $collected[] = $childId;
                $this->collectDescendants($childId, $collected);
            }
        }
    }

    /**
     * Check if a node is a leaf (has no PART_OF children).
     */
    public function isLeafNode(string $nodeId): bool
    {
        $db = $this->getConnection();
        $count = $db->executeQuery(
            "SELECT COUNT(*) FROM memory_relationships WHERE target_id = ? AND type = 'PART_OF'",
            [$nodeId]
        )->fetchOne();
        return (int)$count === 0;
    }

    /**
     * Get all leaf nodes in the pack (nodes with no PART_OF children).
     * Optionally filter by source_ref.
     */
    public function getLeafNodes(?string $sourceRef = null): array
    {
        $db = $this->getConnection();
        
        $sql = "SELECT n.* FROM memory_nodes n 
                WHERE n.is_active = 1 
                AND n.id NOT IN (
                    SELECT DISTINCT target_id FROM memory_relationships WHERE type = 'PART_OF'
                )";
        $params = [];
        
        if ($sourceRef !== null) {
            $sql .= ' AND n.source_ref = ?';
            $params[] = $sourceRef;
        }
        
        $result = $db->executeQuery($sql, $params);
        $nodes = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $nodes[] = MemoryNode::fromArray($row);
        }
        return $nodes;
    }

    /**
     * Get direct PART_OF children of a node.
     */
    public function getChildNodes(string $nodeId): array
    {
        $db = $this->getConnection();
        $result = $db->executeQuery(
            "SELECT n.* FROM memory_nodes n 
             JOIN memory_relationships r ON n.id = r.source_id 
             WHERE r.target_id = ? AND r.type = 'PART_OF' AND n.is_active = 1",
            [$nodeId]
        );
        $nodes = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $nodes[] = MemoryNode::fromArray($row);
        }
        return $nodes;
    }

    /**
     * Get all root nodes (depth=0) in the pack.
     * Optionally exclude a specific source_ref.
     */
    public function getRootNodes(?string $excludeSourceRef = null): array
    {
        $db = $this->getConnection();
        
        $sql = 'SELECT * FROM memory_nodes WHERE depth = 0 AND is_active = 1';
        $params = [];
        
        if ($excludeSourceRef !== null) {
            $sql .= ' AND source_ref != ?';
            $params[] = $excludeSourceRef;
        }
        
        $result = $db->executeQuery($sql, $params);
        $nodes = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $nodes[] = MemoryNode::fromArray($row);
        }
        return $nodes;
    }

    public function relationshipExists(string $sourceId, string $targetId, ?string $type = null, bool $checkBothDirections = true): bool
    {
        $db = $this->getConnection();
        
        if ($checkBothDirections) {
            $sql = 'SELECT COUNT(*) FROM memory_relationships WHERE ((source_id = ? AND target_id = ?) OR (source_id = ? AND target_id = ?))';
            $params = [$sourceId, $targetId, $targetId, $sourceId];
            
            if ($type) {
                $sql .= ' AND type = ?';
                $params[] = $type;
            }
        } else {
            $sql = 'SELECT COUNT(*) FROM memory_relationships WHERE source_id = ? AND target_id = ?';
            $params = [$sourceId, $targetId];
            
            if ($type) {
                $sql .= ' AND type = ?';
                $params[] = $type;
            }
        }
        
        $result = $db->executeQuery($sql, $params);
        return (int)$result->fetchOne() > 0;
    }
    
    // ========================================
    // Tag Operations
    // ========================================
    
    public function addTag(string $nodeId, string $tag): MemoryTag
    {
        $db = $this->getConnection();
        
        // Skip if this exact tag already exists for this node
        $existing = $db->executeQuery(
            'SELECT id FROM memory_tags WHERE memory_id = ? AND tag = ?',
            [$nodeId, $tag]
        )->fetchAssociative();
        
        if ($existing) {
            return new MemoryTag($nodeId, $tag);
        }
        
        $tagEntity = new MemoryTag($nodeId, $tag);
        
        $db->executeStatement(
            'INSERT INTO memory_tags (id, memory_id, tag, created_at) VALUES (?, ?, ?, ?)',
            [
                $tagEntity->getId(),
                $tagEntity->getMemoryId(),
                $tagEntity->getTag(),
                $tagEntity->getCreatedAt()->format('Y-m-d H:i:s')
            ]
        );
        
        return $tagEntity;
    }
    
    public function getTagsForNode(string $nodeId): array
    {
        $db = $this->getConnection();
        
        $result = $db->executeQuery(
            'SELECT tag FROM memory_tags WHERE memory_id = ?',
            [$nodeId]
        );
        
        return array_column($result->fetchAllAssociative(), 'tag');
    }
    
    public function getAllTags(): array
    {
        $db = $this->getConnection();
        
        $result = $db->executeQuery(
            'SELECT DISTINCT tag FROM memory_tags ORDER BY tag'
        );
        
        return array_column($result->fetchAllAssociative(), 'tag');
    }
    
    // ========================================
    // Job Operations
    // ========================================
    
    public function createJob(string $type, array $payload = []): MemoryJob
    {
        $db = $this->getConnection();
        
        $job = new MemoryJob($type, $payload);
        
        $db->executeStatement(
            'INSERT INTO memory_jobs (id, type, status, payload, result, progress, total_steps, error, created_at, started_at, completed_at) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $job->getId(),
                $job->getType(),
                $job->getStatus(),
                json_encode($job->getPayload()),
                null,
                0,
                0,
                null,
                $job->getCreatedAt()->format('Y-m-d H:i:s'),
                null,
                null
            ]
        );
        
        return $job;
    }
    
    public function findJobById(string $jobId): ?MemoryJob
    {
        $db = $this->getConnection();
        
        $result = $db->executeQuery(
            'SELECT * FROM memory_jobs WHERE id = ?',
            [$jobId]
        );
        
        $row = $result->fetchAssociative();
        if (!$row) {
            return null;
        }
        
        return MemoryJob::fromArray($row);
    }
    
    public function updateJobProgress(string $jobId, int $progress, int $totalSteps): void
    {
        $db = $this->getConnection();
        
        $db->executeStatement(
            'UPDATE memory_jobs SET progress = ?, total_steps = ? WHERE id = ?',
            [$progress, $totalSteps, $jobId]
        );
    }
    
    public function startJob(MemoryJob|string $job): void
    {
        $jobId = $job instanceof MemoryJob ? $job->getId() : $job;
        $db = $this->getConnection();
        
        $db->executeStatement(
            'UPDATE memory_jobs SET status = ?, started_at = ? WHERE id = ?',
            [MemoryJob::STATUS_PROCESSING, date('Y-m-d H:i:s'), $jobId]
        );
    }
    
    public function completeJob(string $jobId, ?array $result = null): void
    {
        $db = $this->getConnection();
        
        $db->executeStatement(
            'UPDATE memory_jobs SET status = ?, completed_at = ?, result = ? WHERE id = ?',
            [MemoryJob::STATUS_COMPLETED, date('Y-m-d H:i:s'), $result ? json_encode($result) : null, $jobId]
        );
    }
    
    public function failJob(string $jobId, string $error): void
    {
        $db = $this->getConnection();
        
        $db->executeStatement(
            'UPDATE memory_jobs SET status = ?, completed_at = ?, error = ? WHERE id = ?',
            [MemoryJob::STATUS_FAILED, date('Y-m-d H:i:s'), $error, $jobId]
        );
    }
    
    public function getActiveJobs(): array
    {
        $db = $this->getConnection();
        
        $result = $db->executeQuery(
            'SELECT * FROM memory_jobs WHERE status IN (?, ?) ORDER BY created_at DESC',
            [MemoryJob::STATUS_PENDING, MemoryJob::STATUS_PROCESSING]
        );
        
        $jobs = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $jobs[] = MemoryJob::fromArray($row);
        }
        
        return $jobs;
    }
    
    /**
     * Get jobs that need processing (pending or in-progress)
     * Extraction jobs have priority — relationship analysis jobs are held
     * until all extraction jobs are completed.
     */
    public function getJobsToProcess(int $limit = 1): array
    {
        $db = $this->getConnection();
        
        // Check if any extraction jobs are still active
        $extractionActive = $db->executeQuery(
            'SELECT COUNT(*) FROM memory_jobs WHERE type = ? AND status IN (?, ?)',
            [MemoryJob::TYPE_EXTRACT_RECURSIVE, MemoryJob::STATUS_PENDING, MemoryJob::STATUS_PROCESSING]
        )->fetchOne();
        
        if ($extractionActive > 0) {
            // Only return extraction jobs while extractions are running
            $result = $db->executeQuery(
                'SELECT * FROM memory_jobs WHERE type = ? AND status IN (?, ?) ORDER BY created_at ASC LIMIT ?',
                [MemoryJob::TYPE_EXTRACT_RECURSIVE, MemoryJob::STATUS_PENDING, MemoryJob::STATUS_PROCESSING, $limit]
            );
        } else {
            // No active extractions — all job types eligible
            $result = $db->executeQuery(
                'SELECT * FROM memory_jobs WHERE status IN (?, ?) ORDER BY created_at ASC LIMIT ?',
                [MemoryJob::STATUS_PENDING, MemoryJob::STATUS_PROCESSING, $limit]
            );
        }
        
        $jobs = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $jobs[] = MemoryJob::fromArray($row);
        }
        
        return $jobs;
    }
    
    
    /**
     * Get recently completed jobs since timestamp
     */
    public function getRecentlyCompletedJobs(string $since): array
    {
        $db = $this->getConnection();
        
        $result = $db->executeQuery(
            'SELECT * FROM memory_jobs WHERE status IN (?, ?) AND completed_at > ? ORDER BY completed_at DESC',
            [MemoryJob::STATUS_COMPLETED, MemoryJob::STATUS_FAILED, $since]
        );
        
        $jobs = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $jobs[] = MemoryJob::fromArray($row);
        }
        
        return $jobs;
    }
    
    /**
     * Update job payload (for storing progress state)
     */
    public function updateJobPayload(string $jobId, array $payload): void
    {
        $db = $this->getConnection();
        
        $db->executeStatement(
            'UPDATE memory_jobs SET payload = ? WHERE id = ?',
            [json_encode($payload), $jobId]
        );
    }
    
    // ========================================
    // Statistics & Graph Data
    // ========================================
    
    public function getStats(): array
    {
        $db = $this->getConnection();
        
        // Total count
        $totalResult = $db->executeQuery(
            "SELECT COUNT(*) as count FROM memory_nodes WHERE is_active = 1"
        );
        $totalCount = (int) $totalResult->fetchOne();
        
        // Count by category
        $categoryResult = $db->executeQuery(
            "SELECT category, COUNT(*) as count FROM memory_nodes 
             WHERE is_active = 1 GROUP BY category"
        );
        $categories = [];
        foreach ($categoryResult->fetchAllAssociative() as $row) {
            $categories[$row['category']] = (int) $row['count'];
        }
        
        // Unique tags count
        $tagsResult = $db->executeQuery(
            "SELECT COUNT(DISTINCT tag) as count FROM memory_tags t
             JOIN memory_nodes n ON t.memory_id = n.id
             WHERE n.is_active = 1"
        );
        $tagsCount = (int) $tagsResult->fetchOne();
        
        // Relationships count
        $relResult = $db->executeQuery(
            "SELECT COUNT(*) as count FROM memory_relationships r
             JOIN memory_nodes n ON r.source_id = n.id
             WHERE n.is_active = 1"
        );
        $relationshipsCount = (int) $relResult->fetchOne();
        
        return [
            'totalNodes' => $totalCount,
            'totalRelationships' => $relationshipsCount,
            'categoryCounts' => $categories,
            'tagsCount' => $tagsCount
        ];
    }
    
    /**
     * Check if legacy memory files have been migrated (extracted) into this pack
     * Looks for nodes with source_type = 'legacy_memory' and source_ref containing legacy file names
     *
     * @return array ['migrated' => bool, 'files' => [...], 'allMigrated' => bool]
     */
    public function isLegacyMemoryMigrated(): array
    {
        $db = $this->getConnection();

        $result = $db->executeQuery(
            "SELECT DISTINCT source_ref FROM memory_nodes 
             WHERE source_type = 'legacy_memory' AND is_active = 1"
        );

        $sourceRefs = array_column($result->fetchAllAssociative(), 'source_ref');

        $files = [
            'conversations' => false,
            'inner-thoughts' => false,
            'knowledge-base' => false
        ];

        foreach ($sourceRefs as $ref) {
            if (strpos($ref, 'conversations.md') !== false) {
                $files['conversations'] = true;
            }
            if (strpos($ref, 'inner-thoughts.md') !== false) {
                $files['inner-thoughts'] = true;
            }
            if (strpos($ref, 'knowledge-base.md') !== false) {
                $files['knowledge-base'] = true;
            }
        }

        $migrated = $files['conversations'] || $files['inner-thoughts'] || $files['knowledge-base'];

        return [
            'migrated' => $migrated,
            'files' => $files,
            'allMigrated' => $files['conversations'] && $files['inner-thoughts'] && $files['knowledge-base']
        ];
    }

    public function getGraphData(): array
    {
        $nodes = $this->findAllNodes(true);
        $relationships = $this->findAllRelationships();
        
        $graphNodes = [];
        foreach ($nodes as $node) {
            $graphNodes[] = [
                'id' => $node->getId(),
                'content' => $node->getContent(),
                'summary' => $node->getSummary(),
                'category' => $node->getCategory(),
                'importance' => $node->getImportance(),
                'confidence' => $node->getConfidence(),
                'createdAt' => $node->getCreatedAt()->format('Y-m-d H:i:s'),
                'accessCount' => $node->getAccessCount(),
                'tags' => $this->getTagsForNode($node->getId()),
                'sourceType' => $node->getSourceType(),
                'sourceRef' => $node->getSourceRef(),
                'sourceRange' => $node->getSourceRange(),
                'depth' => $node->getDepth()
            ];
        }
        
        $graphEdges = [];
        foreach ($relationships as $rel) {
            $graphEdges[] = [
                'id' => $rel->getId(),
                'source' => $rel->getSourceId(),
                'target' => $rel->getTargetId(),
                'type' => $rel->getType(),
                'strength' => $rel->getStrength(),
                'context' => $rel->getContext()
            ];
        }
        
        return [
            'nodes' => $graphNodes,
            'edges' => $graphEdges,
            'stats' => $this->getStats()
        ];
    }
    
    public function getGraphDelta(string $since): array
    {
        $db = $this->getConnection();
        
        // Get nodes created after timestamp
        $nodesResult = $db->executeQuery(
            'SELECT * FROM memory_nodes 
             WHERE is_active = 1 AND created_at > ?
             ORDER BY created_at ASC',
            [$since]
        );
        
        $nodes = [];
        foreach ($nodesResult->fetchAllAssociative() as $row) {
            $node = MemoryNode::fromArray($row);
            $nodes[] = [
                'id' => $node->getId(),
                'content' => $node->getContent(),
                'summary' => $node->getSummary(),
                'category' => $node->getCategory(),
                'importance' => $node->getImportance(),
                'confidence' => $node->getConfidence(),
                'createdAt' => $node->getCreatedAt()->format('Y-m-d H:i:s'),
                'accessCount' => $node->getAccessCount(),
                'tags' => $this->getTagsForNode($node->getId()),
                'sourceType' => $node->getSourceType(),
                'sourceRef' => $node->getSourceRef(),
                'sourceRange' => $node->getSourceRange(),
                'depth' => $node->getDepth()
            ];
        }
        
        // Get edges created after timestamp
        $edgesResult = $db->executeQuery(
            "SELECT r.* FROM memory_relationships r
             JOIN memory_nodes n ON r.source_id = n.id
             WHERE r.created_at > ?",
            [$since]
        );
        
        $edges = [];
        foreach ($edgesResult->fetchAllAssociative() as $row) {
            $rel = MemoryRelationship::fromArray($row);
            $edges[] = [
                'id' => $rel->getId(),
                'source' => $rel->getSourceId(),
                'target' => $rel->getTargetId(),
                'type' => $rel->getType(),
                'strength' => $rel->getStrength(),
                'context' => $rel->getContext()
            ];
        }
        
        return [
            'nodes' => $nodes,
            'edges' => $edges
        ];
    }
    
    // ========================================
    // Consolidation
    // ========================================
    
    public function logConsolidation(string $action, array $affectedIds, array $details = []): void
    {
        $db = $this->getConnection();
        
        $db->executeStatement(
            'INSERT INTO memory_consolidation_log (id, action, affected_ids, details, created_at) VALUES (?, ?, ?, ?, ?)',
            [
                uuid_create(),
                $action,
                json_encode($affectedIds),
                json_encode($details),
                date('Y-m-d H:i:s')
            ]
        );
    }
    
    public function decayImportance(float $decayRate = 0.99, int $minDaysSinceAccess = 7): int
    {
        $db = $this->getConnection();
        
        $cutoffDate = (new \DateTime())->modify("-{$minDaysSinceAccess} days")->format('Y-m-d H:i:s');
        
        $result = $db->executeStatement(
            "UPDATE memory_nodes 
             SET importance = importance * ? 
             WHERE is_active = 1 
             AND (last_accessed IS NULL OR last_accessed < ?)",
            [$decayRate, $cutoffDate]
        );
        
        return $result;
    }
    
    public function prune(float $importanceThreshold = 0.1, int $minAgeDays = 30): int
    {
        $db = $this->getConnection();
        
        $cutoffDate = (new \DateTime())->modify("-{$minAgeDays} days")->format('Y-m-d H:i:s');
        
        $result = $db->executeQuery(
            "SELECT id FROM memory_nodes 
             WHERE is_active = 1 
             AND importance < ? AND created_at < ?
             AND (last_accessed IS NULL OR last_accessed < ?)",
            [$importanceThreshold, $cutoffDate, $cutoffDate]
        );
        
        $prunedIds = array_column($result->fetchAllAssociative(), 'id');
        
        if (!empty($prunedIds)) {
            $placeholders = implode(',', array_fill(0, count($prunedIds), '?'));
            $db->executeStatement(
                "UPDATE memory_nodes SET is_active = 0 WHERE id IN ({$placeholders})",
                $prunedIds
            );
            
            $this->logConsolidation('prune', $prunedIds, [
                'importanceThreshold' => $importanceThreshold,
                'minAgeDays' => $minAgeDays
            ]);
        }
        
        return count($prunedIds);
    }
}
