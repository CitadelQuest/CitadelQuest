<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use App\Validator\UniqueUsernameCaseInsensitive;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[UniqueEntity(fields: ['email'], message: 'auth.register.error.email_already_used')]
#[UniqueUsernameCaseInsensitive]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private ?Uuid $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $username = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $databasePath = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $requirePasswordChange = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $migratedTo = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $migratedAt = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $migrationStatus = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->username;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function getDatabasePath(): ?string
    {
        return basename($this->databasePath);
    }

    public function setDatabasePath(string $databasePath): static
    {
        $this->databasePath = $databasePath;
        return $this;
    }

    public function isRequirePasswordChange(): bool
    {
        return $this->requirePasswordChange;
    }

    public function setRequirePasswordChange(bool $requirePasswordChange): static
    {
        $this->requirePasswordChange = $requirePasswordChange;
        return $this;
    }

    #[Deprecated]
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
    }

    public function getMigratedTo(): ?string
    {
        return $this->migratedTo;
    }

    public function setMigratedTo(?string $migratedTo): static
    {
        $this->migratedTo = $migratedTo;
        return $this;
    }

    public function getMigratedAt(): ?\DateTimeInterface
    {
        return $this->migratedAt;
    }

    public function setMigratedAt(?\DateTimeInterface $migratedAt): static
    {
        $this->migratedAt = $migratedAt;
        return $this;
    }

    public function getMigrationStatus(): ?string
    {
        return $this->migrationStatus;
    }

    public function setMigrationStatus(?string $migrationStatus): static
    {
        $this->migrationStatus = $migrationStatus;
        return $this;
    }

    public function isMigrated(): bool
    {
        return $this->migrationStatus === 'migrated';
    }

    public function hasAdminRole(): bool
    {
        return in_array('ROLE_ADMIN', $this->getRoles());
    }
}
