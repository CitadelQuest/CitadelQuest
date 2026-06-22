<?php

namespace App\Service;

use App\Entity\User;
use App\Service\UserDatabaseManager;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Stores AI Gateway webhook callback results in the user's per-user SQLite database.
 *
 * When the CQ AI Gateway finishes a background job, it POSTs the result to the
 * CitadelQuest webhook endpoint. This service persists that result so the
 * waiting CQAIGateway::sendRequestViaJob() loop can read it from local DB
 * instead of polling the gateway over HTTP.
 */
class AiWebhookService
{
    private ?User $user = null;

    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly Security $security
    ) {
        $this->user = $security->getUser();
    }

    public function setUser(User $user): void
    {
        $this->user = $user;
    }

    private function getUserDb()
    {
        if (!$this->user) {
            throw new \Exception('User not found');
        }
        return $this->userDatabaseManager->getDatabaseConnection($this->user);
    }

    /**
     * Store a webhook result received from the CQ AI Gateway.
     */
    public function storeResult(string $jobId, string $status, ?array $response, ?string $error): void
    {
        $db = $this->getUserDb();

        $db->executeStatement(
            'INSERT OR REPLACE INTO ai_webhook_result (job_id, status, response_payload, error, created_at)
             VALUES (?, ?, ?, ?, ?)',
            [
                $jobId,
                $status,
                $response !== null ? json_encode($response) : null,
                $error,
                (new \DateTime())->format('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Get a stored webhook result by job ID.
     * Returns ['status' => ..., 'response' => ..., 'error' => ...] or null if not found.
     */
    public function getResult(string $jobId): ?array
    {
        $db = $this->getUserDb();

        $row = $db->executeQuery(
            'SELECT * FROM ai_webhook_result WHERE job_id = ?',
            [$jobId]
        )->fetchAssociative();

        if (!$row) {
            return null;
        }

        return [
            'status' => $row['status'],
            'response' => $row['response_payload'] !== null ? json_decode($row['response_payload'], true) : null,
            'error' => $row['error'] ?? null,
        ];
    }

    /**
     * Remove a stored webhook result (cleanup after consumption).
     */
    public function deleteResult(string $jobId): void
    {
        $db = $this->getUserDb();

        $db->executeStatement('DELETE FROM ai_webhook_result WHERE job_id = ?', [$jobId]);
    }
}
