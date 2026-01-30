<?php

namespace App\Service;

use App\Entity\SpiritMemoryNode;
use App\Entity\SpiritMemoryRelationship;
use App\Entity\SpiritMemoryTag;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Psr\Log\LoggerInterface;

class SpiritMemoryService
{
    private $user;

    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly Security $security,
        private readonly LoggerInterface $logger
    ) {
        $this->user = $security->getUser();
    }

    private function getUserDb()
    {
        return $this->userDatabaseManager->getDatabaseConnection($this->user);
    }

    // ========================================
    // STORE Operations
    // ========================================

    /**
     * Store a new memory node
     */
    public function store(
        string $spiritId,
        string $content,
        string $category = SpiritMemoryNode::CATEGORY_KNOWLEDGE,
        float $importance = 0.5,
        ?string $summary = null,
        ?string $sourceType = null,
        ?string $sourceRef = null,
        array $tags = [],
        ?string $relatesTo = null
    ): SpiritMemoryNode {
        $db = $this->getUserDb();

        // Create the memory node
        $node = new SpiritMemoryNode($spiritId, $content, $category, $importance, $summary);
        $node->setSourceType($sourceType);
        $node->setSourceRef($sourceRef);

        // Auto-generate summary if not provided (first 100 chars)
        if (!$summary && strlen($content) > 100) {
            $node->setSummary(substr($content, 0, 100) . '...');
        }

        // Insert into database
        $db->executeStatement(
            'INSERT INTO spirit_memory_nodes 
            (id, spirit_id, content, summary, category, importance, confidence, created_at, last_accessed, access_count, source_type, source_ref, is_active, superseded_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $node->getId(),
                $node->getSpiritId(),
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
                1,
                null
            ]
        );

        // Add tags
        foreach ($tags as $tag) {
            $this->addTag($node->getId(), $tag);
        }

        // Create relationship if relatesTo is specified
        if ($relatesTo) {
            $relatedMemory = $this->findByKeyword($spiritId, $relatesTo, 1);
            if (!empty($relatedMemory)) {
                $this->createRelationship(
                    $node->getId(),
                    $relatedMemory[0]->getId(),
                    SpiritMemoryNode::RELATION_RELATES_TO
                );
            }
        }

        $this->logger->info('Spirit memory stored', [
            'memoryId' => $node->getId(),
            'spiritId' => $spiritId,
            'category' => $category
        ]);

        return $node;
    }

    /**
     * Add a tag to a memory
     */
    public function addTag(string $memoryId, string $tag): SpiritMemoryTag
    {
        $db = $this->getUserDb();

        $tagEntity = new SpiritMemoryTag($memoryId, $tag);

        $db->executeStatement(
            'INSERT INTO spirit_memory_tags (id, memory_id, tag, created_at) VALUES (?, ?, ?, ?)',
            [
                $tagEntity->getId(),
                $tagEntity->getMemoryId(),
                $tagEntity->getTag(),
                $tagEntity->getCreatedAt()->format('Y-m-d H:i:s')
            ]
        );

        return $tagEntity;
    }

    /**
     * Create a relationship between two memories
     */
    public function createRelationship(
        string $sourceId,
        string $targetId,
        string $type,
        float $strength = 1.0,
        ?string $context = null
    ): SpiritMemoryRelationship {
        $db = $this->getUserDb();

        $relationship = new SpiritMemoryRelationship($sourceId, $targetId, $type, $strength, $context);

        $db->executeStatement(
            'INSERT INTO spirit_memory_relationships (id, source_id, target_id, type, strength, context, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
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

    // ========================================
    // RECALL Operations
    // ========================================

    /**
     * Recall memories based on query with weighted scoring
     */
    public function recall(
        string $spiritId,
        string $query,
        ?string $category = null,
        array $tags = [],
        int $limit = 10,
        bool $includeRelated = true,
        float $recencyWeight = 0.2,
        float $importanceWeight = 0.4,
        float $relevanceWeight = 0.4
    ): array {
        $db = $this->getUserDb();

        // Build the base query
        $sql = "SELECT *, 
                (CASE WHEN content LIKE ? OR summary LIKE ? THEN 1.0 ELSE 0.0 END) as relevance_score
                FROM spirit_memory_nodes 
                WHERE spirit_id = ? AND is_active = 1";
        $params = ["%{$query}%", "%{$query}%", $spiritId];

        // Add category filter
        if ($category) {
            $sql .= " AND category = ?";
            $params[] = $category;
        }

        // Add tag filter if specified
        if (!empty($tags)) {
            $placeholders = implode(',', array_fill(0, count($tags), '?'));
            $sql .= " AND id IN (SELECT memory_id FROM spirit_memory_tags WHERE tag IN ({$placeholders}))";
            $params = array_merge($params, $tags);
        }

        $sql .= " ORDER BY relevance_score DESC, importance DESC, created_at DESC LIMIT ?";
        $params[] = $limit * 2; // Get more for scoring

        $result = $db->executeQuery($sql, $params);
        $rows = $result->fetchAllAssociative();

        // Score and sort results
        $scoredResults = [];
        $now = new \DateTime();

        foreach ($rows as $row) {
            $node = SpiritMemoryNode::fromArray($row);
            
            // Calculate recency score (0-1, higher = more recent)
            $createdAt = $node->getCreatedAt();
            $daysSinceCreation = max(1, $now->diff($createdAt)->days);
            $recencyScore = 1.0 / (1 + log($daysSinceCreation));

            // Calculate final score
            $relevanceScore = (float)$row['relevance_score'];
            $importanceScore = $node->getImportance();

            $finalScore = ($relevanceScore * $relevanceWeight) +
                          ($importanceScore * $importanceWeight) +
                          ($recencyScore * $recencyWeight);

            $scoredResults[] = [
                'node' => $node,
                'score' => $finalScore,
                'tags' => $this->getTagsForMemory($node->getId())
            ];

            // Update access count
            $this->incrementAccessCount($node->getId());
        }

        // Sort by score
        usort($scoredResults, fn($a, $b) => $b['score'] <=> $a['score']);

        // Limit results
        $scoredResults = array_slice($scoredResults, 0, $limit);

        // Include related memories if requested
        if ($includeRelated && !empty($scoredResults)) {
            $relatedMemories = [];
            foreach ($scoredResults as $result) {
                $related = $this->getRelatedMemories($result['node']->getId(), 2);
                foreach ($related as $rel) {
                    if (!isset($relatedMemories[$rel['node']->getId()])) {
                        $relatedMemories[$rel['node']->getId()] = $rel;
                    }
                }
            }
            // Add related memories that aren't already in results
            foreach ($relatedMemories as $relId => $relData) {
                $alreadyIncluded = false;
                foreach ($scoredResults as $sr) {
                    if ($sr['node']->getId() === $relId) {
                        $alreadyIncluded = true;
                        break;
                    }
                }
                if (!$alreadyIncluded) {
                    $relData['isRelated'] = true;
                    $scoredResults[] = $relData;
                }
            }
        }

        return $scoredResults;
    }

    /**
     * Find memories by keyword (simple search)
     */
    public function findByKeyword(string $spiritId, string $keyword, int $limit = 10): array
    {
        $db = $this->getUserDb();

        $result = $db->executeQuery(
            "SELECT * FROM spirit_memory_nodes 
             WHERE spirit_id = ? AND is_active = 1 
             AND (content LIKE ? OR summary LIKE ?)
             ORDER BY importance DESC, created_at DESC
             LIMIT ?",
            [$spiritId, "%{$keyword}%", "%{$keyword}%", $limit]
        );

        $nodes = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $nodes[] = SpiritMemoryNode::fromArray($row);
        }

        return $nodes;
    }

    /**
     * Get a memory by ID
     */
    public function findById(string $memoryId): ?SpiritMemoryNode
    {
        $db = $this->getUserDb();

        $result = $db->executeQuery(
            'SELECT * FROM spirit_memory_nodes WHERE id = ?',
            [$memoryId]
        );

        $row = $result->fetchAssociative();
        if (!$row) {
            return null;
        }

        return SpiritMemoryNode::fromArray($row);
    }

    /**
     * Check if memories have already been extracted from a specific source
     * Used for duplicate prevention in memoryExtract
     * 
     * @return array|null Null if not extracted, or array with extraction info
     */
    public function hasExtractedFromSource(string $spiritId, string $sourceType, string $sourceRef): ?array
    {
        $db = $this->getUserDb();

        $result = $db->executeQuery(
            "SELECT COUNT(*) as count, MAX(created_at) as last_extracted 
             FROM spirit_memory_nodes 
             WHERE spirit_id = ? AND source_type = ? AND source_ref = ? AND is_active = 1",
            [$spiritId, $sourceType, $sourceRef]
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

    /**
     * Check if legacy memory files have been migrated to v3 for a Spirit
     * 
     * Looks for memories with source_type = 'legacy_memory' and source_ref 
     * containing the legacy file names (conversations.md, inner-thoughts.md, knowledge-base.md)
     * 
     * @return array ['migrated' => bool, 'files' => ['conversations' => bool, 'inner-thoughts' => bool, 'knowledge-base' => bool]]
     */
    public function isLegacyMemoryMigrated(string $spiritId): array
    {
        $db = $this->getUserDb();

        $result = $db->executeQuery(
            "SELECT DISTINCT source_ref FROM spirit_memory_nodes 
             WHERE spirit_id = ? AND source_type = 'legacy_memory' AND is_active = 1",
            [$spiritId]
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

        // Consider migrated if at least one legacy file has been processed
        $migrated = $files['conversations'] || $files['inner-thoughts'] || $files['knowledge-base'];

        return [
            'migrated' => $migrated,
            'files' => $files,
            'allMigrated' => $files['conversations'] && $files['inner-thoughts'] && $files['knowledge-base']
        ];
    }

    /**
     * Get memory statistics for a Spirit (for UI display)
     */
    public function getMemoryStats(string $spiritId): array
    {
        $db = $this->getUserDb();

        // Total active memories
        $totalResult = $db->executeQuery(
            "SELECT COUNT(*) as count FROM spirit_memory_nodes WHERE spirit_id = ? AND is_active = 1",
            [$spiritId]
        );
        $totalCount = (int) $totalResult->fetchOne();

        // Count by category
        $categoryResult = $db->executeQuery(
            "SELECT category, COUNT(*) as count FROM spirit_memory_nodes 
             WHERE spirit_id = ? AND is_active = 1 
             GROUP BY category",
            [$spiritId]
        );
        $categories = [];
        foreach ($categoryResult->fetchAllAssociative() as $row) {
            $categories[$row['category']] = (int) $row['count'];
        }

        // Unique tags count
        $tagsResult = $db->executeQuery(
            "SELECT COUNT(DISTINCT tag) as count FROM spirit_memory_tags t
             JOIN spirit_memory_nodes n ON t.memory_id = n.id
             WHERE n.spirit_id = ? AND n.is_active = 1",
            [$spiritId]
        );
        $tagsCount = (int) $tagsResult->fetchOne();

        // Relationships count
        $relResult = $db->executeQuery(
            "SELECT COUNT(*) as count FROM spirit_memory_relationships r
             JOIN spirit_memory_nodes n ON r.source_id = n.id
             WHERE n.spirit_id = ? AND n.is_active = 1",
            [$spiritId]
        );
        $relationshipsCount = (int) $relResult->fetchOne();

        return [
            'totalMemories' => $totalCount,
            'categories' => $categories,
            'tagsCount' => $tagsCount,
            'relationshipsCount' => $relationshipsCount
        ];
    }

    /**
     * Get all memories for a spirit
     */
    public function findAllBySpirit(string $spiritId, bool $activeOnly = true): array
    {
        $db = $this->getUserDb();

        $sql = 'SELECT * FROM spirit_memory_nodes WHERE spirit_id = ?';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY created_at DESC';

        $result = $db->executeQuery($sql, [$spiritId]);

        $nodes = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $nodes[] = SpiritMemoryNode::fromArray($row);
        }

        return $nodes;
    }

    /**
     * Get tags for a memory
     */
    public function getTagsForMemory(string $memoryId): array
    {
        $db = $this->getUserDb();

        $result = $db->executeQuery(
            'SELECT tag FROM spirit_memory_tags WHERE memory_id = ?',
            [$memoryId]
        );

        return array_column($result->fetchAllAssociative(), 'tag');
    }

    /**
     * Get related memories via relationships
     */
    public function getRelatedMemories(string $memoryId, int $limit = 5): array
    {
        $db = $this->getUserDb();

        // Get outgoing relationships
        $result = $db->executeQuery(
            "SELECT n.*, r.type as relation_type, r.strength as relation_strength
             FROM spirit_memory_nodes n
             JOIN spirit_memory_relationships r ON n.id = r.target_id
             WHERE r.source_id = ? AND n.is_active = 1
             LIMIT ?",
            [$memoryId, $limit]
        );

        $related = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $related[] = [
                'node' => SpiritMemoryNode::fromArray($row),
                'relationType' => $row['relation_type'],
                'relationStrength' => (float)$row['relation_strength'],
                'tags' => $this->getTagsForMemory($row['id']),
                'score' => (float)$row['relation_strength']
            ];
        }

        return $related;
    }

    /**
     * Increment access count for a memory
     */
    private function incrementAccessCount(string $memoryId): void
    {
        $db = $this->getUserDb();

        $db->executeStatement(
            'UPDATE spirit_memory_nodes SET access_count = access_count + 1, last_accessed = ? WHERE id = ?',
            [date('Y-m-d H:i:s'), $memoryId]
        );
    }

    // ========================================
    // UPDATE Operations
    // ========================================

    /**
     * Update a memory (creates EVOLVED_INTO relationship)
     */
    public function update(string $memoryId, string $newContent, ?string $reason = null): SpiritMemoryNode
    {
        $db = $this->getUserDb();

        $oldMemory = $this->findById($memoryId);
        if (!$oldMemory) {
            throw new \RuntimeException('Memory not found');
        }

        // Create new memory with updated content
        $newMemory = $this->store(
            $oldMemory->getSpiritId(),
            $newContent,
            $oldMemory->getCategory(),
            $oldMemory->getImportance(),
            null, // Will auto-generate summary
            'derived',
            $memoryId,
            $this->getTagsForMemory($memoryId)
        );

        // Create EVOLVED_INTO relationship
        $this->createRelationship(
            $oldMemory->getId(),
            $newMemory->getId(),
            SpiritMemoryNode::RELATION_EVOLVED_INTO,
            1.0,
            $reason
        );

        // Mark old memory as superseded
        $db->executeStatement(
            'UPDATE spirit_memory_nodes SET superseded_by = ? WHERE id = ?',
            [$newMemory->getId(), $oldMemory->getId()]
        );

        // Log consolidation action
        $this->logConsolidation($oldMemory->getSpiritId(), 'update', [$oldMemory->getId(), $newMemory->getId()], [
            'reason' => $reason,
            'oldContent' => substr($oldMemory->getContent(), 0, 100),
            'newContent' => substr($newContent, 0, 100)
        ]);

        $this->logger->info('Spirit memory updated', [
            'oldMemoryId' => $oldMemory->getId(),
            'newMemoryId' => $newMemory->getId()
        ]);

        return $newMemory;
    }

    // ========================================
    // FORGET Operations
    // ========================================

    /**
     * Soft delete a memory (mark as inactive)
     */
    public function forget(string $memoryId, ?string $reason = null): bool
    {
        $db = $this->getUserDb();

        $memory = $this->findById($memoryId);
        if (!$memory) {
            return false;
        }

        $db->executeStatement(
            'UPDATE spirit_memory_nodes SET is_active = 0 WHERE id = ?',
            [$memoryId]
        );

        // Log consolidation action
        $this->logConsolidation($memory->getSpiritId(), 'forget', [$memoryId], [
            'reason' => $reason,
            'content' => substr($memory->getContent(), 0, 100)
        ]);

        $this->logger->info('Spirit memory forgotten', [
            'memoryId' => $memoryId,
            'reason' => $reason
        ]);

        return true;
    }

    /**
     * Hard delete a memory (permanent)
     */
    public function delete(string $memoryId): bool
    {
        $db = $this->getUserDb();

        // Delete tags first
        $db->executeStatement('DELETE FROM spirit_memory_tags WHERE memory_id = ?', [$memoryId]);

        // Delete relationships
        $db->executeStatement(
            'DELETE FROM spirit_memory_relationships WHERE source_id = ? OR target_id = ?',
            [$memoryId, $memoryId]
        );

        // Delete the memory
        $db->executeStatement('DELETE FROM spirit_memory_nodes WHERE id = ?', [$memoryId]);

        return true;
    }

    // ========================================
    // CONSOLIDATION Operations
    // ========================================

    /**
     * Log a consolidation action
     */
    public function logConsolidation(string $spiritId, string $action, array $affectedIds, array $details = []): void
    {
        $db = $this->getUserDb();

        $db->executeStatement(
            'INSERT INTO spirit_memory_consolidation_log (id, spirit_id, action, affected_ids, details, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [
                uuid_create(),
                $spiritId,
                $action,
                json_encode($affectedIds),
                json_encode($details),
                date('Y-m-d H:i:s')
            ]
        );
    }

    /**
     * Decay importance of old, rarely-accessed memories
     */
    public function decayImportance(string $spiritId, float $decayRate = 0.99, int $minDaysSinceAccess = 7): int
    {
        $db = $this->getUserDb();

        $cutoffDate = (new \DateTime())->modify("-{$minDaysSinceAccess} days")->format('Y-m-d H:i:s');

        $result = $db->executeStatement(
            "UPDATE spirit_memory_nodes 
             SET importance = importance * ? 
             WHERE spirit_id = ? AND is_active = 1 
             AND (last_accessed IS NULL OR last_accessed < ?)",
            [$decayRate, $spiritId, $cutoffDate]
        );

        return $result;
    }

    /**
     * Prune low-importance, old memories
     */
    public function prune(string $spiritId, float $importanceThreshold = 0.1, int $minAgeDays = 30): int
    {
        $db = $this->getUserDb();

        $cutoffDate = (new \DateTime())->modify("-{$minAgeDays} days")->format('Y-m-d H:i:s');

        // Get memories to prune
        $result = $db->executeQuery(
            "SELECT id FROM spirit_memory_nodes 
             WHERE spirit_id = ? AND is_active = 1 
             AND importance < ? AND created_at < ?
             AND (last_accessed IS NULL OR last_accessed < ?)",
            [$spiritId, $importanceThreshold, $cutoffDate, $cutoffDate]
        );

        $prunedIds = array_column($result->fetchAllAssociative(), 'id');

        if (!empty($prunedIds)) {
            // Soft delete
            $placeholders = implode(',', array_fill(0, count($prunedIds), '?'));
            $db->executeStatement(
                "UPDATE spirit_memory_nodes SET is_active = 0 WHERE id IN ({$placeholders})",
                $prunedIds
            );

            // Log consolidation
            $this->logConsolidation($spiritId, 'prune', $prunedIds, [
                'importanceThreshold' => $importanceThreshold,
                'minAgeDays' => $minAgeDays
            ]);
        }

        return count($prunedIds);
    }

    // ========================================
    // STATISTICS
    // ========================================

    /**
     * Get memory statistics for a spirit
     */
    public function getStats(string $spiritId): array
    {
        $db = $this->getUserDb();

        // Total count
        $totalResult = $db->executeQuery(
            'SELECT COUNT(*) as count FROM spirit_memory_nodes WHERE spirit_id = ?',
            [$spiritId]
        );
        $total = (int)$totalResult->fetchOne();

        // Active count
        $activeResult = $db->executeQuery(
            'SELECT COUNT(*) as count FROM spirit_memory_nodes WHERE spirit_id = ? AND is_active = 1',
            [$spiritId]
        );
        $active = (int)$activeResult->fetchOne();

        // By category
        $categoryResult = $db->executeQuery(
            "SELECT category, COUNT(*) as count FROM spirit_memory_nodes 
             WHERE spirit_id = ? AND is_active = 1 GROUP BY category",
            [$spiritId]
        );
        $byCategory = [];
        foreach ($categoryResult->fetchAllAssociative() as $row) {
            $byCategory[$row['category']] = (int)$row['count'];
        }

        // Relationship count
        $relResult = $db->executeQuery(
            "SELECT COUNT(*) as count FROM spirit_memory_relationships r
             JOIN spirit_memory_nodes n ON r.source_id = n.id
             WHERE n.spirit_id = ?",
            [$spiritId]
        );
        $relationships = (int)$relResult->fetchOne();

        // Average importance
        $avgResult = $db->executeQuery(
            'SELECT AVG(importance) as avg FROM spirit_memory_nodes WHERE spirit_id = ? AND is_active = 1',
            [$spiritId]
        );
        $avgImportance = (float)$avgResult->fetchOne();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
            'byCategory' => $byCategory,
            'relationships' => $relationships,
            'averageImportance' => round($avgImportance, 2)
        ];
    }
}
