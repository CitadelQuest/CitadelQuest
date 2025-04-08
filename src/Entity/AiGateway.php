<?php

namespace App\Entity;

use JsonSerializable;

class AiGateway implements JsonSerializable
{
    private string $id;
    private string $name;
    private string $apiKey;
    private string $apiEndpointUrl;
    private \DateTimeInterface $createdAt;
    private \DateTimeInterface $updatedAt;
    
    public function __construct(string $name, string $apiKey, string $apiEndpointUrl)
    {
        $this->id = uuid_create();
        $this->name = $name;
        $this->apiKey = $apiKey;
        $this->apiEndpointUrl = $apiEndpointUrl;
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
    
    public function getName(): string
    {
        return $this->name;
    }
    
    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }
    
    public function getApiKey(): string
    {
        return $this->apiKey;
    }
    
    public function setApiKey(string $apiKey): self
    {
        $this->apiKey = $apiKey;
        return $this;
    }
    
    public function getApiEndpointUrl(): string
    {
        return $this->apiEndpointUrl;
    }
    
    public function setApiEndpointUrl(string $apiEndpointUrl): self
    {
        $this->apiEndpointUrl = $apiEndpointUrl;
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
            'name' => $this->name,
            'apiKey' => '********', // Don't expose the actual API key
            'apiEndpointUrl' => $this->apiEndpointUrl,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt' => $this->updatedAt->format(\DateTimeInterface::ATOM)
        ];
    }
    
    public static function fromArray(array $data): self
    {
        $gateway = new self(
            $data['name'],
            $data['api_key'],
            $data['api_endpoint_url']
        );
        
        $gateway->setId($data['id']);
        
        if (isset($data['created_at'])) {
            $gateway->setCreatedAt(new \DateTime($data['created_at']));
        }
        
        if (isset($data['updated_at'])) {
            $gateway->setUpdatedAt(new \DateTime($data['updated_at']));
        }
        
        return $gateway;
    }
}
