<?php

namespace App\Entity;

use JsonSerializable;

class SpiritMemoryTag implements JsonSerializable
{
    private string $id;
    private string $memoryId;
    private string $tag;
    private \DateTimeInterface $createdAt;

    public function __construct(string $memoryId, string $tag)
    {
        $this->id = uuid_create();
        $this->memoryId = $memoryId;
        $this->tag = strtolower(trim($tag));
        $this->createdAt = new \DateTime();
    }

    // Getters
    public function getId(): string
    {
        return $this->id;
    }

    public function getMemoryId(): string
    {
        return $this->memoryId;
    }

    public function getTag(): string
    {
        return $this->tag;
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

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'memoryId' => $this->memoryId,
            'tag' => $this->tag,
            'createdAt' => $this->createdAt->format('c'),
        ];
    }

    public static function fromArray(array $data): self
    {
        $tag = new self($data['memory_id'], $data['tag']);
        $tag->setId($data['id']);
        $tag->setCreatedAt(new \DateTime($data['created_at']));

        return $tag;
    }
}
