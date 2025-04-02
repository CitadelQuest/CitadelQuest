<?php

namespace App\Entity;

use JsonSerializable;

class Spirit implements JsonSerializable
{
    private string $id;
    private string $name;
    private int $level;
    private int $experience;
    private string $visualState;
    private int $consciousnessLevel;
    private \DateTimeInterface $createdAt;
    private \DateTimeInterface $lastInteraction;
    private array $abilities = [];
    
    public function __construct(string $name)
    {
        $this->id = uuid_create();
        $this->name = $name;
        $this->level = 1;
        $this->experience = 0;
        $this->visualState = 'initial';
        $this->consciousnessLevel = 1;
        $this->createdAt = new \DateTime();
        $this->lastInteraction = new \DateTime();
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
    
    public function getLevel(): int
    {
        return $this->level;
    }
    
    public function setLevel(int $level): self
    {
        $this->level = $level;
        return $this;
    }
    
    public function getExperience(): int
    {
        return $this->experience;
    }
    
    public function setExperience(int $experience): self
    {
        $this->experience = $experience;
        return $this;
    }
    
    public function addExperience(int $points): self
    {
        $this->experience += $points;
        
        // Check if level up is needed
        $nextLevelThreshold = $this->calculateNextLevelThreshold();
        if ($this->experience >= $nextLevelThreshold) {
            $this->levelUp();
        }
        
        return $this;
    }
    
    private function calculateNextLevelThreshold(): int
    {
        // Simple exponential level progression
        return 100 * pow(1.5, $this->level - 1);
    }
    
    private function levelUp(): void
    {
        $this->level++;
        
        // Potentially evolve visual state based on level
        if ($this->level >= 10 && $this->visualState === 'initial') {
            $this->visualState = 'evolved_1';
        } elseif ($this->level >= 25 && $this->visualState === 'evolved_1') {
            $this->visualState = 'evolved_2';
        } elseif ($this->level >= 50 && $this->visualState === 'evolved_2') {
            $this->visualState = 'evolved_3';
        }
    }
    
    public function getVisualState(): string
    {
        return $this->visualState;
    }
    
    public function setVisualState(string $visualState): self
    {
        $this->visualState = $visualState;
        return $this;
    }
    
    public function getConsciousnessLevel(): int
    {
        return $this->consciousnessLevel;
    }
    
    public function setConsciousnessLevel(int $consciousnessLevel): self
    {
        $this->consciousnessLevel = $consciousnessLevel;
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
    
    public function getLastInteraction(): \DateTimeInterface
    {
        return $this->lastInteraction;
    }
    
    public function setLastInteraction(\DateTimeInterface $lastInteraction): self
    {
        $this->lastInteraction = $lastInteraction;
        return $this;
    }
    
    public function updateLastInteraction(): self
    {
        $this->lastInteraction = new \DateTime();
        return $this;
    }
    
    public function getAbilities(): array
    {
        return $this->abilities;
    }
    
    public function setAbilities(array $abilities): self
    {
        $this->abilities = $abilities;
        return $this;
    }
    
    public function addAbility(SpiritAbility $ability): self
    {
        $this->abilities[] = $ability;
        return $this;
    }
    
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'level' => $this->level,
            'experience' => $this->experience,
            'visualState' => $this->visualState,
            'consciousnessLevel' => $this->consciousnessLevel,
            'createdAt' => $this->createdAt->format('c'),
            'lastInteraction' => $this->lastInteraction->format('c'),
            'abilities' => array_map(function($ability) {
                return $ability->jsonSerialize();
            }, $this->abilities)
        ];
    }
    
    public static function fromArray(array $data): self
    {
        $spirit = new self($data['name']);
        $spirit->setId($data['id']);
        $spirit->setLevel($data['level']);
        $spirit->setExperience($data['experience']);
        $spirit->setVisualState($data['visual_state']);
        $spirit->setConsciousnessLevel($data['consciousness_level']);
        $spirit->setCreatedAt(new \DateTime($data['created_at']));
        $spirit->setLastInteraction(new \DateTime($data['last_interaction']));
        
        return $spirit;
    }
}
