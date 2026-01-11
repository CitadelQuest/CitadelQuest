<?php

namespace App\Entity;

use JsonSerializable;

class Spirit implements JsonSerializable
{
    private string $id;
    private string $name;
    private \DateTimeInterface $createdAt;
    private \DateTimeInterface $lastInteraction;
    
    public function __construct(string $name)
    {
        $this->id = uuid_create();
        $this->name = $name;
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

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'createdAt' => $this->createdAt->format('c'),
            'lastInteraction' => $this->lastInteraction->format('c')
        ];
    }
    
    public static function fromArray(array $data): self
    {
        $spirit = new self($data['name']);
        $spirit->setId($data['id']);
        $spirit->setCreatedAt(new \DateTime($data['created_at']));
        $spirit->setLastInteraction(new \DateTime($data['last_interaction']));

        return $spirit;
    }
}
