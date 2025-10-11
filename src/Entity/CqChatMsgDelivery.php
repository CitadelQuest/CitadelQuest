<?php

namespace App\Entity;

use JsonSerializable;

class CqChatMsgDelivery implements JsonSerializable
{
    private string $id;
    private string $cqChatMsgId;
    private string $cqContactId;
    private string $status;
    private ?\DateTimeInterface $deliveredAt;
    private ?\DateTimeInterface $seenAt;
    private \DateTimeInterface $createdAt;
    private \DateTimeInterface $updatedAt;
    
    public function __construct(
        string $cqChatMsgId,
        string $cqContactId,
        string $status = 'SENT',
        ?string $specificId = null
    ) {
        $this->id = $specificId ?? uuid_create();
        $this->cqChatMsgId = $cqChatMsgId;
        $this->cqContactId = $cqContactId;
        $this->status = $status;
        $this->deliveredAt = null;
        $this->seenAt = null;
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
    
    public function getCqChatMsgId(): string
    {
        return $this->cqChatMsgId;
    }
    
    public function setCqChatMsgId(string $cqChatMsgId): self
    {
        $this->cqChatMsgId = $cqChatMsgId;
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
    
    public function getStatus(): string
    {
        return $this->status;
    }
    
    public function setStatus(string $status): self
    {
        $this->status = $status;
        $this->updateUpdatedAt();
        
        // Auto-set timestamps based on status
        if ($status === 'DELIVERED' && $this->deliveredAt === null) {
            $this->deliveredAt = new \DateTime();
        }
        if ($status === 'SEEN' && $this->seenAt === null) {
            $this->seenAt = new \DateTime();
            // If seen, also mark as delivered
            if ($this->deliveredAt === null) {
                $this->deliveredAt = new \DateTime();
            }
        }
        
        return $this;
    }
    
    public function getDeliveredAt(): ?\DateTimeInterface
    {
        return $this->deliveredAt;
    }
    
    public function setDeliveredAt(?\DateTimeInterface $deliveredAt): self
    {
        $this->deliveredAt = $deliveredAt;
        $this->updateUpdatedAt();
        return $this;
    }
    
    public function getSeenAt(): ?\DateTimeInterface
    {
        return $this->seenAt;
    }
    
    public function setSeenAt(?\DateTimeInterface $seenAt): self
    {
        $this->seenAt = $seenAt;
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
            'cqChatMsgId' => $this->cqChatMsgId,
            'cqContactId' => $this->cqContactId,
            'status' => $this->status,
            'deliveredAt' => $this->deliveredAt?->format(\DateTimeInterface::ATOM),
            'seenAt' => $this->seenAt?->format(\DateTimeInterface::ATOM),
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt' => $this->updatedAt->format(\DateTimeInterface::ATOM)
        ];
    }
    
    public static function fromArray(array $data): self
    {
        $delivery = new self(
            $data['cq_chat_msg_id'],
            $data['cq_contact_id'],
            $data['status'] ?? 'SENT'
        );
        
        $delivery->setId($data['id']);
        
        if (isset($data['delivered_at']) && $data['delivered_at']) {
            $delivery->setDeliveredAt(new \DateTime($data['delivered_at']));
        }
        
        if (isset($data['seen_at']) && $data['seen_at']) {
            $delivery->setSeenAt(new \DateTime($data['seen_at']));
        }
        
        if (isset($data['created_at'])) {
            $delivery->setCreatedAt(new \DateTime($data['created_at']));
        }
        
        if (isset($data['updated_at'])) {
            $delivery->setUpdatedAt(new \DateTime($data['updated_at']));
        }
        
        return $delivery;
    }
}
