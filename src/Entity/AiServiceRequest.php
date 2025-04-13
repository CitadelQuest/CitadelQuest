<?php

namespace App\Entity;

use JsonSerializable;

class AiServiceRequest implements JsonSerializable
{
    private string $id;
    private string $aiServiceModelId;
    private ?AiServiceModel $aiServiceModel = null;
    private string $messages; // JSON string
    private ?int $maxTokens = 1000;
    private ?float $temperature = 0.7;
    private ?string $stopSequence = null;
    private \DateTimeInterface $createdAt;
    
    public function __construct(string $aiServiceModelId, array $messages)
    {
        $this->id = uuid_create();
        $this->aiServiceModelId = $aiServiceModelId;
        $this->messages = json_encode($messages);
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
    
    public function getMessages(): array
    {
        return json_decode($this->messages, true);
    }
    
    public function getMessagesRaw(): string
    {
        return $this->messages;
    }
    
    public function setMessages(array $messages): self
    {
        $this->messages = json_encode($messages);
        return $this;
    }
    
    public function getMaxTokens(): ?int
    {
        return $this->maxTokens;
    }
    
    public function setMaxTokens(?int $maxTokens): self
    {
        $this->maxTokens = $maxTokens;
        return $this;
    }
    
    public function getTemperature(): ?float
    {
        return $this->temperature;
    }
    
    public function setTemperature(?float $temperature): self
    {
        $this->temperature = $temperature;
        return $this;
    }
    
    public function getStopSequence(): ?string
    {
        return $this->stopSequence;
    }
    
    public function setStopSequence(?string $stopSequence): self
    {
        $this->stopSequence = $stopSequence;
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
            'aiServiceModelId' => $this->aiServiceModelId,
            'messages' => $this->getMessages(),
            'maxTokens' => $this->maxTokens,
            'temperature' => $this->temperature,
            'stopSequence' => $this->stopSequence,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM)
        ];
    }
    
    public static function fromArray(array $data): self
    {
        $messages = json_decode($data['messages'], true);
        if (!$messages) {
            $messages = [];
        }
        
        $request = new self($data['ai_service_model_id'], $messages);
        $request->setId($data['id']);
        
        if (isset($data['max_tokens'])) {
            $request->setMaxTokens($data['max_tokens']);
        }
        
        if (isset($data['temperature'])) {
            $request->setTemperature($data['temperature']);
        }
        
        if (isset($data['stop_sequence'])) {
            $request->setStopSequence($data['stop_sequence']);
        }
        
        if (isset($data['created_at'])) {
            $request->setCreatedAt(new \DateTime($data['created_at']));
        }
        
        return $request;
    }
}
