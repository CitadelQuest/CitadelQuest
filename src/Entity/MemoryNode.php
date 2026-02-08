<?php

namespace App\Entity;

use JsonSerializable;

/**
 * Memory Node entity for CQ Memory Pack (.cqmpack)
 * 
 * Unlike SpiritMemoryNode, this entity does not have a spiritId
 * as it's designed for standalone, portable memory packs.
 */
class MemoryNode implements JsonSerializable
{
    private string $id;
    private string $content;
    private ?string $summary;
    private string $category;
    private float $importance;
    private float $confidence;
    private \DateTimeInterface $createdAt;
    private ?\DateTimeInterface $lastAccessed;
    private int $accessCount;
    private ?string $sourceType;
    private ?string $sourceRef;
    private ?string $sourceRange;
    private bool $isActive;
    private ?int $depth;

    // Relationship types as constants
    public const RELATION_PART_OF = 'PART_OF';
    public const RELATION_RELATES_TO = 'RELATES_TO';
    public const RELATION_CONTRADICTS = 'CONTRADICTS';
    public const RELATION_REINFORCES = 'REINFORCES';

    // Category types as constants
    public const CATEGORY_CONVERSATION = 'conversation';
    public const CATEGORY_THOUGHT = 'thought';
    public const CATEGORY_KNOWLEDGE = 'knowledge';
    public const CATEGORY_FACT = 'fact';
    public const CATEGORY_PREFERENCE = 'preference';

    public function __construct(
        string $content,
        string $category = self::CATEGORY_KNOWLEDGE,
        float $importance = 0.5,
        ?string $summary = null
    ) {
        $this->id = uuid_create();
        $this->content = $content;
        $this->summary = $summary;
        $this->category = $category;
        $this->importance = $importance;
        $this->confidence = 1.0;
        $this->createdAt = new \DateTime();
        $this->lastAccessed = null;
        $this->accessCount = 0;
        $this->sourceType = null;
        $this->sourceRef = null;
        $this->sourceRange = null;
        $this->isActive = true;
        $this->depth = null;
    }

    // Getters
    public function getId(): string
    {
        return $this->id;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getImportance(): float
    {
        return $this->importance;
    }

    public function getConfidence(): float
    {
        return $this->confidence;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getLastAccessed(): ?\DateTimeInterface
    {
        return $this->lastAccessed;
    }

    public function getAccessCount(): int
    {
        return $this->accessCount;
    }

    public function getSourceType(): ?string
    {
        return $this->sourceType;
    }

    public function getSourceRef(): ?string
    {
        return $this->sourceRef;
    }

    public function getSourceRange(): ?string
    {
        return $this->sourceRange;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getDepth(): ?int
    {
        return $this->depth;
    }

    // Setters
    public function setId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function setSummary(?string $summary): self
    {
        $this->summary = $summary;
        return $this;
    }

    public function setCategory(string $category): self
    {
        $this->category = $category;
        return $this;
    }

    public function setImportance(float $importance): self
    {
        $this->importance = max(0.0, min(1.0, $importance));
        return $this;
    }

    public function setConfidence(float $confidence): self
    {
        $this->confidence = max(0.0, min(1.0, $confidence));
        return $this;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function setLastAccessed(?\DateTimeInterface $lastAccessed): self
    {
        $this->lastAccessed = $lastAccessed;
        return $this;
    }

    public function setAccessCount(int $accessCount): self
    {
        $this->accessCount = $accessCount;
        return $this;
    }

    public function setSourceType(?string $sourceType): self
    {
        $this->sourceType = $sourceType;
        return $this;
    }

    public function setSourceRef(?string $sourceRef): self
    {
        $this->sourceRef = $sourceRef;
        return $this;
    }

    public function setSourceRange(?string $sourceRange): self
    {
        $this->sourceRange = $sourceRange;
        return $this;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function setDepth(?int $depth): self
    {
        $this->depth = $depth;
        return $this;
    }

    // Utility methods
    public function incrementAccessCount(): self
    {
        $this->accessCount++;
        $this->lastAccessed = new \DateTime();
        return $this;
    }

    public function deactivate(): self
    {
        $this->isActive = false;
        return $this;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'content' => $this->content,
            'summary' => $this->summary,
            'category' => $this->category,
            'importance' => $this->importance,
            'confidence' => $this->confidence,
            'createdAt' => $this->createdAt->format('c'),
            'lastAccessed' => $this->lastAccessed?->format('c'),
            'accessCount' => $this->accessCount,
            'sourceType' => $this->sourceType,
            'sourceRef' => $this->sourceRef,
            'sourceRange' => $this->sourceRange,
            'isActive' => $this->isActive,
            'depth' => $this->depth,
        ];
    }

    public static function fromArray(array $data): self
    {
        $node = new self(
            $data['content'],
            $data['category'] ?? self::CATEGORY_KNOWLEDGE,
            (float)($data['importance'] ?? 0.5),
            $data['summary'] ?? null
        );

        $node->setId($data['id']);
        $node->setConfidence((float)($data['confidence'] ?? 1.0));
        $node->setCreatedAt(new \DateTime($data['created_at']));
        
        if (!empty($data['last_accessed'])) {
            $node->setLastAccessed(new \DateTime($data['last_accessed']));
        }
        
        $node->setAccessCount((int)($data['access_count'] ?? 0));
        $node->setSourceType($data['source_type'] ?? null);
        $node->setSourceRef($data['source_ref'] ?? null);
        $node->setSourceRange($data['source_range'] ?? null);
        $node->setIsActive((bool)($data['is_active'] ?? true));
        $node->setDepth(isset($data['depth']) ? (int)$data['depth'] : null);

        return $node;
    }

    public static function getValidCategories(): array
    {
        return [
            self::CATEGORY_CONVERSATION,
            self::CATEGORY_THOUGHT,
            self::CATEGORY_KNOWLEDGE,
            self::CATEGORY_FACT,
            self::CATEGORY_PREFERENCE,
        ];
    }

    public static function getValidRelationTypes(): array
    {
        return [
            self::RELATION_PART_OF,
            self::RELATION_RELATES_TO,
            self::RELATION_CONTRADICTS,
            self::RELATION_REINFORCES,
        ];
    }
}
