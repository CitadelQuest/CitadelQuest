<?php

namespace App\Entity;

use App\Repository\MigrationRequestRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * MigrationRequest Entity
 * 
 * Tracks user migration requests between CitadelQuest instances.
 * Stored in main.db (shared database).
 */
#[ORM\Entity(repositoryClass: MigrationRequestRepository::class)]
#[ORM\Table(name: 'migration_request')]
#[ORM\HasLifecycleCallbacks]
class MigrationRequest
{
    // Migration statuses
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_TRANSFERRING = 'transferring';
    public const STATUS_RESTORING = 'restoring';
    public const STATUS_NOTIFYING = 'notifying';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    // Direction
    public const DIRECTION_OUTGOING = 'outgoing';
    public const DIRECTION_INCOMING = 'incoming';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private Uuid $userId;

    #[ORM\Column(length: 180)]
    private string $username;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 255)]
    private string $sourceDomain;

    #[ORM\Column(length: 255)]
    private string $targetDomain;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $backupSize = null;

    #[ORM\Column(length: 255, nullable: true, unique: true)]
    private ?string $migrationToken = null;

    #[ORM\Column(length: 50)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 20)]
    private string $direction;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $adminId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $updatedAt;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $acceptedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $completedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $tokenExpiresAt = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTime();
    }

    // Getters and Setters

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function setId(Uuid $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getUserId(): Uuid
    {
        return $this->userId;
    }

    public function setUserId(Uuid $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): self
    {
        $this->username = $username;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function getSourceDomain(): string
    {
        return $this->sourceDomain;
    }

    public function setSourceDomain(string $sourceDomain): self
    {
        $this->sourceDomain = $sourceDomain;
        return $this;
    }

    public function getTargetDomain(): string
    {
        return $this->targetDomain;
    }

    public function setTargetDomain(string $targetDomain): self
    {
        $this->targetDomain = $targetDomain;
        return $this;
    }

    public function getBackupSize(): ?int
    {
        return $this->backupSize;
    }

    public function setBackupSize(?int $backupSize): self
    {
        $this->backupSize = $backupSize;
        return $this;
    }

    public function getMigrationToken(): ?string
    {
        return $this->migrationToken;
    }

    public function setMigrationToken(?string $migrationToken): self
    {
        $this->migrationToken = $migrationToken;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getDirection(): string
    {
        return $this->direction;
    }

    public function setDirection(string $direction): self
    {
        $this->direction = $direction;
        return $this;
    }

    public function getAdminId(): ?Uuid
    {
        return $this->adminId;
    }

    public function setAdminId(?Uuid $adminId): self
    {
        $this->adminId = $adminId;
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;
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

    public function getAcceptedAt(): ?\DateTimeInterface
    {
        return $this->acceptedAt;
    }

    public function setAcceptedAt(?\DateTimeInterface $acceptedAt): self
    {
        $this->acceptedAt = $acceptedAt;
        return $this;
    }

    public function getCompletedAt(): ?\DateTimeInterface
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeInterface $completedAt): self
    {
        $this->completedAt = $completedAt;
        return $this;
    }

    public function getTokenExpiresAt(): ?\DateTimeInterface
    {
        return $this->tokenExpiresAt;
    }

    public function setTokenExpiresAt(?\DateTimeInterface $tokenExpiresAt): self
    {
        $this->tokenExpiresAt = $tokenExpiresAt;
        return $this;
    }

    // Helper methods

    public function isOutgoing(): bool
    {
        return $this->direction === self::DIRECTION_OUTGOING;
    }

    public function isIncoming(): bool
    {
        return $this->direction === self::DIRECTION_INCOMING;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isTokenValid(): bool
    {
        if (!$this->migrationToken || !$this->tokenExpiresAt) {
            return false;
        }
        return $this->tokenExpiresAt > new \DateTime();
    }

    public function generateMigrationToken(): string
    {
        $this->migrationToken = bin2hex(random_bytes(32));
        $this->tokenExpiresAt = new \DateTime('+24 hours');
        return $this->migrationToken;
    }

    public function toArray(): array
    {
        return [
            'id' => (string) $this->id,
            'user_id' => (string) $this->userId,
            'username' => $this->username,
            'email' => $this->email,
            'source_domain' => $this->sourceDomain,
            'target_domain' => $this->targetDomain,
            'backup_size' => $this->backupSize,
            'status' => $this->status,
            'direction' => $this->direction,
            'error_message' => $this->errorMessage,
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at' => $this->updatedAt->format(\DateTimeInterface::ATOM),
            'accepted_at' => $this->acceptedAt?->format(\DateTimeInterface::ATOM),
            'completed_at' => $this->completedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
