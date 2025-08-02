<?php

namespace App\Entity;

use JsonSerializable;

class ProjectFile implements JsonSerializable
{
    private string $id;
    private string $projectId;
    private string $path;
    private string $name;
    private string $type;
    private ?string $mimeType;
    private ?int $size;
    private bool $isDirectory;
    private \DateTimeInterface $createdAt;
    private \DateTimeInterface $updatedAt;
    
    public function __construct(
        string $projectId,
        string $path,
        string $name,
        string $type,
        bool $isDirectory = false,
        ?string $mimeType = null,
        ?int $size = null
    ) {
        $this->id = uuid_create();
        $this->projectId = $projectId;
        $this->path = $this->normalizePath($path);
        $this->name = $name;
        $this->type = $type;
        $this->isDirectory = $isDirectory;
        $this->mimeType = $mimeType;
        $this->size = $size;
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
    
    public function getProjectId(): string
    {
        return $this->projectId;
    }
    
    public function setProjectId(string $projectId): self
    {
        $this->projectId = $projectId;
        return $this;
    }
    
    public function getPath(): string
    {
        return $this->path;
    }
    
    public function setPath(string $path): self
    {
        $this->path = $this->normalizePath($path);
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
    
    public function getFullPath(): string
    {
        if ($this->path === '/' || $this->path === '') {
            return '/' . $this->name;
        }
        return $this->path . '/' . $this->name;
    }
    
    public function getType(): string
    {
        return $this->type;
    }
    
    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }
    
    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }
    
    public function setMimeType(?string $mimeType): self
    {
        $this->mimeType = $mimeType;
        return $this;
    }
    
    public function getSize(): ?int
    {
        return $this->size;
    }
    
    public function setSize(?int $size): self
    {
        $this->size = $size;
        return $this;
    }
    
    public function isDirectory(): bool
    {
        return $this->isDirectory;
    }
    
    public function setIsDirectory(bool $isDirectory): self
    {
        $this->isDirectory = $isDirectory;
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
    
    /**
     * Normalize a file path to ensure consistent format
     */
    private function normalizePath(string $path): string
    {
        // Ensure path starts with /
        if (empty($path) || $path === '.') {
            return '/';
        }
        
        // Ensure path starts with /
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        
        // Remove trailing slash if not root
        if ($path !== '/' && substr($path, -1) === '/') {
            $path = substr($path, 0, -1);
        }
        
        return $path;
    }
    
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'projectId' => $this->projectId,
            'path' => $this->path,
            'name' => $this->name,
            'fullPath' => $this->getFullPath(),
            'type' => $this->type,
            'mimeType' => $this->mimeType,
            'size' => $this->size,
            'isDirectory' => $this->isDirectory,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt' => $this->updatedAt->format(\DateTimeInterface::ATOM)
        ];
    }
    
    public static function fromArray(array $data): self
    {
        $file = new self(
            $data['project_id'],
            $data['path'],
            $data['name'],
            $data['type'],
            (bool) ($data['is_directory'] ?? false),
            $data['mime_type'] ?? null,
            $data['size'] ?? null
        );
        
        $file->setId($data['id']);
        
        if (isset($data['created_at'])) {
            $file->setCreatedAt(new \DateTime($data['created_at']));
        }
        
        if (isset($data['updated_at'])) {
            $file->setUpdatedAt(new \DateTime($data['updated_at']));
        }
        
        return $file;
    }
}
