<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\CqContact;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * ContactUpdateService
 * 
 * Handles notifying CQ Contacts about user domain changes after migration.
 * Includes retry queue for failed notifications.
 */
class ContactUpdateService
{
    public function __construct(
        private UserDatabaseManager $userDatabaseManager,
        private CqContactService $cqContactService,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {}

    /**
     * Notify all contacts about user's domain change
     * 
     * @param User $user The migrated user
     * @param string $oldDomain The old domain
     * @param string $newDomain The new domain
     * @return array Results of notification attempts
     */
    public function notifyAllContacts(User $user, string $oldDomain, string $newDomain): array
    {
        $this->cqContactService->setUser($user);
        $contacts = $this->cqContactService->getActiveContacts();
        
        $results = [
            'success' => 0,
            'failed' => 0,
            'queued' => 0,
        ];

        foreach ($contacts as $contact) {
            try {
                $success = $this->notifyContact($contact, $user, $oldDomain, $newDomain);
                
                if ($success) {
                    $results['success']++;
                } else {
                    // Add to retry queue
                    $this->addToRetryQueue($user, $contact, $oldDomain, $newDomain);
                    $results['queued']++;
                }
            } catch (\Exception $e) {
                $this->logger->error('Failed to notify contact about migration', [
                    'contact_id' => $contact->getId(),
                    'contact_domain' => $contact->getCqContactDomain(),
                    'error' => $e->getMessage(),
                ]);
                
                // Add to retry queue
                $this->addToRetryQueue($user, $contact, $oldDomain, $newDomain, $e->getMessage());
                $results['failed']++;
            }
        }

        $this->logger->info('Contact notification completed', [
            'user_id' => (string) $user->getId(),
            'results' => $results,
        ]);

        return $results;
    }

    /**
     * Notify a single contact about domain change
     */
    public function notifyContact(CqContact $contact, User $user, string $oldDomain, string $newDomain): bool
    {
        $url = 'https://' . $contact->getCqContactDomain() 
            . '/' . $contact->getCqContactUsername() 
            . '/api/federation/contact-update';

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $contact->getCqContactApiKey(),
                ],
                'json' => [
                    'user_id' => (string) $user->getId(),
                    'old_domain' => $oldDomain,
                    'new_domain' => $newDomain,
                    'username' => $user->getUsername(),
                ],
                'timeout' => 30,
            ]);

            $data = $response->toArray();
            
            if ($data['success'] ?? false) {
                $this->logger->info('Contact notified successfully', [
                    'contact_domain' => $contact->getCqContactDomain(),
                ]);
                return true;
            }

            $this->logger->warning('Contact notification returned failure', [
                'contact_domain' => $contact->getCqContactDomain(),
                'response' => $data,
            ]);
            return false;

        } catch (\Exception $e) {
            $this->logger->error('Contact notification request failed', [
                'contact_domain' => $contact->getCqContactDomain(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Add failed notification to retry queue
     */
    public function addToRetryQueue(
        User $user, 
        CqContact $contact, 
        string $oldDomain, 
        string $newDomain, 
        ?string $errorMessage = null
    ): void {
        $userDb = $this->userDatabaseManager->getDatabaseConnection($user);

        $id = bin2hex(random_bytes(18));
        $now = new \DateTime();
        $nextAttempt = (clone $now)->modify('+5 minutes');

        $userDb->executeStatement(
            'INSERT INTO contact_update_queue 
                (id, contact_id, contact_domain, old_domain, new_domain, attempts, last_attempt_at, next_attempt_at, status, error_message, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id,
                $contact->getId(),
                $contact->getCqContactDomain(),
                $oldDomain,
                $newDomain,
                1,
                $now->format('Y-m-d H:i:s'),
                $nextAttempt->format('Y-m-d H:i:s'),
                'pending',
                $errorMessage,
                $now->format('Y-m-d H:i:s'),
            ]
        );

        $this->logger->info('Added contact to retry queue', [
            'contact_id' => $contact->getId(),
            'next_attempt' => $nextAttempt->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Process retry queue for a user
     */
    public function processRetryQueue(User $user): array
    {
        $userDb = $this->userDatabaseManager->getDatabaseConnection($user);
        $this->cqContactService->setUser($user);

        $now = new \DateTime();
        
        // Get pending items that are ready for retry
        $items = $userDb->executeQuery(
            "SELECT * FROM contact_update_queue 
             WHERE status = 'pending' 
             AND next_attempt_at <= ? 
             AND attempts < max_attempts
             ORDER BY next_attempt_at ASC",
            [$now->format('Y-m-d H:i:s')]
        )->fetchAllAssociative();

        $results = [
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
        ];

        foreach ($items as $item) {
            $results['processed']++;
            
            $contact = $this->cqContactService->findById($item['contact_id']);
            if (!$contact) {
                // Contact no longer exists, mark as failed
                $this->updateQueueItemStatus($userDb, $item['id'], 'failed', 'Contact not found');
                $results['failed']++;
                continue;
            }

            try {
                $success = $this->notifyContact($contact, $user, $item['old_domain'], $item['new_domain']);
                
                if ($success) {
                    $this->updateQueueItemStatus($userDb, $item['id'], 'completed');
                    $results['success']++;
                } else {
                    $this->incrementRetryAttempt($userDb, $item);
                    $results['failed']++;
                }
            } catch (\Exception $e) {
                $this->incrementRetryAttempt($userDb, $item, $e->getMessage());
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Get pending queue items for a user
     */
    public function getPendingQueueItems(User $user): array
    {
        $userDb = $this->userDatabaseManager->getDatabaseConnection($user);
        
        return $userDb->executeQuery(
            "SELECT * FROM contact_update_queue WHERE status = 'pending' ORDER BY created_at DESC"
        )->fetchAllAssociative();
    }

    /**
     * Manually retry a specific queue item
     */
    public function retryQueueItem(User $user, string $itemId): bool
    {
        $userDb = $this->userDatabaseManager->getDatabaseConnection($user);
        $this->cqContactService->setUser($user);

        $item = $userDb->executeQuery(
            'SELECT * FROM contact_update_queue WHERE id = ?',
            [$itemId]
        )->fetchAssociative();

        if (!$item) {
            return false;
        }

        $contact = $this->cqContactService->findById($item['contact_id']);
        if (!$contact) {
            $this->updateQueueItemStatus($userDb, $itemId, 'failed', 'Contact not found');
            return false;
        }

        try {
            $success = $this->notifyContact($contact, $user, $item['old_domain'], $item['new_domain']);
            
            if ($success) {
                $this->updateQueueItemStatus($userDb, $itemId, 'completed');
                return true;
            }
            
            $this->incrementRetryAttempt($userDb, $item);
            return false;
            
        } catch (\Exception $e) {
            $this->incrementRetryAttempt($userDb, $item, $e->getMessage());
            return false;
        }
    }

    /**
     * Update queue item status
     */
    private function updateQueueItemStatus($userDb, string $id, string $status, ?string $errorMessage = null): void
    {
        $userDb->executeStatement(
            'UPDATE contact_update_queue SET status = ?, error_message = ? WHERE id = ?',
            [$status, $errorMessage, $id]
        );
    }

    /**
     * Increment retry attempt with exponential backoff
     */
    private function incrementRetryAttempt($userDb, array $item, ?string $errorMessage = null): void
    {
        $attempts = ($item['attempts'] ?? 0) + 1;
        $maxAttempts = $item['max_attempts'] ?? 5;
        
        $now = new \DateTime();
        
        // Exponential backoff: 5min, 15min, 45min, 2h15min, 6h45min
        $delayMinutes = 5 * pow(3, $attempts - 1);
        $nextAttempt = (clone $now)->modify("+{$delayMinutes} minutes");

        $status = $attempts >= $maxAttempts ? 'failed' : 'pending';

        $userDb->executeStatement(
            'UPDATE contact_update_queue 
             SET attempts = ?, last_attempt_at = ?, next_attempt_at = ?, status = ?, error_message = ?
             WHERE id = ?',
            [
                $attempts,
                $now->format('Y-m-d H:i:s'),
                $nextAttempt->format('Y-m-d H:i:s'),
                $status,
                $errorMessage ?? $item['error_message'],
                $item['id'],
            ]
        );
    }
}
