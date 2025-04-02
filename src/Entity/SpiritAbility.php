<?php

namespace App\Entity;

use JsonSerializable;

class SpiritAbility implements JsonSerializable
{
    private string $id;
    private string $spiritId;
    private string $abilityType;
    private string $abilityName;
    private bool $unlocked;
    private ?\DateTimeInterface $unlockedAt;
    
    public function __construct(string $spiritId, string $abilityType, string $abilityName)
    {
        $this->id = uuid_create();
        $this->spiritId = $spiritId;
        $this->abilityType = $abilityType;
        $this->abilityName = $abilityName;
        $this->unlocked = false;
        $this->unlockedAt = null;
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
    
    public function getAbilityType(): string
    {
        return $this->abilityType;
    }
    
    public function setAbilityType(string $abilityType): self
    {
        $this->abilityType = $abilityType;
        return $this;
    }
    
    public function getAbilityName(): string
    {
        return $this->abilityName;
    }
    
    public function setAbilityName(string $abilityName): self
    {
        $this->abilityName = $abilityName;
        return $this;
    }
    
    public function isUnlocked(): bool
    {
        return $this->unlocked;
    }
    
    public function setUnlocked(bool $unlocked): self
    {
        $this->unlocked = $unlocked;
        
        if ($unlocked && $this->unlockedAt === null) {
            $this->unlockedAt = new \DateTime();
        }
        
        return $this;
    }
    
    public function unlock(): self
    {
        return $this->setUnlocked(true);
    }
    
    public function getUnlockedAt(): ?\DateTimeInterface
    {
        return $this->unlockedAt;
    }
    
    public function setUnlockedAt(?\DateTimeInterface $unlockedAt): self
    {
        $this->unlockedAt = $unlockedAt;
        return $this;
    }
    
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'spiritId' => $this->spiritId,
            'abilityType' => $this->abilityType,
            'abilityName' => $this->abilityName,
            'unlocked' => $this->unlocked,
            'unlockedAt' => $this->unlockedAt ? $this->unlockedAt->format('c') : null
        ];
    }
    
    public static function fromArray(array $data): self
    {
        $ability = new self($data['spirit_id'], $data['ability_type'], $data['ability_name']);
        $ability->setId($data['id']);
        $ability->setUnlocked((bool)$data['unlocked']);
        
        if (!empty($data['unlocked_at'])) {
            $ability->setUnlockedAt(new \DateTime($data['unlocked_at']));
        }
        
        return $ability;
    }
}
