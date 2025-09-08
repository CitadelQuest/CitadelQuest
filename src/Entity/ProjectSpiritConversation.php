<?php

namespace App\Entity;

use JsonSerializable;

class ProjectSpiritConversation implements JsonSerializable
{
    private string $id;
    private string $projectId;
    private string $spiritConversationId;
    private ?string $category;
    private ?string $systemPromptInstructions;
    private ?string $taskList;
    private ?string $taskListStatus;
    private ?string $taskListResult;
    private ?string $frontendData;
    private bool $autorun;
    private \DateTimeInterface $createdAt;
    private \DateTimeInterface $updatedAt;
    
    public function __construct(
        string $projectId,
        string $spiritConversationId,
        ?string $category = null,
        ?string $systemPromptInstructions = null,
        ?string $taskList = null,
        ?string $taskListStatus = null,
        ?string $taskListResult = null,
        ?string $frontendData = null,
        bool $autorun = false
    ) {
        $this->id = uuid_create();
        $this->projectId = $projectId;
        $this->spiritConversationId = $spiritConversationId;
        $this->category = $category;
        $this->systemPromptInstructions = $systemPromptInstructions;
        $this->taskList = $taskList;
        $this->taskListStatus = $taskListStatus;
        $this->taskListResult = $taskListResult;
        $this->frontendData = $frontendData;
        $this->autorun = $autorun;
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
    
    public function getCategory(): ?string
    {
        return $this->category;
    }
    
    public function setCategory(?string $category): self
    {
        $this->category = $category;
        $this->updateUpdatedAt();
        return $this;
    }
    
    public function getSystemPromptInstructions(): ?string
    {
        return $this->systemPromptInstructions;
    }
    
    public function setSystemPromptInstructions(?string $systemPromptInstructions): self
    {
        $this->systemPromptInstructions = $systemPromptInstructions;
        $this->updateUpdatedAt();
        return $this;
    }
    
    public function getTaskList(): ?string
    {
        return $this->taskList;
    }
    
    public function setTaskList(?string $taskList): self
    {
        $this->taskList = $taskList;
        $this->updateUpdatedAt();
        return $this;
    }
    
    public function getTaskListStatus(): ?string
    {
        return $this->taskListStatus;
    }
    
    public function setTaskListStatus(?string $taskListStatus): self
    {
        $this->taskListStatus = $taskListStatus;
        $this->updateUpdatedAt();
        return $this;
    }
    
    public function getTaskListResult(): ?string
    {
        return $this->taskListResult;
    }
    
    public function setTaskListResult(?string $taskListResult): self
    {
        $this->taskListResult = $taskListResult;
        $this->updateUpdatedAt();
        return $this;
    }
    
    public function getFrontendData(): ?string
    {
        return $this->frontendData;
    }
    
    public function setFrontendData(?string $frontendData): self
    {
        $this->frontendData = $frontendData;
        $this->updateUpdatedAt();
        return $this;
    }
    
    public function isAutorun(): bool
    {
        return $this->autorun;
    }
    
    public function setAutorun(bool $autorun): self
    {
        $this->autorun = $autorun;
        $this->updateUpdatedAt();
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
            'projectId' => $this->projectId,
            'spiritConversationId' => $this->spiritConversationId,
            'category' => $this->category,
            'systemPromptInstructions' => $this->systemPromptInstructions,
            'taskList' => $this->taskList,
            'taskListStatus' => $this->taskListStatus,
            'taskListResult' => $this->taskListResult,
            'frontendData' => $this->frontendData,
            'autorun' => $this->autorun,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt' => $this->updatedAt->format(\DateTimeInterface::ATOM)
        ];
    }
    
    public static function fromArray(array $data): self
    {
        $conversation = new self(
            $data['project_id'],
            $data['spirit_conversation_id'],
            $data['category'] ?? null,
            $data['system_prompt_instructions'] ?? null,
            $data['task_list'] ?? null,
            $data['task_list_status'] ?? null,
            $data['task_list_result'] ?? null,
            $data['frontend_data'] ?? null,
            (bool) ($data['autorun'] ?? false)
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
