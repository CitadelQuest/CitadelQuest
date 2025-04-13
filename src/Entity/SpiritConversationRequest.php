<?php

namespace App\Entity;

use JsonSerializable;

class SpiritConversationRequest implements JsonSerializable
{
    private string $id;
    private string $spiritConversationId;
    private ?SpiritConversation $spiritConversation = null;
    private string $aiServiceRequestId;
    private ?AiServiceRequest $aiServiceRequest = null;
    private \DateTimeInterface $createdAt;
    
    public function __construct(string $spiritConversationId, string $aiServiceRequestId)
    {
        $this->id = uuid_create();
        $this->spiritConversationId = $spiritConversationId;
        $this->aiServiceRequestId = $aiServiceRequestId;
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
    
    public function getSpiritConversationId(): string
    {
        return $this->spiritConversationId;
    }
    
    public function setSpiritConversationId(string $spiritConversationId): self
    {
        $this->spiritConversationId = $spiritConversationId;
        return $this;
    }
    
    public function getSpiritConversation(): ?SpiritConversation
    {
        return $this->spiritConversation;
    }
    
    public function setSpiritConversation(?SpiritConversation $spiritConversation): self
    {
        $this->spiritConversation = $spiritConversation;
        if ($spiritConversation !== null) {
            $this->spiritConversationId = $spiritConversation->getId();
        }
        return $this;
    }
    
    public function getAiServiceRequestId(): string
    {
        return $this->aiServiceRequestId;
    }
    
    public function setAiServiceRequestId(string $aiServiceRequestId): self
    {
        $this->aiServiceRequestId = $aiServiceRequestId;
        return $this;
    }
    
    public function getAiServiceRequest(): ?AiServiceRequest
    {
        return $this->aiServiceRequest;
    }
    
    public function setAiServiceRequest(?AiServiceRequest $aiServiceRequest): self
    {
        $this->aiServiceRequest = $aiServiceRequest;
        if ($aiServiceRequest !== null) {
            $this->aiServiceRequestId = $aiServiceRequest->getId();
        }
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
            'spiritConversationId' => $this->spiritConversationId,
            'aiServiceRequestId' => $this->aiServiceRequestId,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM)
        ];
    }
    
    public static function fromArray(array $data): self
    {
        $request = new self(
            $data['spirit_conversation_id'],
            $data['ai_service_request_id']
        );
        
        $request->setId($data['id']);
        
        if (isset($data['created_at'])) {
            $request->setCreatedAt(new \DateTime($data['created_at']));
        }
        
        return $request;
    }
}
