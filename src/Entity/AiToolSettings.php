<?php

namespace App\Entity;

use JsonSerializable;

class AiToolSettings implements JsonSerializable
{
    private string $id;
    private string $toolId;
    private string $key;
    private ?string $value;
    private string $type;
    private ?string $label;
    private ?string $description;
    private int $displayOrder;
    private \DateTimeInterface $createdAt;
    private \DateTimeInterface $updatedAt;

    public function __construct(
        string $toolId,
        string $key,
        ?string $value = null,
        string $type = 'text',
        ?string $label = null,
        ?string $description = null,
        int $displayOrder = 0
    ) {
        $this->id = uuid_create();
        $this->toolId = $toolId;
        $this->key = $key;
        $this->value = $value;
        $this->type = $type;
        $this->label = $label;
        $this->description = $description;
        $this->displayOrder = $displayOrder;
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getToolId(): string
    {
        return $this->toolId;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): self
    {
        $this->value = $value;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getDisplayOrder(): int
    {
        return $this->displayOrder;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'toolId' => $this->toolId,
            'key' => $this->key,
            'value' => $this->value,
            'type' => $this->type,
            'label' => $this->label,
            'description' => $this->description,
            'displayOrder' => $this->displayOrder,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt' => $this->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }

    public static function fromArray(array $data): self
    {
        $setting = new self(
            $data['tool_id'],
            $data['key'],
            $data['value'] ?? null,
            $data['type'] ?? 'text',
            $data['label'] ?? null,
            $data['description'] ?? null,
            (int) ($data['display_order'] ?? 0)
        );

        $setting->setId($data['id']);

        if (isset($data['created_at'])) {
            $setting->createdAt = new \DateTime($data['created_at']);
        }
        if (isset($data['updated_at'])) {
            $setting->updatedAt = new \DateTime($data['updated_at']);
        }

        return $setting;
    }
}
