<?php

namespace App\Entity;

use JsonSerializable;

class CqChatGroupMember implements JsonSerializable
{
    private string $id;
    private string $cqChatId;
    private string $cqContactId;
    private string $role;
    private ?string $memberUsername;
    private ?string $memberDomain;
    private \DateTimeInterface $joinedAt;
    private \DateTimeInterface $createdAt;
    
    public function __construct(
        string $cqChatId,
        string $cqContactId,
        string $role = 'member',
        ?string $specificId = null
    ) {
        $this->id = $specificId ?? uuid_create();
        $this->cqChatId = $cqChatId;
        $this->cqContactId = $cqContactId;
        $this->role = $role;
        $this->memberUsername = null;
        $this->memberDomain = null;
        $this->joinedAt = new \DateTime();
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
    
    public function getCqChatId(): string
    {
        return $this->cqChatId;
    }
    
    public function setCqChatId(string $cqChatId): self
    {
        $this->cqChatId = $cqChatId;
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
    
    public function getRole(): string
    {
        return $this->role;
    }
    
    public function setRole(string $role): self
    {
        $this->role = $role;
        return $this;
    }
    
    public function isHost(): bool
    {
        return $this->role === 'host';
    }
    
    public function getMemberUsername(): ?string
    {
        return $this->memberUsername;
    }
    
    public function setMemberUsername(?string $memberUsername): self
    {
        $this->memberUsername = $memberUsername;
        return $this;
    }
    
    public function getMemberDomain(): ?string
    {
        return $this->memberDomain;
    }
    
    public function setMemberDomain(?string $memberDomain): self
    {
        $this->memberDomain = $memberDomain;
        return $this;
    }
    
    public function getJoinedAt(): \DateTimeInterface
    {
        return $this->joinedAt;
    }
    
    public function setJoinedAt(\DateTimeInterface $joinedAt): self
    {
        $this->joinedAt = $joinedAt;
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
        $data = [
            'id' => $this->id,
            'cqChatId' => $this->cqChatId,
            'cqContactId' => $this->cqContactId,
            'role' => $this->role,
            'joinedAt' => $this->joinedAt->format(\DateTimeInterface::ATOM),
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM)
        ];
        
        if ($this->memberUsername) {
            $data['memberUsername'] = $this->memberUsername;
            $data['memberDomain'] = $this->memberDomain;
        }
        
        return $data;
    }
    
    public static function fromArray(array $data): self
    {
        $member = new self(
            $data['cq_chat_id'],
            $data['cq_contact_id'],
            $data['role'] ?? 'member'
        );
        
        $member->setId($data['id']);
        
        if (isset($data['member_username'])) {
            $member->setMemberUsername($data['member_username']);
        }
        if (isset($data['member_domain'])) {
            $member->setMemberDomain($data['member_domain']);
        }
        
        if (isset($data['joined_at'])) {
            $member->setJoinedAt(new \DateTime($data['joined_at']));
        }
        
        if (isset($data['created_at'])) {
            $member->setCreatedAt(new \DateTime($data['created_at']));
        }
        
        return $member;
    }
}
