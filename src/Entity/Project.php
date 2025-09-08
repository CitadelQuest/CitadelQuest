<?php

namespace App\Entity;

use JsonSerializable;

class Project implements JsonSerializable
{
    private string $id;
    private string $title;
    private string $slug;
    private ?string $description;
    private bool $isPublic;
    private bool $isActive;
    private ?string $srcUrl;
    private \DateTimeInterface $createdAt;
    private \DateTimeInterface $updatedAt;
    
    public function __construct(
        string $title,
        string $slug,
        ?string $description = null,
        bool $isPublic = false,
        bool $isActive = true,
        ?string $srcUrl = null
    ) {
        $this->id = uuid_create();
        $this->title = $title;
        $this->slug = $slug;
        $this->description = $description;
        $this->isPublic = $isPublic;
        $this->isActive = $isActive;
        $this->srcUrl = $srcUrl;
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
    
    public function getTitle(): string
    {
        return $this->title;
    }
    
    public function setTitle(string $title): self
    {
        $this->title = $title;
        $this->updateUpdatedAt();
        return $this;
    }
    
    public function getSlug(): string
    {
        return $this->slug;
    }
    
    public function setSlug(string $slug): self
    {
        $this->slug = $slug;
        $this->updateUpdatedAt();
        return $this;
    }
    
    public function getDescription(): ?string
    {
        return $this->description;
    }
    
    public function setDescription(?string $description): self
    {
        $this->description = $description;
        $this->updateUpdatedAt();
        return $this;
    }
    
    public function isPublic(): bool
    {
        return $this->isPublic;
    }
    
    public function setIsPublic(bool $isPublic): self
    {
        $this->isPublic = $isPublic;
        $this->updateUpdatedAt();
        return $this;
    }
    
    public function isActive(): bool
    {
        return $this->isActive;
    }
    
    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        $this->updateUpdatedAt();
        return $this;
    }
    
    public function getSrcUrl(): ?string
    {
        return $this->srcUrl;
    }
    
    public function setSrcUrl(?string $srcUrl): self
    {
        $this->srcUrl = $srcUrl;
        $this->updateUpdatedAt();
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
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'isPublic' => $this->isPublic,
            'isActive' => $this->isActive,
            'srcUrl' => $this->srcUrl,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt' => $this->updatedAt->format(\DateTimeInterface::ATOM)
        ];
    }
    
    public static function fromArray(array $data): self
    {
        $project = new self(
            $data['title'],
            $data['slug'],
            $data['description'] ?? null,
            (bool) ($data['is_public'] ?? false),
            (bool) ($data['is_active'] ?? true),
            $data['src_url'] ?? null
        );
        
        if (isset($data['id'])) {
            $project->setId($data['id']);
        }
        
        if (isset($data['created_at'])) {
            $project->setCreatedAt(new \DateTime($data['created_at']));
        }
        
        if (isset($data['updated_at'])) {
            $project->setUpdatedAt(new \DateTime($data['updated_at']));
        }
        
        return $project;
    }
}
