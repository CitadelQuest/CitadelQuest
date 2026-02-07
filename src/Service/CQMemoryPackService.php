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
                is_active INTEGER DEFAULT 1
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
        ?string $sourceRange = null
    ): MemoryNode {
        $db = $this->getConnection();
        
        $node = new MemoryNode($content, $category, $importance, $summary);
        $node->setSourceType($sourceType);
        $node->setSourceRef($sourceRef);
        $node->setSourceRange($sourceRange);
        
        // Auto-generate summary if not provided
        if (!$summary && strlen($content) > 100) {
            $node->setSummary(substr($content, 0, 100) . '...');
        }
        
        $db->executeStatement(
            'INSERT INTO memory_nodes 
            (id, content, summary, category, importance, confidence, created_at, last_accessed, access_count, source_type, source_ref, source_range, is_active) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
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
                1
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
    
    public function findByKeyword(string $keyword, int $limit = 10): array
    {
        $db = $this->getConnection();
        
        $result = $db->executeQuery(
            "SELECT * FROM memory_nodes 
             WHERE is_active = 1 
             AND (content LIKE ? OR summary LIKE ?)
             ORDER BY importance DESC, created_at DESC
             LIMIT ?",
            ["%{$keyword}%", "%{$keyword}%", $limit]
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
     * Replicates the scoring logic from SpiritMemoryService::recall()
     * but operates on the currently open pack (no spiritId needed).
     */
    public function recall(
        string $query,
        ?string $category = null,
        array $tags = [],
        int $limit = 10,
        bool $includeRelated = true,
        float $recencyWeight = 0.2,
        float $importanceWeight = 0.4,
        float $relevanceWeight = 0.4
    ): array {
        $db = $this->getConnection();
        
        $sql = "SELECT *, 
                (CASE WHEN content LIKE ? OR summary LIKE ? THEN 1.0 ELSE 0.0 END) as relevance_score
                FROM memory_nodes 
                WHERE is_active = 1";
        $params = ["%{$query}%", "%{$query}%"];
        
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
        
        $scoredResults = [];
        $now = new \DateTime();
        
        foreach ($rows as $row) {
            $node = MemoryNode::fromArray($row);
            
            $daysSinceCreation = max(1, $now->diff($node->getCreatedAt())->days);
            $recencyScore = 1.0 / (1 + log($daysSinceCreation));
            
            $relevanceScore = (float)$row['relevance_score'];
            $importanceScore = $node->getImportance();
            
            $finalScore = ($relevanceScore * $relevanceWeight) +
                          ($importanceScore * $importanceWeight) +
                          ($recencyScore * $recencyWeight);
            
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
                    $relId = $rel->getId();
                    if (!isset($relatedMemories[$relId])) {
                        $relatedMemories[$relId] = [
                            'node' => $rel,
                            'score' => 0,
                            'tags' => $this->getTagsForNode($relId),
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
        
        // Create SUPERSEDES relationship (new node supersedes old)
        $this->createRelationship(
            $newNode->getId(),
            $oldNode->getId(),
            MemoryNode::RELATION_SUPERSEDES,
            1.0,
            $reason
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
     */
    public function getJobsToProcess(int $limit = 1): array
    {
        $db = $this->getConnection();
        
        $result = $db->executeQuery(
            'SELECT * FROM memory_jobs WHERE status IN (?, ?) ORDER BY created_at ASC LIMIT ?',
            [MemoryJob::STATUS_PENDING, MemoryJob::STATUS_PROCESSING, $limit]
        );
        
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
                'sourceRange' => $node->getSourceRange()
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
                'sourceRange' => $node->getSourceRange()
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
