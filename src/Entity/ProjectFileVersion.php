<?php

namespace App\Entity;

use JsonSerializable;

class ProjectFileVersion implements JsonSerializable
{
    private string $id;
    private string $fileId;
    private int $version;
    private ?int $size;
    private ?string $hash;
    private \DateTimeInterface $createdAt;
    
    public function __construct(
        string $fileId,
        int $version,
        ?int $size = null,
        ?string $hash = null
    ) {
        $this->id = uuid_create();
        $this->fileId = $fileId;
        $this->version = $version;
        $this->size = $size;
        $this->hash = $hash;
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
    
    public function getFileId(): string
    {
        return $this->fileId;
    }
    
    public function setFileId(string $fileId): self
    {
        $this->fileId = $fileId;
        return $this;
    }
    
    public function getVersion(): int
    {
        return $this->version;
    }
    
    public function setVersion(int $version): self
    {
        $this->version = $version;
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
    
    public function getHash(): ?string
    {
        return $this->hash;
    }
    
    public function setHash(?string $hash): self
    {
        $this->hash = $hash;
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
            'fileId' => $this->fileId,
            'version' => $this->version,
            'size' => $this->size,
            'hash' => $this->hash,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM)
        ];
    }
    
    public static function fromArray(array $data): self
    {
        $version = new self(
            $data['file_id'],
            (int) $data['version'],
            $data['size'] ?? null,
            $data['hash'] ?? null
        );
        
        $version->setId($data['id']);
        
        if (isset($data['created_at'])) {
            $version->setCreatedAt(new \DateTime($data['created_at']));
        }
        
        return $version;
    }
}
