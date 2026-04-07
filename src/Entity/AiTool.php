<?php

namespace App\Entity;

use JsonSerializable;

class AiTool implements JsonSerializable
{
    private string $id;
    private string $name;
    private string $description;
    private string $parameters;
    private bool $isActive;
    private string $category;
    private int $displayOrder;
    private \DateTimeInterface $createdAt;
    private \DateTimeInterface $updatedAt;
    
    public function __construct(string $name, string $description, string $parameters, bool $isActive = true, string $category = 'general', int $displayOrder = 0)
    {
        $this->id = uuid_create();
        $this->name = $name;
        $this->description = $description;
        $this->parameters = $parameters;
        $this->isActive = $isActive;
        $this->category = $category;
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
    
    public function getName(): string
    {
        return $this->name;
    }
    
    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }
    
    public function getDescription(): string
    {
        return $this->description;
    }
    
    public function setDescription(string $description): self
    {
        $this->description = $description;
        return $this;
    }
    
    public function getParameters(): string
    {
        return $this->parameters;
    }
    
    public function getParametersAsArray(): array
    {
        return json_decode($this->parameters, true) ?? [];
    }
    
    public function setParameters(string $parameters): self
    {
        $this->parameters = $parameters;
        return $this;
    }
    
    public function isActive(): bool
    {
        return $this->isActive;
    }
    
    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }
    
    public function getCategory(): string
    {
        return $this->category;
    }
    
    public function setCategory(string $category): self
    {
        $this->category = $category;
        return $this;
    }
    
    public function getDisplayOrder(): int
    {
        return $this->displayOrder;
    }
    
    public function setDisplayOrder(int $displayOrder): self
    {
        $this->displayOrder = $displayOrder;
        return $this;
    }
    
    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
    
    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }
    
    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }
    
    public function setUpdatedAt(\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
    
    public function updateUpdatedAt(): self
    {
        $this->updatedAt = new \DateTime();
        return $this;
    }
    
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'parameters' => $this->getParametersAsArray(),
            'isActive' => $this->isActive,
            'category' => $this->category,
            'displayOrder' => $this->displayOrder,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt' => $this->updatedAt->format(\DateTimeInterface::ATOM)
        ];
    }
    
    public static function fromArray(array $data): self
    {
        $tool = new self(
            $data['name'],
            $data['description'],
            $data['parameters'],
            (bool) ($data['is_active'] ?? true),
            $data['category'] ?? 'general',
            (int) ($data['display_order'] ?? 0)
        );
        
        $tool->setId($data['id']);
        
        if (isset($data['created_at'])) {
            $tool->setCreatedAt(new \DateTime($data['created_at']));
        }
        
        if (isset($data['updated_at'])) {
            $tool->setUpdatedAt(new \DateTime($data['updated_at']));
        }
        
        return $tool;
    }
}
