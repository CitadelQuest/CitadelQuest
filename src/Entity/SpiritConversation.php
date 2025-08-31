<?php

namespace App\Entity;

use JsonSerializable;

class SpiritConversation implements JsonSerializable
{
    private string $id;
    private string $spiritId;
    private ?Spirit $spirit = null;
    private string $title;
    private string $messages; // JSON encoded array of messages
    private \DateTimeInterface $createdAt;
    private \DateTimeInterface $lastInteraction;
    
    public function __construct(string $spiritId, string $title, array $messages = [])
    {
        $this->id = uuid_create();
        $this->spiritId = $spiritId;
        $this->title = $title;
        $this->messages = json_encode($messages);
        $this->createdAt = new \DateTime();
        $this->lastInteraction = new \DateTime();
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
    
    public function getSpiritId(): string
    {
        return $this->spiritId;
    }
    
    public function setSpiritId(string $spiritId): self
    {
        $this->spiritId = $spiritId;
        return $this;
    }
    
    public function getSpirit(): ?Spirit
    {
        return $this->spirit;
    }
    
    public function setSpirit(?Spirit $spirit): self
    {
        $this->spirit = $spirit;
        if ($spirit !== null) {
            $this->spiritId = $spirit->getId();
        }
        return $this;
    }
    
    public function getTitle(): string
    {
        return $this->title;
    }
    
    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }
    
    public function getMessages(): array
    {
        return json_decode($this->messages, true);
    }
    
    public function setMessages(array $messages): self
    {
        $this->messages = json_encode($messages);
        return $this;
    }
    
    public function addMessage(array $message): self
    {
        $messages = $this->getMessages();
        $messages[] = $message;
        $this->messages = json_encode($messages);
        $this->lastInteraction = new \DateTime();
        return $this;
    }

    public function removeToolCallsAndResultsFromMessages(): self // not used
    {
        $messages = $this->getMessages();
        $messages = array_filter($messages, fn($message) => !isset($message['tool_calls']) && !isset($message['tool_result']));
        $this->messages = json_encode($messages);
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
    
    public function getLastInteraction(): \DateTimeInterface
    {
        return $this->lastInteraction;
    }
    
    public function setLastInteraction(\DateTimeInterface $lastInteraction): self
    {
        $this->lastInteraction = $lastInteraction;
        return $this;
    }
    
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'spiritId' => $this->spiritId,
            'title' => $this->title,
            'messages' => $this->getMessages(),
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'lastInteraction' => $this->lastInteraction->format(\DateTimeInterface::ATOM)
        ];
    }
    
    public static function fromArray(array $data): self
    {
        $conversation = new self(
            $data['spirit_id'],
            $data['title'],
            json_decode($data['messages']??'[]', true) ?? []
        );
        
        $conversation->setId($data['id']);
        
        if (isset($data['created_at'])) {
            $conversation->setCreatedAt(new \DateTime($data['created_at']));
        }
        
        if (isset($data['last_interaction'])) {
            $conversation->setLastInteraction(new \DateTime($data['last_interaction']));
        }
        
        return $conversation;
    }
}
