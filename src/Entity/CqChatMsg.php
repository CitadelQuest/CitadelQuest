<?php

namespace App\Entity;

use JsonSerializable;

class CqChatMsg implements JsonSerializable
{
    private string $id;
    private string $cqChatId;
    private ?string $cqContactId;
    private ?string $content;
    private ?string $attachments;
    private ?string $status;
    private \DateTimeInterface $createdAt;
    private \DateTimeInterface $updatedAt;
    
    public function __construct(
        string $cqChatId,
        ?string $cqContactId = null,
        ?string $content = null,
        ?string $attachments = null,
        ?string $status = null,
        ?string $id = null
    ) {
        $this->id = $id ?? uuid_create();
        $this->cqChatId = $cqChatId;
        $this->cqContactId = $cqContactId;
        $this->content = $content;
        $this->attachments = $attachments;
        $this->status = $status;
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
    
    public function getCqChatId(): string
    {
        return $this->cqChatId;
    }
    
    public function setCqChatId(string $cqChatId): self
    {
        $this->cqChatId = $cqChatId;
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
    
    public function getContent(): ?string
    {
        return $this->content;
    }
    
    public function setContent(?string $content): self
    {
        $this->content = $content;
        $this->updateUpdatedAt();
        return $this;
    }
    
    public function getAttachments(): ?string
    {
        return $this->attachments;
    }
    
    public function setAttachments(?string $attachments): self
    {
        $this->attachments = $attachments;
        $this->updateUpdatedAt();
        return $this;
    }
    
    public function getStatus(): ?string
    {
        return $this->status;
    }
    
    public function setStatus(?string $status): self
    {
        $this->status = $status;
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
            'cqChatId' => $this->cqChatId,
            'cqContactId' => $this->cqContactId,
            'content' => $this->content,
            'attachments' => $this->attachments,
            'status' => $this->status,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt' => $this->updatedAt->format(\DateTimeInterface::ATOM)
        ];
    }
    
    public static function fromArray(array $data): self
    {
        $message = new self(
            $data['cq_chat_id'],
            $data['cq_contact_id'] ?? null,
            $data['content'] ?? null,
            $data['attachments'] ?? null,
            $data['status'] ?? null,
            $data['id'] ?? null
        );
        
        if (isset($data['created_at'])) {
            $message->setCreatedAt(new \DateTime($data['created_at']));
        }
        
        if (isset($data['updated_at'])) {
            $message->setUpdatedAt(new \DateTime($data['updated_at']));
        }
        
        return $message;
    }
}
