<?php

namespace App\Entity;

use JsonSerializable;

class AiServiceUseLog implements JsonSerializable
{
    private string $id;
    private string $aiGatewayId;
    private ?AiGateway $aiGateway = null;
    private string $aiServiceModelId;
    private ?AiServiceModel $aiServiceModel = null;
    private string $aiServiceRequestId;
    private ?AiServiceRequest $aiServiceRequest = null;
    private string $aiServiceResponseId;
    private ?AiServiceResponse $aiServiceResponse = null;
    private ?string $purpose = null;
    private ?int $inputTokens = null;
    private ?int $outputTokens = null;
    private ?int $totalTokens = null;
    private ?float $inputPrice = null;
    private ?float $outputPrice = null;
    private ?float $totalPrice = null;
    private \DateTimeInterface $createdAt;
    
    public function __construct(
        string $aiGatewayId,
        string $aiServiceModelId,
        string $aiServiceRequestId,
        string $aiServiceResponseId
    ) {
        $this->id = uuid_create();
        $this->aiGatewayId = $aiGatewayId;
        $this->aiServiceModelId = $aiServiceModelId;
        $this->aiServiceRequestId = $aiServiceRequestId;
        $this->aiServiceResponseId = $aiServiceResponseId;
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
    
    public function getAiServiceModelId(): string
    {
        return $this->aiServiceModelId;
    }
    
    public function setAiServiceModelId(string $aiServiceModelId): self
    {
        $this->aiServiceModelId = $aiServiceModelId;
        return $this;
    }
    
    public function getAiServiceModel(): ?AiServiceModel
    {
        return $this->aiServiceModel;
    }
    
    public function setAiServiceModel(?AiServiceModel $aiServiceModel): self
    {
        $this->aiServiceModel = $aiServiceModel;
        if ($aiServiceModel !== null) {
            $this->aiServiceModelId = $aiServiceModel->getId();
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
    
    public function getAiServiceResponseId(): string
    {
        return $this->aiServiceResponseId;
    }
    
    public function setAiServiceResponseId(string $aiServiceResponseId): self
    {
        $this->aiServiceResponseId = $aiServiceResponseId;
        return $this;
    }
    
    public function getAiServiceResponse(): ?AiServiceResponse
    {
        return $this->aiServiceResponse;
    }
    
    public function setAiServiceResponse(?AiServiceResponse $aiServiceResponse): self
    {
        $this->aiServiceResponse = $aiServiceResponse;
        if ($aiServiceResponse !== null) {
            $this->aiServiceResponseId = $aiServiceResponse->getId();
        }
        return $this;
    }
    
    public function getPurpose(): ?string
    {
        return $this->purpose;
    }
    
    public function setPurpose(?string $purpose): self
    {
        $this->purpose = $purpose;
        return $this;
    }
    
    public function getInputTokens(): ?int
    {
        return $this->inputTokens;
    }
    
    public function setInputTokens(?int $inputTokens): self
    {
        $this->inputTokens = $inputTokens;
        return $this;
    }
    
    public function getOutputTokens(): ?int
    {
        return $this->outputTokens;
    }
    
    public function setOutputTokens(?int $outputTokens): self
    {
        $this->outputTokens = $outputTokens;
        return $this;
    }
    
    public function getTotalTokens(): ?int
    {
        return $this->totalTokens;
    }
    
    public function setTotalTokens(?int $totalTokens): self
    {
        $this->totalTokens = $totalTokens;
        return $this;
    }
    
    public function getInputPrice(): ?float
    {
        return $this->inputPrice;
    }
    
    public function setInputPrice(?float $inputPrice): self
    {
        $this->inputPrice = $inputPrice;
        return $this;
    }
    
    public function getOutputPrice(): ?float
    {
        return $this->outputPrice;
    }
    
    public function setOutputPrice(?float $outputPrice): self
    {
        $this->outputPrice = $outputPrice;
        return $this;
    }
    
    public function getTotalPrice(): ?float
    {
        return $this->totalPrice;
    }
    
    public function setTotalPrice(?float $totalPrice): self
    {
        $this->totalPrice = $totalPrice;
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
            'aiGatewayId' => $this->aiGatewayId,
            'aiServiceModelId' => $this->aiServiceModelId,
            'aiServiceRequestId' => $this->aiServiceRequestId,
            'aiServiceResponseId' => $this->aiServiceResponseId,
            'purpose' => $this->purpose,
            'inputTokens' => $this->inputTokens,
            'outputTokens' => $this->outputTokens,
            'totalTokens' => $this->totalTokens,
            'inputPrice' => $this->inputPrice,
            'outputPrice' => $this->outputPrice,
            'totalPrice' => $this->totalPrice,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM)
        ];
    }
    
    public static function fromArray(array $data): self
    {
        $log = new self(
            $data['ai_gateway_id'],
            $data['ai_service_model_id'],
            $data['ai_service_request_id'],
            $data['ai_service_response_id']
        );
        
        $log->setId($data['id']);
        
        if (isset($data['purpose'])) {
            $log->setPurpose($data['purpose']);
        }
        
        if (isset($data['input_tokens'])) {
            $log->setInputTokens($data['input_tokens']);
        }
        
        if (isset($data['output_tokens'])) {
            $log->setOutputTokens($data['output_tokens']);
        }
        
        if (isset($data['total_tokens'])) {
            $log->setTotalTokens($data['total_tokens']);
        }
        
        if (isset($data['input_price'])) {
            $log->setInputPrice($data['input_price']);
        }
        
        if (isset($data['output_price'])) {
            $log->setOutputPrice($data['output_price']);
        }
        
        if (isset($data['total_price'])) {
            $log->setTotalPrice($data['total_price']);
        }
        
        if (isset($data['created_at'])) {
            $log->setCreatedAt(new \DateTime($data['created_at']));
        }
        
        return $log;
    }
}
