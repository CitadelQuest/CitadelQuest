<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * Maintenance Job Service
 *
 * Persists background maintenance jobs in the user database. A job represents one
 * long-running task (backup creation, database optimization) executed by a detached
 * CLI worker instead of being held open on a single HTTP request. This prevents
 * Cloudflare 524 timeouts on large databases.
 *
 * Jobs live in the database they operate on. For admin-triggered jobs the caller
 * passes the target user explicitly; otherwise the current user is used. The user
 * is resolved lazily (at query time) so this service works both in the normal
 * authenticated web context and inside the CLI worker (after a token is set).
 */
class MaintenanceJobService
{
    public const TYPE_BACKUP = 'backup';
    public const TYPE_DB_OPTIMIZE = 'db_optimize';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    // A pending job whose worker never started, or a processing job whose worker
    // died, would otherwise block all future jobs forever. Anything older than
    // these thresholds is treated as stale and failed.
    private const PENDING_STALE_SECONDS = 600;      // 10 min
    private const PROCESSING_STALE_SECONDS = 21600; // 6 h

    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly Security $security
    ) {
    }

    private function getUserDb(?User $user = null)
    {
        $user ??= $this->security->getUser();
        if (!$user instanceof User) {
            throw new \RuntimeException('MaintenanceJobService: User not authenticated');
        }
        return $this->userDatabaseManager->getDatabaseConnection($user);
    }

    /**
     * Create a new pending job.
     *
     * @return string The new job id
     */
    public function create(string $type, array $payload = [], ?User $user = null): string
    {
        $db = $this->getUserDb($user);
        $id = Uuid::v4()->toRfc4122();

        $db->executeStatement(
            'INSERT INTO maintenance_job (id, type, status, payload, created_at) VALUES (?, ?, ?, ?, ?)',
            [
                $id,
                $type,
                self::STATUS_PENDING,
                $payload ? json_encode($payload) : null,
                (new \DateTime())->format('Y-m-d H:i:s'),
            ]
        );

        return $id;
    }

    public function find(string $id, ?User $user = null): ?array
    {
        $row = $this->getUserDb($user)->executeQuery(
            'SELECT * FROM maintenance_job WHERE id = ?',
            [$id]
        )->fetchAssociative();

        return $row ? $this->decode($row) : null;
    }

    /**
     * Most recent still-running job, optionally filtered by type (null = any type).
     */
    public function findActive(?string $type = null, ?User $user = null): ?array
    {
        $db = $this->getUserDb($user);

        if ($type === null) {
            $row = $db->executeQuery(
                'SELECT * FROM maintenance_job WHERE status IN (?, ?) ORDER BY created_at DESC LIMIT 1',
                [self::STATUS_PENDING, self::STATUS_PROCESSING]
            )->fetchAssociative();
        } else {
            $row = $db->executeQuery(
                'SELECT * FROM maintenance_job WHERE type = ? AND status IN (?, ?) ORDER BY created_at DESC LIMIT 1',
                [$type, self::STATUS_PENDING, self::STATUS_PROCESSING]
            )->fetchAssociative();
        }

        return $row ? $this->decode($row) : null;
    }

    public function markProcessing(string $id, ?User $user = null): void
    {
        $this->getUserDb($user)->executeStatement(
            'UPDATE maintenance_job SET status = ?, started_at = ? WHERE id = ?',
            [self::STATUS_PROCESSING, (new \DateTime())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function markCompleted(string $id, array $result, ?User $user = null): void
    {
        $this->getUserDb($user)->executeStatement(
            'UPDATE maintenance_job SET status = ?, result = ?, completed_at = ? WHERE id = ? AND status = ?',
            [
                self::STATUS_COMPLETED,
                json_encode($result),
                (new \DateTime())->format('Y-m-d H:i:s'),
                $id,
                self::STATUS_PROCESSING,
            ]
        );
    }

    public function markFailed(string $id, string $error, ?User $user = null): void
    {
        $this->getUserDb($user)->executeStatement(
            'UPDATE maintenance_job SET status = ?, error = ?, completed_at = ? WHERE id = ?',
            [self::STATUS_FAILED, $error, (new \DateTime())->format('Y-m-d H:i:s'), $id]
        );
    }

    /**
     * Fail stale jobs so a crashed worker cannot block future jobs forever.
     */
    public function failStaleJobs(?User $user = null): void
    {
        $db = $this->getUserDb($user);
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        $pendingCutoff = (new \DateTime())
            ->setTimestamp(time() - self::PENDING_STALE_SECONDS)
            ->format('Y-m-d H:i:s');
        $db->executeStatement(
            'UPDATE maintenance_job SET status = ?, error = ?, completed_at = ? WHERE status = ? AND created_at < ?',
            [self::STATUS_FAILED, 'Worker did not start', $now, self::STATUS_PENDING, $pendingCutoff]
        );

        $processingCutoff = (new \DateTime())
            ->setTimestamp(time() - self::PROCESSING_STALE_SECONDS)
            ->format('Y-m-d H:i:s');
        $db->executeStatement(
            'UPDATE maintenance_job SET status = ?, error = ?, completed_at = ? WHERE status = ? AND started_at < ?',
            [self::STATUS_FAILED, 'Worker timed out', $now, self::STATUS_PROCESSING, $processingCutoff]
        );
    }

    private function decode(array $row): array
    {
        $row['payload'] = !empty($row['payload']) ? (json_decode($row['payload'], true) ?: []) : [];
        $row['result'] = !empty($row['result']) ? (json_decode($row['result'], true) ?: []) : [];
        return $row;
    }
}
