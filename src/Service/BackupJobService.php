<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * Backup Job Service
 *
 * Persists background backup jobs in the user database. A job represents one
 * .citadel archive creation that is executed by a detached CLI worker
 * (app:backup-create) instead of being held open on a single HTTP request.
 * This prevents Cloudflare 524 timeouts on large backups.
 *
 * The user is resolved lazily (at query time) so this service works both in the
 * normal authenticated web context and inside the CLI worker (after a token is
 * set on the security token storage).
 */
class BackupJobService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    // A pending job whose worker never started, or a processing job whose worker
    // died, would otherwise block all future backups forever. Anything older than
    // these thresholds is treated as stale and failed.
    private const PENDING_STALE_SECONDS = 600;      // 10 min
    private const PROCESSING_STALE_SECONDS = 21600; // 6 h

    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly Security $security
    ) {
    }

    private function getUserDb()
    {
        /** @var User|null $user */
        $user = $this->security->getUser();
        if (!$user) {
            throw new \RuntimeException('BackupJobService: User not authenticated');
        }
        return $this->userDatabaseManager->getDatabaseConnection($user);
    }

    /**
     * Create a new pending backup job.
     *
     * @return string The new job id
     */
    public function create(): string
    {
        $db = $this->getUserDb();
        $id = Uuid::v4()->toRfc4122();

        $db->executeStatement(
            'INSERT INTO backup_job (id, status, created_at) VALUES (?, ?, ?)',
            [$id, self::STATUS_PENDING, (new \DateTime())->format('Y-m-d H:i:s')]
        );

        return $id;
    }

    public function find(string $id): ?array
    {
        $db = $this->getUserDb();
        $row = $db->executeQuery(
            'SELECT * FROM backup_job WHERE id = ?',
            [$id]
        )->fetchAssociative();

        return $row ?: null;
    }

    /**
     * Most recent still-running job (pending/processing) for the current user.
     * Each user has a private database, so this is inherently per-user.
     */
    public function findActive(): ?array
    {
        $db = $this->getUserDb();
        $row = $db->executeQuery(
            'SELECT * FROM backup_job WHERE status IN (?, ?) ORDER BY created_at DESC LIMIT 1',
            [self::STATUS_PENDING, self::STATUS_PROCESSING]
        )->fetchAssociative();

        return $row ?: null;
    }

    public function markProcessing(string $id): void
    {
        $this->getUserDb()->executeStatement(
            'UPDATE backup_job SET status = ?, started_at = ? WHERE id = ?',
            [self::STATUS_PROCESSING, (new \DateTime())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function markCompleted(string $id, string $filename, int $size): void
    {
        $this->getUserDb()->executeStatement(
            'UPDATE backup_job SET status = ?, filename = ?, size = ?, completed_at = ? WHERE id = ? AND status = ?',
            [
                self::STATUS_COMPLETED,
                $filename,
                $size,
                (new \DateTime())->format('Y-m-d H:i:s'),
                $id,
                self::STATUS_PROCESSING,
            ]
        );
    }

    public function markFailed(string $id, string $error): void
    {
        $this->getUserDb()->executeStatement(
            'UPDATE backup_job SET status = ?, error = ?, completed_at = ? WHERE id = ?',
            [self::STATUS_FAILED, $error, (new \DateTime())->format('Y-m-d H:i:s'), $id]
        );
    }

    /**
     * Fail stale jobs so a crashed worker cannot block future backups forever.
     */
    public function failStaleJobs(): void
    {
        $db = $this->getUserDb();
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        $pendingCutoff = (new \DateTime())
            ->setTimestamp(time() - self::PENDING_STALE_SECONDS)
            ->format('Y-m-d H:i:s');
        $db->executeStatement(
            'UPDATE backup_job SET status = ?, error = ?, completed_at = ? WHERE status = ? AND created_at < ?',
            [self::STATUS_FAILED, 'Backup worker did not start', $now, self::STATUS_PENDING, $pendingCutoff]
        );

        $processingCutoff = (new \DateTime())
            ->setTimestamp(time() - self::PROCESSING_STALE_SECONDS)
            ->format('Y-m-d H:i:s');
        $db->executeStatement(
            'UPDATE backup_job SET status = ?, error = ?, completed_at = ? WHERE status = ? AND started_at < ?',
            [self::STATUS_FAILED, 'Backup worker timed out', $now, self::STATUS_PROCESSING, $processingCutoff]
        );
    }
}
