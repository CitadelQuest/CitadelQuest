<?php

namespace App\Entity;

use JsonSerializable;

class SpiritMemoryRelationship implements JsonSerializable
{
    private string $id;
    private string $sourceId;
    private string $targetId;
    private string $type;
    private float $strength;
    private ?string $context;
    private \DateTimeInterface $createdAt;

    public function __construct(
        string $sourceId,
        string $targetId,
        string $type,
        float $strength = 1.0,
        ?string $context = null
    ) {
        $this->id = uuid_create();
        $this->sourceId = $sourceId;
        $this->targetId = $targetId;
        $this->type = $type;
        $this->strength = $strength;
        $this->context = $context;
        $this->createdAt = new \DateTime();
    }

    // Getters
    public function getId(): string
    {
        return $this->id;
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function getTargetId(): string
    {
        return $this->targetId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getStrength(): float
    {
        return $this->strength;
    }

    public function getContext(): ?string
    {
        return $this->context;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    // Setters
    public function setId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function setStrength(float $strength): self
    {
        $this->strength = max(0.0, min(1.0, $strength));
        return $this;
    }

    public function setContext(?string $context): self
    {
        $this->context = $context;
        return $this;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'sourceId' => $this->sourceId,
            'targetId' => $this->targetId,
            'type' => $this->type,
            'strength' => $this->strength,
            'context' => $this->context,
            'createdAt' => $this->createdAt->format('c'),
        ];
    }

    public static function fromArray(array $data): self
    {
        $relationship = new self(
            $data['source_id'],
            $data['target_id'],
            $data['type'],
            (float)($data['strength'] ?? 1.0),
            $data['context'] ?? null
        );

        $relationship->setId($data['id']);
        $relationship->setCreatedAt(new \DateTime($data['created_at']));

        return $relationship;
    }
}
