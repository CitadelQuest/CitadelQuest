<?php

namespace App\Entity;

use JsonSerializable;

class ProjectCqContact implements JsonSerializable
{
    private string $id;
    private string $projectId;
    private string $cqContactId;
    private \DateTimeInterface $createdAt;
    
    public function __construct(
        string $projectId,
        string $cqContactId
    ) {
        $this->id = uuid_create();
        $this->projectId = $projectId;
        $this->cqContactId = $cqContactId;
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
    
    public function getCqContactId(): string
    {
        return $this->cqContactId;
    }
    
    public function setCqContactId(string $cqContactId): self
    {
        $this->cqContactId = $cqContactId;
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
            'cqContactId' => $this->cqContactId,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM)
        ];
    }
    
    public static function fromArray(array $data): self
    {
        $projectCqContact = new self(
            $data['project_id'],
            $data['cq_contact_id']
        );
        
        $projectCqContact->setId($data['id']);
        
        if (isset($data['created_at'])) {
            $projectCqContact->setCreatedAt(new \DateTime($data['created_at']));
        }
        
        return $projectCqContact;
    }
}
