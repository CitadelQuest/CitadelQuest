<?php

namespace App\Service;

use App\Entity\CqContact;
use App\Entity\User;
use App\Service\UserDatabaseManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CqContactService
{
    private ?User $user;

    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly Security $security,
        private readonly HttpClientInterface $httpClient
    ) {
        $this->user = $security->getUser();
    }

    public function setUser(User $user): void
    {
        $this->user = $user;
    }
    
    /**
     * Get a fresh database connection for the current user
     */
    private function getUserDb()
    {
        if (!$this->user) {
            throw new \Exception('User not found');
        }
        return $this->userDatabaseManager->getDatabaseConnection($this->user);
    }

    public function createContact(
        string $cqContactUrl,
        string $cqContactDomain,
        string $cqContactUsername,
        ?string $cqContactId = null,
        ?string $cqContactApiKey = null,
        ?string $friendRequestStatus = null,
        ?string $description = null,
        ?string $profilePhotoProjectFileId = null,
        bool $isActive = true
    ): CqContact {
        if (!$cqContactApiKey) {
            $cqContactApiKey = $this->generateCqContactApiKey();
        }
        
        $contact = new CqContact(
            $cqContactUrl,
            $cqContactDomain,
            $cqContactUsername,
            $cqContactId,
            $cqContactApiKey,
            $friendRequestStatus,
            $description,
            $profilePhotoProjectFileId,
            $isActive
        );

        // Check for existing contact
        $existingContact = $this->findByUrl($cqContactUrl);
        if ($existingContact) {
            throw new \Exception('Contact already exists');
        }

        // Store in user's database
        $userDb = $this->getUserDb();
        $userDb->executeStatement(
            'INSERT INTO cq_contact (
                id, cq_contact_url, cq_contact_domain, cq_contact_username, cq_contact_id,
                cq_contact_api_key, friend_request_status, description, profile_photo_project_file_id, is_active,
                created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $contact->getId(),
                $contact->getCqContactUrl(),
                $contact->getCqContactDomain(),
                $contact->getCqContactUsername(),
                $contact->getCqContactId(),
                $contact->getCqContactApiKey(),
                $contact->getFriendRequestStatus(),
                $contact->getDescription(),
                $contact->getProfilePhotoProjectFileId(),
                $contact->isActive() ? 1 : 0,
                $contact->getCreatedAt()->format('Y-m-d H:i:s'),
                $contact->getUpdatedAt()->format('Y-m-d H:i:s')
            ]
        );

        return $contact;
    }

    public function findById(?string $id): ?CqContact
    {
        if ($id === null) {
            return null;
        }
        
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT * FROM cq_contact WHERE id = ?',
            [$id]
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        return CqContact::fromArray($result);
    }

    public function findByUrl(string $cqContactUrl): ?CqContact
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT * FROM cq_contact WHERE cq_contact_url = ?',
            [$cqContactUrl]
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        return CqContact::fromArray($result);
    }

    public function findByUrlAndApiKey(string $cqContactUrl, string $cqContactApiKey): ?CqContact
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT * FROM cq_contact WHERE cq_contact_url = ? AND cq_contact_api_key = ?',
            [$cqContactUrl, $cqContactApiKey]
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        return CqContact::fromArray($result);
    }

    public function findByApiKey(string $apiKey): ?CqContact
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT * FROM cq_contact WHERE cq_contact_api_key = ? AND is_active = 1',
            [$apiKey]
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        return CqContact::fromArray($result);
    }

    public function findByDomain(string $domain): array
    {
        $userDb = $this->getUserDb();
        $results = $userDb->executeQuery(
            'SELECT * FROM cq_contact WHERE cq_contact_domain = ? ORDER BY created_at DESC',
            [$domain]
        )->fetchAllAssociative();

        return array_map(fn($data) => CqContact::fromArray($data), $results);
    }

    public function findByDomainAndApiKey(string $domain, string $apiKey): ?CqContact
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT * FROM cq_contact WHERE cq_contact_domain = ? AND cq_contact_api_key = ?',
            [$domain, $apiKey]
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        return CqContact::fromArray($result);
    }

    /**
     * Get all active contacts (for migration target selection)
     */
    public function getActiveContacts(): array
    {
        $userDb = $this->getUserDb();
        $results = $userDb->executeQuery(
            "SELECT * FROM cq_contact WHERE is_active = 1 AND friend_request_status = 'ACCEPTED' ORDER BY cq_contact_username ASC"
        )->fetchAllAssociative();

        return array_map(fn($data) => CqContact::fromArray($data), $results);
    }

    public function findAll(bool $activeOnly = true): array
    {
        $userDb = $this->getUserDb();
        $sql = 'SELECT * FROM cq_contact';
        $params = [];
        
        if ($activeOnly) {
            $sql .= ' WHERE is_active = ?';
            $params[] = 1;
        }
        
        $sql .= ' ORDER BY created_at DESC';
        
        $results = $userDb->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(fn($data) => CqContact::fromArray($data), $results);
    }

    public function updateContact(CqContact $contact): bool
    {
        $contact->updateUpdatedAt();
        
        $userDb = $this->getUserDb();
        $result = $userDb->executeStatement(
            'UPDATE cq_contact SET 
                cq_contact_url = ?, cq_contact_domain = ?, cq_contact_username = ?,
                cq_contact_id = ?, cq_contact_api_key = ?, friend_request_status = ?, description = ?,
                profile_photo_project_file_id = ?, is_active = ?, updated_at = ?
             WHERE id = ?',
            [
                $contact->getCqContactUrl(),
                $contact->getCqContactDomain(),
                $contact->getCqContactUsername(),
                $contact->getCqContactId(),
                $contact->getCqContactApiKey(),
                $contact->getFriendRequestStatus(),
                $contact->getDescription(),
                $contact->getProfilePhotoProjectFileId(),
                $contact->isActive() ? 1 : 0,
                $contact->getUpdatedAt()->format('Y-m-d H:i:s'),
                $contact->getId()
            ]
        );

        return $result > 0;
    }

    public function deleteContact(string $id): bool
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeStatement(
            'DELETE FROM cq_contact WHERE id = ?',
            [$id]
        );

        // Vacuum the database
        //$userDb->executeStatement('VACUUM;');

        return $result > 0;
    }

    public function activateContact(string $id): bool
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeStatement(
            'UPDATE cq_contact SET is_active = 1, updated_at = ? WHERE id = ?',
            [date('Y-m-d H:i:s'), $id]
        );

        return $result > 0;
    }

    public function deactivateContact(string $id): bool
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeStatement(
            'UPDATE cq_contact SET is_active = 0, updated_at = ? WHERE id = ?',
            [date('Y-m-d H:i:s'), $id]
        );

        return $result > 0;
    }

    public function generateCqContactApiKey(): string
    {
        return 'CQ-CONTACT-' . strtoupper(bin2hex(random_bytes(12))) . '-' . strtoupper(bin2hex(random_bytes(12)));
    }

    /**
     * Count pending friend requests (RECEIVED status)
     */
    public function countPendingFriendRequests(): int
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            "SELECT COUNT(*) as count FROM cq_contact WHERE friend_request_status = 'RECEIVED'",
            []
        )->fetchAssociative();

        return (int) ($result['count'] ?? 0);
    }

}
