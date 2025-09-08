<?php

namespace App\Entity;

use JsonSerializable;

class ProjectTool implements JsonSerializable
{
    private string $id;
    private string $projectId;
    private string $toolId;
    private \DateTimeInterface $createdAt;
    
    public function __construct(
        string $projectId,
        string $toolId
    ) {
        $this->id = uuid_create();
        $this->projectId = $projectId;
        $this->toolId = $toolId;
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
    
    public function getToolId(): string
    {
        return $this->toolId;
    }
    
    public function setToolId(string $toolId): self
    {
        $this->toolId = $toolId;
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
            'toolId' => $this->toolId,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM)
        ];
    }
    
    public static function fromArray(array $data): self
    {
        $projectTool = new self(
            $data['project_id'],
            $data['tool_id']
        );
        
        $projectTool->setId($data['id']);
        
        if (isset($data['created_at'])) {
            $projectTool->setCreatedAt(new \DateTime($data['created_at']));
        }
        
        return $projectTool;
    }
}
