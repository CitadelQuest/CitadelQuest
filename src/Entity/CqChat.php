<?php

namespace App\Entity;

use JsonSerializable;

class CqChat implements JsonSerializable
{
    private string $id;
    private ?string $cqContactId;
    private string $title;
    private ?string $summary;
    private bool $isStar;
    private bool $isPin;
    private bool $isMute;
    private bool $isActive;
    private \DateTimeInterface $createdAt;
    private \DateTimeInterface $updatedAt;
    
    public function __construct(
        ?string $cqContactId,
        string $title,
        ?string $summary = null,
        bool $isStar = false,
        bool $isPin = false,
        bool $isMute = false,
        bool $isActive = true,
        ?string $specificId = null
    ) {
        $this->id = $specificId ?? uuid_create();
        $this->cqContactId = $cqContactId;
        $this->title = $title;
        $this->summary = $summary;
        $this->isStar = $isStar;
        $this->isPin = $isPin;
        $this->isMute = $isMute;
        $this->isActive = $isActive;
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
    
    public function getCqContactId(): ?string
    {
        return $this->cqContactId;
    }
    
    public function setCqContactId(?string $cqContactId): self
    {
        $this->cqContactId = $cqContactId;
        $this->updateUpdatedAt();
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
    
    public function getSummary(): ?string
    {
        return $this->summary;
    }
    
    public function setSummary(?string $summary): self
    {
        $this->summary = $summary;
        $this->updateUpdatedAt();
        return $this;
    }
    
    public function isStar(): bool
    {
        return $this->isStar;
    }
    
    public function setIsStar(bool $isStar): self
    {
        $this->isStar = $isStar;
        $this->updateUpdatedAt();
        return $this;
    }
    
    public function isPin(): bool
    {
        return $this->isPin;
    }
    
    public function setIsPin(bool $isPin): self
    {
        $this->isPin = $isPin;
        $this->updateUpdatedAt();
        return $this;
    }
    
    public function isMute(): bool
    {
        return $this->isMute;
    }
    
    public function setIsMute(bool $isMute): self
    {
        $this->isMute = $isMute;
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
            'cqContactId' => $this->cqContactId,
            'title' => $this->title,
            'summary' => $this->summary,
            'isStar' => $this->isStar,
            'isPin' => $this->isPin,
            'isMute' => $this->isMute,
            'isActive' => $this->isActive,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt' => $this->updatedAt->format(\DateTimeInterface::ATOM)
        ];
    }
    
    public static function fromArray(array $data): self
    {
        $chat = new self(
            $data['cq_contact_id'] ?? null,
            $data['title'],
            $data['summary'] ?? null,
            (bool) ($data['is_star'] ?? false),
            (bool) ($data['is_pin'] ?? false),
            (bool) ($data['is_mute'] ?? false),
            (bool) ($data['is_active'] ?? true)
        );
        
        $chat->setId($data['id']);
        
        if (isset($data['created_at'])) {
            $chat->setCreatedAt(new \DateTime($data['created_at']));
        }
        
        if (isset($data['updated_at'])) {
            $chat->setUpdatedAt(new \DateTime($data['updated_at']));
        }
        
        return $chat;
    }
}
