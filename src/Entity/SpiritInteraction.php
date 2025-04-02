<?php

namespace App\Entity;

use JsonSerializable;

class SpiritInteraction implements JsonSerializable
{
    private string $id;
    private string $spiritId;
    private string $interactionType;
    private ?string $context;
    private int $experienceGained;
    private \DateTimeInterface $createdAt;
    
    public function __construct(string $spiritId, string $interactionType, int $experienceGained = 0, ?string $context = null)
    {
        $this->id = uuid_create();
        $this->spiritId = $spiritId;
        $this->interactionType = $interactionType;
        $this->context = $context;
        $this->experienceGained = $experienceGained;
        $this->createdAt = new \DateTime();
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
    
    public function getSpiritId(): string
    {
        return $this->spiritId;
    }
    
    public function setSpiritId(string $spiritId): self
    {
        $this->spiritId = $spiritId;
        return $this;
    }
    
    public function getInteractionType(): string
    {
        return $this->interactionType;
    }
    
    public function setInteractionType(string $interactionType): self
    {
        $this->interactionType = $interactionType;
        return $this;
    }
    
    public function getContext(): ?string
    {
        return $this->context;
    }
    
    public function setContext(?string $context): self
    {
        $this->context = $context;
        return $this;
    }
    
    public function getExperienceGained(): int
    {
        return $this->experienceGained;
    }
    
    public function setExperienceGained(int $experienceGained): self
    {
        $this->experienceGained = $experienceGained;
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
    
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'spiritId' => $this->spiritId,
            'interactionType' => $this->interactionType,
            'context' => $this->context,
            'experienceGained' => $this->experienceGained,
            'createdAt' => $this->createdAt->format('c')
        ];
    }
    
    public static function fromArray(array $data): self
    {
        $interaction = new self(
            $data['spirit_id'], 
            $data['interaction_type'], 
            $data['experience_gained'] ?? 0, 
            $data['context'] ?? null
        );
        
        $interaction->setId($data['id']);
        $interaction->setCreatedAt(new \DateTime($data['created_at']));
        
        return $interaction;
    }
}
