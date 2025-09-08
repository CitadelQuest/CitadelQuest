<?php

namespace App\Entity;

use JsonSerializable;

class ProjectSpirit implements JsonSerializable
{
    private string $id;
    private string $projectId;
    private string $spiritId;
    private \DateTimeInterface $createdAt;
    
    public function __construct(
        string $projectId,
        string $spiritId
    ) {
        $this->id = uuid_create();
        $this->projectId = $projectId;
        $this->spiritId = $spiritId;
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
    
    public function getProjectId(): string
    {
        return $this->projectId;
    }
    
    public function setProjectId(string $projectId): self
    {
        $this->projectId = $projectId;
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
            'projectId' => $this->projectId,
            'spiritId' => $this->spiritId,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM)
        ];
    }
    
    public static function fromArray(array $data): self
    {
        $projectSpirit = new self(
            $data['project_id'],
            $data['spirit_id']
        );
        
        $projectSpirit->setId($data['id']);
        
        if (isset($data['created_at'])) {
            $projectSpirit->setCreatedAt(new \DateTime($data['created_at']));
        }
        
        return $projectSpirit;
    }
}
