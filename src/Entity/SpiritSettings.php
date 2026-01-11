<?php

namespace App\Entity;

use JsonSerializable;

class SpiritSettings implements JsonSerializable
{
    private string $id;
    private string $spiritId;
    private string $key;
    private ?string $value = null;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $spiritId, string $key, ?string $value = null)
    {
        $this->id = uuid_create();
        $this->spiritId = $spiritId;
        $this->key = $key;
        $this->value = $value;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public static function fromArray(array $data): self
    {
        $settings = new self($data['spirit_id'], $data['key'], $data['value']);
        $settings->id = $data['id'];
        $settings->createdAt = new \DateTimeImmutable($data['created_at']);
        $settings->updatedAt = new \DateTimeImmutable($data['updated_at'] ?? $data['created_at']);

        return $settings;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSpiritId(): string
    {
        return $this->spiritId;
    }

    public function setSpiritId(string $spiritId): self
    {
        $this->spiritId = $spiritId;
        return $this;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): self
    {
        $this->key = $key;
        return $this;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): self
    {
        $this->value = $value;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'spiritId' => $this->spiritId,
            'key' => $this->key,
            'value' => $this->value,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt' => $this->updatedAt->format(\DateTimeInterface::ATOM)
        ];
    }
}
