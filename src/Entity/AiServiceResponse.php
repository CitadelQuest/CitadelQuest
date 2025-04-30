<?php

namespace App\Entity;

use JsonSerializable;

class AiServiceResponse implements JsonSerializable
{
    private string $id;
    private string $aiServiceRequestId;
    private ?AiServiceRequest $aiServiceRequest = null;
    private string $message; // JSON string
    private string $fullResponse; // JSON string
    private ?string $finishReason = null;
    private ?int $inputTokens = null;
    private ?int $outputTokens = null;
    private ?int $totalTokens = null;
    private \DateTimeInterface $createdAt;
    
    public function __construct(string $aiServiceRequestId, array $message, array $fullResponse)
    {
        $this->id = uuid_create();
        $this->aiServiceRequestId = $aiServiceRequestId;
        $this->message = json_encode($message);
        $this->fullResponse = json_encode($fullResponse);
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
    
    public function getMessage(): array
    {
        return json_decode($this->message, true);
    }
    
    public function getMessageRaw(): string
    {
        return $this->message;
    }
    
    public function setMessage(array $message): self
    {
        $this->message = json_encode($message);
        return $this;
    }
    
    public function getFullResponse(): array
    {
        return json_decode($this->fullResponse, true);
    }
    
    public function getFullResponseRaw(): string
    {
        return $this->fullResponse;
    }
    
    public function setFullResponse(array $fullResponse): self
    {
        $this->fullResponse = json_encode($fullResponse);
        return $this;
    }
    
    public function getFinishReason(): ?string
    {
        return $this->finishReason;
    }
    
    public function setFinishReason(?string $finishReason): self
    {
        $this->finishReason = $finishReason;
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
            'aiServiceRequestId' => $this->aiServiceRequestId,
            'message' => $this->getMessage(),
            'fullResponse' => $this->getFullResponse(),
            'finishReason' => $this->finishReason,
            'inputTokens' => $this->inputTokens,
            'outputTokens' => $this->outputTokens,
            'totalTokens' => $this->totalTokens,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM)
        ];
    }
    
    public static function fromArray(array $data): self
    {
        $message = json_decode($data['message'], true);
        if (!$message) {
            $message = [];
        }
        
        $fullResponse = json_decode($data['full_response'], true);
        if (!$fullResponse) {
            $fullResponse = [];
        }
        
        $response = new self($data['ai_service_request_id'], $message, $fullResponse);
        $response->setId($data['id']);
        
        if (isset($data['finish_reason'])) {
            $response->setFinishReason($data['finish_reason']);
        }
        
        if (isset($data['input_tokens'])) {
            $response->setInputTokens($data['input_tokens']);
        }
        
        if (isset($data['output_tokens'])) {
            $response->setOutputTokens($data['output_tokens']);
        }
        
        if (isset($data['total_tokens'])) {
            $response->setTotalTokens($data['total_tokens']);
        }
        
        if (isset($data['created_at'])) {
            $response->setCreatedAt(new \DateTime($data['created_at']));
        }
        
        return $response;
    }
}
