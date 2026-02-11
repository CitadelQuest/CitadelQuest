<?php

namespace App\Entity;

use DateTime;

/**
 * Spirit Conversation Message Entity
 * 
 * Represents a single message in a Spirit conversation.
 * Messages are linked via parent_message_id to form chains.
 * 
 * Message Types:
 * - stop: Final text response (finish_reason = 'stop')
 * - tool_use: AI wants to use tools (finish_reason = 'tool_use')
 * - tool_result: Result of tool execution
 * - length: Context length limit reached (finish_reason = 'length')
 * - memory_recall: Phase 3.5 — recalled memories info (informational, not sent to AI)
 */
class SpiritConversationMessage implements \JsonSerializable
{
    private string $id;
    private string $conversationId;
    private string $role;  // 'user', 'assistant', 'tool'
    private string $type;  // 'stop', 'tool_use', 'tool_result', 'length'
    private array $content;  // JSON content
    private ?string $aiServiceRequestId;
    private ?string $aiServiceResponseId;
    private ?string $parentMessageId;
    private DateTime $createdAt;

    public function __construct(
        string $conversationId,
        string $role,
        string $type,
        array $content,
        ?string $parentMessageId = null
    ) {
        $this->id = $this->generateUuid();
        $this->conversationId = $conversationId;
        $this->role = $role;
        $this->type = $type;
        $this->content = $content;
        $this->parentMessageId = $parentMessageId;
        $this->aiServiceRequestId = null;
        $this->aiServiceResponseId = null;
        $this->createdAt = new DateTime();
    }

    /**
     * Generate UUID v4
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * Create entity from database array
     */
    public static function fromArray(array $data): self
    {
        $message = new self(
            $data['conversation_id'],
            $data['role'],
            $data['type'],
            json_decode($data['content'], true) ?? [],
            $data['parent_message_id'] ?? null
        );
        
        $message->id = $data['id'];
        $message->aiServiceRequestId = $data['ai_service_request_id'] ?? null;
        $message->aiServiceResponseId = $data['ai_service_response_id'] ?? null;
        $message->createdAt = new DateTime($data['created_at']);
        
        return $message;
    }

    // Getters
    public function getId(): string 
    { 
        return $this->id; 
    }
    
    public function getConversationId(): string 
    { 
        return $this->conversationId; 
    }
    
    public function getRole(): string 
    { 
        return $this->role; 
    }
    
    public function getType(): string 
    { 
        return $this->type; 
    }
    
    public function getContent(): array 
    { 
        return $this->content; 
    }
    
    public function getParentMessageId(): ?string 
    { 
        return $this->parentMessageId; 
    }
    
    public function getAiServiceRequestId(): ?string 
    { 
        return $this->aiServiceRequestId; 
    }
    
    public function getAiServiceResponseId(): ?string 
    { 
        return $this->aiServiceResponseId; 
    }
    
    public function getCreatedAt(): DateTime 
    { 
        return $this->createdAt; 
    }

    // Setters
    public function setAiServiceRequestId(?string $id): void 
    { 
        $this->aiServiceRequestId = $id; 
    }
    
    public function setAiServiceResponseId(?string $id): void 
    { 
        $this->aiServiceResponseId = $id; 
    }

    public function setContent(array $content): void 
    { 
        $this->content = $content; 
    }

    /**
     * JSON serialization for API responses
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'conversationId' => $this->conversationId,
            'role' => $this->role,
            'type' => $this->type,
            'content' => $this->content,
            'parentMessageId' => $this->parentMessageId,
            'aiServiceRequestId' => $this->aiServiceRequestId,
            'aiServiceResponseId' => $this->aiServiceResponseId,
            'createdAt' => $this->createdAt->format('Y-m-d H:i:s'),
            'timestamp' => $this->createdAt->format('Y-m-d H:i:s')  // For frontend compatibility
        ];
    }
}
