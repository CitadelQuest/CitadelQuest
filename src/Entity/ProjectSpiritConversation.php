<?php

namespace App\Entity;

use JsonSerializable;

/**
 * Pairs an existing `spirit_conversation` to the Project (workspace) it belongs to,
 * so Spirit chat can filter conversations by the active project. One Spirit assigned
 * to multiple projects gets a separate conversation list per project.
 */
class ProjectSpiritConversation implements JsonSerializable
{
    private string $id;
    private string $projectId;
    private string $spiritConversationId;
    private \DateTimeInterface $createdAt;
    private \DateTimeInterface $updatedAt;

    public function __construct(
        string $projectId,
        string $spiritConversationId
    ) {
        $this->id = uuid_create();
        $this->projectId = $projectId;
        $this->spiritConversationId = $spiritConversationId;
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

    public function getProjectId(): string
    {
        return $this->projectId;
    }

    public function setProjectId(string $projectId): self
    {
        $this->projectId = $projectId;
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

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'projectId' => $this->projectId,
            'spiritConversationId' => $this->spiritConversationId,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt' => $this->updatedAt->format(\DateTimeInterface::ATOM)
        ];
    }

    public static function fromArray(array $data): self
    {
        $conversation = new self(
            $data['project_id'],
            $data['spirit_conversation_id']
        );

        $conversation->setId($data['id']);

        if (isset($data['created_at'])) {
            $conversation->setCreatedAt(new \DateTime($data['created_at']));
        }

        if (isset($data['updated_at'])) {
            $conversation->setUpdatedAt(new \DateTime($data['updated_at']));
        }

        return $conversation;
    }
}
