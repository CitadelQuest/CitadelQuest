<?php

namespace App\Entity;

use JsonSerializable;

class AiServiceModel implements JsonSerializable
{
    private string $id;
    private string $aiGatewayId;
    private ?AiGateway $aiGateway = null;
    private ?string $virtualKey = null;
    private string $modelName;
    private string $modelSlug;
    private ?int $contextWindow = null;
    private ?string $maxInput = null;
    private ?string $maxInputImageSize = null;
    private ?int $maxOutput = null;
    private ?float $ppmInput = null;
    private ?float $ppmOutput = null;
    private bool $isActive = true;
    private \DateTimeInterface $createdAt;
    private \DateTimeInterface $updatedAt;
    
    public function __construct(string $aiGatewayId, string $modelName, string $modelSlug)
    {
        $this->id = uuid_create();
        $this->aiGatewayId = $aiGatewayId;
        $this->modelName = $modelName;
        $this->modelSlug = $modelSlug;
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
    
    public function getVirtualKey(): ?string
    {
        return $this->virtualKey;
    }
    
    public function setVirtualKey(?string $virtualKey): self
    {
        $this->virtualKey = $virtualKey;
        return $this;
    }
    
    public function getModelName(): string
    {
        return $this->modelName;
    }
    
    public function setModelName(string $modelName): self
    {
        $this->modelName = $modelName;
        return $this;
    }
    
    public function getModelSlug(): string
    {
        return $this->modelSlug;
    }
    
    public function setModelSlug(string $modelSlug): self
    {
        $this->modelSlug = $modelSlug;
        return $this;
    }
    
    public function getContextWindow(): ?int
    {
        return $this->contextWindow;
    }
    
    public function setContextWindow(?int $contextWindow): self
    {
        $this->contextWindow = $contextWindow;
        return $this;
    }
    
    public function getMaxInput(): ?string
    {
        return $this->maxInput;
    }
    
    public function setMaxInput(?string $maxInput): self
    {
        $this->maxInput = $maxInput;
        return $this;
    }
    
    public function getMaxInputImageSize(): ?string
    {
        return $this->maxInputImageSize;
    }
    
    public function setMaxInputImageSize(?string $maxInputImageSize): self
    {
        $this->maxInputImageSize = $maxInputImageSize;
        return $this;
    }
    
    public function getMaxOutput(): ?int
    {
        return $this->maxOutput;
    }
    
    public function setMaxOutput(?int $maxOutput): self
    {
        $this->maxOutput = $maxOutput;
        return $this;
    }
    
    public function getPpmInput(): ?float
    {
        return $this->ppmInput;
    }
    
    public function setPpmInput(?float $ppmInput): self
    {
        $this->ppmInput = $ppmInput;
        return $this;
    }
    
    public function getPpmOutput(): ?float
    {
        return $this->ppmOutput;
    }
    
    public function setPpmOutput(?float $ppmOutput): self
    {
        $this->ppmOutput = $ppmOutput;
        return $this;
    }
    
    public function isActive(): bool
    {
        return $this->isActive;
    }
    
    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
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
            // deprecated: 'virtualKey' => $this->virtualKey ? '********' : null, // Don't expose the actual virtual key
            'modelName' => $this->modelName,
            'modelSlug' => $this->modelSlug,
            'contextWindow' => $this->contextWindow,
            'maxInput' => $this->maxInput,
            'maxInputImageSize' => $this->maxInputImageSize,
            'maxOutput' => $this->maxOutput,
            'ppmInput' => $this->ppmInput,
            'ppmOutput' => $this->ppmOutput,
            'isActive' => $this->isActive,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt' => $this->updatedAt->format(\DateTimeInterface::ATOM)
        ];
    }
    
    public static function fromArray(array $data): self
    {
        $model = new self(
            $data['ai_gateway_id'],
            $data['model_name'],
            $data['model_slug']
        );
        
        $model->setId($data['id']);
        
        if (isset($data['virtual_key'])) {
            $model->setVirtualKey($data['virtual_key']);
        }
        
        if (isset($data['context_window'])) {
            $model->setContextWindow($data['context_window']);
        }
        
        if (isset($data['max_input'])) {
            $model->setMaxInput($data['max_input']);
        }
        
        if (isset($data['max_input_image_size'])) {
            $model->setMaxInputImageSize($data['max_input_image_size']);
        }
        
        if (isset($data['max_output'])) {
            $model->setMaxOutput($data['max_output']);
        }
        
        if (isset($data['ppm_input'])) {
            $model->setPpmInput($data['ppm_input']);
        }
        
        if (isset($data['ppm_output'])) {
            $model->setPpmOutput($data['ppm_output']);
        }
        
        if (isset($data['is_active'])) {
            $model->setIsActive((bool)$data['is_active']);
        }
        
        if (isset($data['created_at'])) {
            $model->setCreatedAt(new \DateTime($data['created_at']));
        }
        
        if (isset($data['updated_at'])) {
            $model->setUpdatedAt(new \DateTime($data['updated_at']));
        }
        
        return $model;
    }
}
