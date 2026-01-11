<?php

namespace App\Entity;

use JsonSerializable;

class DiaryEntry implements JsonSerializable
{
    private ?string $id = null;
    private string $title;
    private string $content;
    private ?string $contentFormatted = null;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;
    private bool $isEncrypted = false;
    private bool $isFavorite = false;
    private ?array $tags = null;
    private ?string $mood = null;

    public function __construct()
    {
        $this->id = uuid_create();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?string
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
        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function getContentFormatted(): ?string
    {
        return $this->contentFormatted;
    }

    public function setContentFormatted(?string $contentFormatted): self
    {
        $this->contentFormatted = $contentFormatted;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
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

    public function isEncrypted(): bool
    {
        return $this->isEncrypted;
    }

    public function setIsEncrypted(bool $isEncrypted): self
    {
        $this->isEncrypted = $isEncrypted;
        return $this;
    }

    public function isFavorite(): bool
    {
        return $this->isFavorite;
    }

    public function setIsFavorite(bool $isFavorite): self
    {
        $this->isFavorite = $isFavorite;
        return $this;
    }

    public function getTags(): ?array
    {
        if ($this->tags === null) {
            return null;
        }
        return is_array($this->tags) ? $this->tags : explode(',', $this->tags);
    }

    public function setTags(array|string|null $tags): self
    {
        if (is_array($tags)) {
            $this->tags = array_filter($tags); // Remove empty tags
        } else if (is_string($tags)) {
            $this->tags = array_filter(explode(',', $tags));
        } else {
            $this->tags = null;
        }
        return $this;
    }

    public function getMood(): ?string
    {
        return $this->mood;
    }

    public function setMood(?string $mood): self
    {
        $this->mood = $mood;
        return $this;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'content' => $this->content,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt' => $this->updatedAt->format(\DateTimeInterface::ATOM),
            'isEncrypted' => $this->isEncrypted,
            'isFavorite' => $this->isFavorite,
            'tags' => $this->tags,
            'mood' => $this->mood,
            'contentFormatted' => $this->contentFormatted,
        ];
    }
}
