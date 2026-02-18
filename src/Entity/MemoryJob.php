<?php

namespace App\Entity;

use JsonSerializable;

/**
 * Memory Job entity for CQ Memory Pack (.cqmpack)
 * 
 * Async job processing for extraction/analysis tasks.
 */
class MemoryJob implements JsonSerializable
{
    private string $id;
    private string $type;
    private string $status;
    private array $payload;
    private ?array $result;
    private int $progress;
    private int $totalSteps;
    private ?string $error;
    private \DateTimeInterface $createdAt;
    private ?\DateTimeInterface $startedAt;
    private ?\DateTimeInterface $completedAt;

    // Job types
    public const TYPE_EXTRACT_RECURSIVE = 'extract_recursive';
    public const TYPE_ANALYZE_RELATIONSHIPS = 'analyze_relationships';
    public const TYPE_CONSOLIDATE = 'consolidate';
    public const TYPE_MERGE = 'merge';

    // Job statuses
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public function __construct(string $type, array $payload = [])
    {
        $this->id = uuid_create();
        $this->type = $type;
        $this->status = self::STATUS_PENDING;
        $this->payload = $payload;
        $this->result = null;
        $this->progress = 0;
        $this->totalSteps = 0;
        $this->error = null;
        $this->createdAt = new \DateTime();
        $this->startedAt = null;
        $this->completedAt = null;
    }

    // Getters
    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getResult(): ?array
    {
        return $this->result;
    }

    public function getProgress(): int
    {
        return $this->progress;
    }

    public function getTotalSteps(): int
    {
        return $this->totalSteps;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?\DateTimeInterface
    {
        return $this->startedAt;
    }

    public function getCompletedAt(): ?\DateTimeInterface
    {
        return $this->completedAt;
    }

    // Setters
    public function setId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function setPayload(array $payload): self
    {
        $this->payload = $payload;
        return $this;
    }

    public function setResult(?array $result): self
    {
        $this->result = $result;
        return $this;
    }

    public function setProgress(int $progress): self
    {
        $this->progress = $progress;
        return $this;
    }

    public function setTotalSteps(int $totalSteps): self
    {
        $this->totalSteps = $totalSteps;
        return $this;
    }

    public function setError(?string $error): self
    {
        $this->error = $error;
        return $this;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function setStartedAt(?\DateTimeInterface $startedAt): self
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    public function setCompletedAt(?\DateTimeInterface $completedAt): self
    {
        $this->completedAt = $completedAt;
        return $this;
    }

    // Utility methods
    public function start(): self
    {
        $this->status = self::STATUS_PROCESSING;
        $this->startedAt = new \DateTime();
        return $this;
    }

    public function complete(?array $result = null): self
    {
        $this->status = self::STATUS_COMPLETED;
        $this->completedAt = new \DateTime();
        $this->result = $result;
        return $this;
    }

    public function fail(string $error): self
    {
        $this->status = self::STATUS_FAILED;
        $this->completedAt = new \DateTime();
        $this->error = $error;
        return $this;
    }

    public function incrementProgress(): self
    {
        $this->progress++;
        return $this;
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'payload' => $this->payload,
            'result' => $this->result,
            'progress' => $this->progress,
            'totalSteps' => $this->totalSteps,
            'error' => $this->error,
            'createdAt' => $this->createdAt->format('c'),
            'startedAt' => $this->startedAt?->format('c'),
            'completedAt' => $this->completedAt?->format('c'),
        ];
    }

    public static function fromArray(array $data): self
    {
        $payload = $data['payload'] ?? [];
        if (is_string($payload)) {
            $payload = json_decode($payload, true) ?? [];
        }

        $result = $data['result'] ?? null;
        if (is_string($result)) {
            $result = json_decode($result, true);
        }

        $job = new self($data['type'], $payload);
        $job->setId($data['id']);
        $job->setStatus($data['status'] ?? self::STATUS_PENDING);
        $job->setResult($result);
        $job->setProgress((int)($data['progress'] ?? 0));
        $job->setTotalSteps((int)($data['total_steps'] ?? 0));
        $job->setError($data['error'] ?? null);
        $job->setCreatedAt(new \DateTime($data['created_at']));

        if (!empty($data['started_at'])) {
            $job->setStartedAt(new \DateTime($data['started_at']));
        }

        if (!empty($data['completed_at'])) {
            $job->setCompletedAt(new \DateTime($data['completed_at']));
        }

        return $job;
    }

    public static function getValidTypes(): array
    {
        return [
            self::TYPE_EXTRACT_RECURSIVE,
            self::TYPE_ANALYZE_RELATIONSHIPS,
            self::TYPE_CONSOLIDATE,
            self::TYPE_MERGE,
        ];
    }

    public static function getValidStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
        ];
    }
}
