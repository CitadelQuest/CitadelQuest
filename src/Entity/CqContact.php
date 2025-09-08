<?php

namespace App\Entity;

use JsonSerializable;

class CqContact implements JsonSerializable
{
    private string $id;
    private string $cqContactUrl;
    private string $cqContactDomain;
    private string $cqContactUsername;
    private ?string $cqContactId;
    private ?string $cqContactApiKey;
    private ?string $friendRequestStatus;
    private ?string $description;
    private ?string $profilePhotoProjectFileId;
    private bool $isActive;
    private \DateTimeInterface $createdAt;
    private \DateTimeInterface $updatedAt;
    
    public function __construct(
        string $cqContactUrl,
        string $cqContactDomain,
        string $cqContactUsername,
        ?string $cqContactId = null,
        ?string $cqContactApiKey = null,
        ?string $friendRequestStatus = null,
        ?string $description = null,
        ?string $profilePhotoProjectFileId = null,
        bool $isActive = true
    ) {
        $this->id = uuid_create();
        $this->cqContactUrl = $cqContactUrl;
        $this->cqContactDomain = $cqContactDomain;
        $this->cqContactUsername = $cqContactUsername;
        $this->cqContactId = $cqContactId;
        $this->cqContactApiKey = $cqContactApiKey;
        $this->friendRequestStatus = $friendRequestStatus;
        $this->description = $description;
        $this->profilePhotoProjectFileId = $profilePhotoProjectFileId;
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
    
    public function getCqContactUrl(): string
    {
        return $this->cqContactUrl;
    }
    
    public function setCqContactUrl(string $cqContactUrl): self
    {
        $this->cqContactUrl = $cqContactUrl;
        $this->updateUpdatedAt();
        return $this;
    }
    
    public function getCqContactDomain(): string
    {
        return $this->cqContactDomain;
    }
    
    public function setCqContactDomain(string $cqContactDomain): self
    {
        $this->cqContactDomain = $cqContactDomain;
        $this->updateUpdatedAt();
        return $this;
    }
    
    public function getCqContactUsername(): string
    {
        return $this->cqContactUsername;
    }
    
    public function setCqContactUsername(string $cqContactUsername): self
    {
        $this->cqContactUsername = $cqContactUsername;
        $this->updateUpdatedAt();
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
    
    public function getCqContactApiKey(): ?string
    {
        return $this->cqContactApiKey;
    }
    
    public function setCqContactApiKey(?string $cqContactApiKey): self
    {
        $this->cqContactApiKey = $cqContactApiKey;
        $this->updateUpdatedAt();
        return $this;
    }

    public function getFriendRequestStatus(): ?string
    {
        return $this->friendRequestStatus;
    }
    
    public function setFriendRequestStatus(?string $friendRequestStatus): self
    {
        $this->friendRequestStatus = $friendRequestStatus;
        $this->updateUpdatedAt();
        return $this;
    }
    
    public function getDescription(): ?string
    {
        return $this->description;
    }
    
    public function setDescription(?string $description): self
    {
        $this->description = $description;
        $this->updateUpdatedAt();
        return $this;
    }
    
    public function getProfilePhotoProjectFileId(): ?string
    {
        return $this->profilePhotoProjectFileId;
    }
    
    public function setProfilePhotoProjectFileId(?string $profilePhotoProjectFileId): self
    {
        $this->profilePhotoProjectFileId = $profilePhotoProjectFileId;
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
            'cqContactUrl' => $this->cqContactUrl,
            'cqContactDomain' => $this->cqContactDomain,
            'cqContactUsername' => $this->cqContactUsername,
            'cqContactId' => $this->cqContactId,
            'cqContactApiKey' => $this->cqContactApiKey,
            'friendRequestStatus' => $this->friendRequestStatus,
            'description' => $this->description,
            'profilePhotoProjectFileId' => $this->profilePhotoProjectFileId,
            'isActive' => $this->isActive,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt' => $this->updatedAt->format(\DateTimeInterface::ATOM)
        ];
    }
    
    public static function fromArray(array $data): self
    {
        $contact = new self(
            $data['cq_contact_url'],
            $data['cq_contact_domain'],
            $data['cq_contact_username'],
            $data['cq_contact_id'],
            $data['cq_contact_api_key'],
            $data['friend_request_status'] ?? null,
            $data['description'] ?? null,
            $data['profile_photo_project_file_id'] ?? null,
            (bool) ($data['is_active'] ?? true)
        );
        
        $contact->setId($data['id']);
        
        if (isset($data['created_at'])) {
            $contact->setCreatedAt(new \DateTime($data['created_at']));
        }
        
        if (isset($data['updated_at'])) {
            $contact->setUpdatedAt(new \DateTime($data['updated_at']));
        }
        
        return $contact;
    }
}
