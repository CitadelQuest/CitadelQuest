<?php

namespace App\Entity;

use JsonSerializable;

class AiUserSettings implements JsonSerializable
{
    private string $id;
    private string $aiGatewayId;
    private ?AiGateway $aiGateway = null;
    private ?string $primaryAiServiceModelId = null;
    private ?AiServiceModel $primaryAiServiceModel = null;
    private ?string $secondaryAiServiceModelId = null;
    private ?AiServiceModel $secondaryAiServiceModel = null;
    private \DateTimeInterface $createdAt;
    private \DateTimeInterface $updatedAt;
    
    public function __construct(string $aiGatewayId)
    {
        $this->id = uuid_create();
        $this->aiGatewayId = $aiGatewayId;
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
    
    public function getAiGatewayId(): string
    {
        return $this->aiGatewayId;
    }
    
    public function setAiGatewayId(string $aiGatewayId): self
    {
        $this->aiGatewayId = $aiGatewayId;
        return $this;
    }
    
    public function getAiGateway(): ?AiGateway
    {
        return $this->aiGateway;
    }
    
    public function setAiGateway(?AiGateway $aiGateway): self
    {
        $this->aiGateway = $aiGateway;
        if ($aiGateway !== null) {
            $this->aiGatewayId = $aiGateway->getId();
        }
        return $this;
    }
    
    public function getPrimaryAiServiceModelId(): ?string
    {
        return $this->primaryAiServiceModelId;
    }
    
    public function setPrimaryAiServiceModelId(?string $primaryAiServiceModelId): self
    {
        $this->primaryAiServiceModelId = $primaryAiServiceModelId;
        return $this;
    }
    
    public function getPrimaryAiServiceModel(): ?AiServiceModel
    {
        return $this->primaryAiServiceModel;
    }
    
    public function setPrimaryAiServiceModel(?AiServiceModel $primaryAiServiceModel): self
    {
        $this->primaryAiServiceModel = $primaryAiServiceModel;
        if ($primaryAiServiceModel !== null) {
            $this->primaryAiServiceModelId = $primaryAiServiceModel->getId();
        }
        return $this;
    }
    
    public function getSecondaryAiServiceModelId(): ?string
    {
        return $this->secondaryAiServiceModelId;
    }
    
    public function setSecondaryAiServiceModelId(?string $secondaryAiServiceModelId): self
    {
        $this->secondaryAiServiceModelId = $secondaryAiServiceModelId;
        return $this;
    }
    
    public function getSecondaryAiServiceModel(): ?AiServiceModel
    {
        return $this->secondaryAiServiceModel;
    }
    
    public function setSecondaryAiServiceModel(?AiServiceModel $secondaryAiServiceModel): self
    {
        $this->secondaryAiServiceModel = $secondaryAiServiceModel;
        if ($secondaryAiServiceModel !== null) {
            $this->secondaryAiServiceModelId = $secondaryAiServiceModel->getId();
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
            'aiGatewayId' => $this->aiGatewayId,
            'aiGateway' => $this->aiGateway ? $this->aiGateway->jsonSerialize() : null,
            'primaryAiServiceModelId' => $this->primaryAiServiceModelId,
            'primaryAiServiceModel' => $this->primaryAiServiceModel ? $this->primaryAiServiceModel->jsonSerialize() : null,
            'secondaryAiServiceModelId' => $this->secondaryAiServiceModelId,
            'secondaryAiServiceModel' => $this->secondaryAiServiceModel ? $this->secondaryAiServiceModel->jsonSerialize() : null,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt' => $this->updatedAt->format(\DateTimeInterface::ATOM)
        ];
    }
    
    public static function fromArray(array $data): self
    {
        $settings = new self($data['ai_gateway_id']);
        
        if (isset($data['id'])) {
            $settings->setId($data['id']);
        }
        
        if (isset($data['primary_ai_service_model_id'])) {
            $settings->setPrimaryAiServiceModelId($data['primary_ai_service_model_id']);
        }
        
        if (isset($data['secondary_ai_service_model_id'])) {
            $settings->setSecondaryAiServiceModelId($data['secondary_ai_service_model_id']);
        }
        
        if (isset($data['created_at'])) {
            $settings->setCreatedAt(new \DateTime($data['created_at']));
        }
        
        if (isset($data['updated_at'])) {
            $settings->setUpdatedAt(new \DateTime($data['updated_at']));
        }
        
        return $settings;
    }
}
