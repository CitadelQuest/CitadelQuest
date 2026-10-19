<?php

namespace App\Entity;

use JsonSerializable;

/**
 * A Project (workspace). `id` is the short, human-readable, immutable slug
 * (e.g. 'general', 'marketing') and is used everywhere as `projectId`.
 */
class Project implements JsonSerializable
{
    private string $id;
    private string $title;
    private ?string $description;
    private bool $isPublic;
    private bool $isArchived;
    private bool $isSystem;
    private ?string $srcUrl;
    private \DateTimeInterface $createdAt;
    private \DateTimeInterface $updatedAt;

    public function __construct(
        string $id,
        string $title,
        ?string $description = null,
        bool $isPublic = false,
        bool $isArchived = false,
        bool $isSystem = false,
        ?string $srcUrl = null
    ) {
        $this->id = $id;
        $this->title = $title;
        $this->description = $description;
        $this->isPublic = $isPublic;
        $this->isArchived = $isArchived;
        $this->isSystem = $isSystem;
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

    /**
     * Whether the project is archived (hidden from lists). NOT the same as the
     * currently-selected workspace — that is settings.active_project_id.
     */
    public function isArchived(): bool
    {
        return $this->isArchived;
    }

    public function setIsArchived(bool $isArchived): self
    {
        $this->isArchived = $isArchived;
        $this->updateUpdatedAt();
        return $this;
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    public function setIsSystem(bool $isSystem): self
    {
        $this->isSystem = $isSystem;
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
            'description' => $this->description,
            'isPublic' => $this->isPublic,
            'isArchived' => $this->isArchived,
            'isSystem' => $this->isSystem,
            'srcUrl' => $this->srcUrl,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt' => $this->updatedAt->format(\DateTimeInterface::ATOM)
        ];
    }

    public static function fromArray(array $data): self
    {
        $project = new self(
            $data['id'],
            $data['title'],
            $data['description'] ?? null,
            (bool) ($data['is_public'] ?? false),
            (bool) ($data['is_archived'] ?? false),
            (bool) ($data['is_system'] ?? false),
            $data['src_url'] ?? null
        );

        if (isset($data['created_at'])) {
            $project->setCreatedAt(new \DateTime($data['created_at']));
        }

        if (isset($data['updated_at'])) {
            $project->setUpdatedAt(new \DateTime($data['updated_at']));
        }

        return $project;
    }
}
