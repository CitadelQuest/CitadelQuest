<?php

namespace App\Service;

use App\Entity\SpiritMemoryJob;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

class SpiritMemoryJobService
{
    private ?User $user;

    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
        private readonly NotificationService $notificationService
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
            throw new \RuntimeException('User not authenticated');
        }
        return $this->userDatabaseManager->getDatabaseConnection($this->user);
    }

    /**
     * Create a new job
     */
    public function create(string $spiritId, string $type, array $payload): SpiritMemoryJob
    {
        $db = $this->getUserDb();
        
        $job = new SpiritMemoryJob($spiritId, $type, $payload);

        $db->executeStatement(
            'INSERT INTO spirit_memory_jobs 
            (id, spirit_id, type, status, payload, result, progress, total_steps, error, created_at, started_at, completed_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $job->getId(),
                $job->getSpiritId(),
                $job->getType(),
                $job->getStatus(),
                json_encode($job->getPayload()),
                null,
                0,
                0,
                null,
                $job->getCreatedAt()->format('Y-m-d H:i:s'),
                null,
                null
            ]
        );

        $this->logger->info('Spirit memory job created', [
            'jobId' => $job->getId(),
            'type' => $type,
            'spiritId' => $spiritId
        ]);

        return $job;
    }

    /**
     * Find a job by ID
     */
    public function findById(string $id): ?SpiritMemoryJob
    {
        $db = $this->getUserDb();
        
        $result = $db->executeQuery(
            'SELECT * FROM spirit_memory_jobs WHERE id = ?',
            [$id]
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        return SpiritMemoryJob::fromArray($result);
    }

    /**
     * Get pending jobs for processing
     */
    public function getPendingJobs(int $limit = 1): array
    {
        $db = $this->getUserDb();
        
        $results = $db->executeQuery(
            'SELECT * FROM spirit_memory_jobs 
             WHERE status = ? 
             ORDER BY created_at ASC 
             LIMIT ?',
            [SpiritMemoryJob::STATUS_PENDING, $limit]
        )->fetchAllAssociative();

        return array_map(fn($row) => SpiritMemoryJob::fromArray($row), $results);
    }

    /**
     * Get jobs that need processing (pending or in-progress)
     * This ensures jobs continue processing across multiple poll cycles
     */
    public function getJobsToProcess(int $limit = 1): array
    {
        $db = $this->getUserDb();
        
        $results = $db->executeQuery(
            'SELECT * FROM spirit_memory_jobs 
             WHERE status IN (?, ?) 
             ORDER BY 
                CASE WHEN status = ? THEN 0 ELSE 1 END,
                created_at ASC 
             LIMIT ?',
            [
                SpiritMemoryJob::STATUS_PROCESSING, 
                SpiritMemoryJob::STATUS_PENDING,
                SpiritMemoryJob::STATUS_PROCESSING,
                $limit
            ]
        )->fetchAllAssociative();

        return array_map(fn($row) => SpiritMemoryJob::fromArray($row), $results);
    }

    /**
     * Get jobs that are currently processing (for status display)
     */
    public function getActiveJobs(): array
    {
        $db = $this->getUserDb();
        
        $results = $db->executeQuery(
            'SELECT * FROM spirit_memory_jobs 
             WHERE status IN (?, ?) 
             ORDER BY created_at DESC',
            [SpiritMemoryJob::STATUS_PENDING, SpiritMemoryJob::STATUS_PROCESSING]
        )->fetchAllAssociative();

        return array_map(fn($row) => SpiritMemoryJob::fromArray($row), $results);
    }

    /**
     * Get recently completed jobs (for notifications)
     */
    public function getRecentlyCompletedJobs(string $since): array
    {
        $db = $this->getUserDb();
        
        $results = $db->executeQuery(
            'SELECT * FROM spirit_memory_jobs 
             WHERE status IN (?, ?) 
             AND completed_at > ?
             ORDER BY completed_at DESC',
            [SpiritMemoryJob::STATUS_COMPLETED, SpiritMemoryJob::STATUS_FAILED, $since]
        )->fetchAllAssociative();

        return array_map(fn($row) => SpiritMemoryJob::fromArray($row), $results);
    }

    /**
     * Update job status
     */
    public function update(SpiritMemoryJob $job): void
    {
        $db = $this->getUserDb();

        $db->executeStatement(
            'UPDATE spirit_memory_jobs 
             SET status = ?, payload = ?, result = ?, progress = ?, total_steps = ?, error = ?, started_at = ?, completed_at = ?
             WHERE id = ?',
            [
                $job->getStatus(),
                json_encode($job->getPayload()),
                $job->getResult() ? json_encode($job->getResult()) : null,
                $job->getProgress(),
                $job->getTotalSteps(),
                $job->getError(),
                $job->getStartedAt()?->format('Y-m-d H:i:s'),
                $job->getCompletedAt()?->format('Y-m-d H:i:s'),
                $job->getId()
            ]
        );
    }

    /**
     * Mark job as started
     */
    public function startJob(SpiritMemoryJob $job): void
    {
        $job->start();
        $this->update($job);
        
        $this->logger->info('Spirit memory job started', [
            'jobId' => $job->getId(),
            'type' => $job->getType()
        ]);
    }

    /**
     * Mark job as completed
     */
    public function completeJob(SpiritMemoryJob $job, ?array $result = null): void
    {
        $job->complete($result);
        $this->update($job);
        
        $this->logger->info('Spirit memory job completed', [
            'jobId' => $job->getId(),
            'type' => $job->getType()
        ]);
        
        // Create notification for user
        if ($this->user) {
            $title = $this->getJobCompletionTitle($job);
            $message = $result['message'] ?? $this->getJobCompletionMessage($job, $result);
            $this->notificationService->createNotification($this->user, $title, $message, 'success');
        }
    }

    /**
     * Mark job as failed
     */
    public function failJob(SpiritMemoryJob $job, string $error): void
    {
        $job->fail($error);
        $this->update($job);
        
        $this->logger->error('Spirit memory job failed', [
            'jobId' => $job->getId(),
            'type' => $job->getType(),
            'error' => $error
        ]);
        
        // Create notification for user
        if ($this->user) {
            $title = $this->getJobFailureTitle($job);
            $message = "Error: " . $error;
            $this->notificationService->createNotification($this->user, $title, $message, 'error');
        }
    }

    /**
     * Update job progress
     */
    public function updateProgress(SpiritMemoryJob $job, int $progress, ?int $totalSteps = null): void
    {
        $job->setProgress($progress);
        if ($totalSteps !== null) {
            $job->setTotalSteps($totalSteps);
        }
        $this->update($job);
    }

    /**
     * Clean up old completed jobs (older than 24 hours)
     */
    public function cleanupOldJobs(): int
    {
        $db = $this->getUserDb();
        
        $cutoff = (new \DateTime())->modify('-24 hours')->format('Y-m-d H:i:s');
        
        $result = $db->executeStatement(
            'DELETE FROM spirit_memory_jobs 
             WHERE status IN (?, ?) 
             AND completed_at < ?',
            [SpiritMemoryJob::STATUS_COMPLETED, SpiritMemoryJob::STATUS_FAILED, $cutoff]
        );

        return $result;
    }

    /**
     * Get notification title for completed job
     */
    private function getJobCompletionTitle(SpiritMemoryJob $job): string
    {
        return match ($job->getType()) {
            SpiritMemoryJob::TYPE_EXTRACT_RECURSIVE => '📚 Memory Extraction Complete',
            SpiritMemoryJob::TYPE_ANALYZE_RELATIONSHIPS => '🔗 Relationship Analysis Complete',
            default => '✅ Memory Job Complete'
        };
    }

    /**
     * Get notification message for completed job
     */
    private function getJobCompletionMessage(SpiritMemoryJob $job, ?array $result): string
    {
        return match ($job->getType()) {
            SpiritMemoryJob::TYPE_EXTRACT_RECURSIVE => sprintf(
                'Created %d memory nodes from document.',
                $result['total_memories'] ?? 0
            ),
            SpiritMemoryJob::TYPE_ANALYZE_RELATIONSHIPS => sprintf(
                'Analyzed %d nodes, created %d relationships.',
                $result['nodes_analyzed'] ?? 0,
                $result['relationships_created'] ?? 0
            ),
            default => 'Job completed successfully.'
        };
    }

    /**
     * Get notification title for failed job
     */
    private function getJobFailureTitle(SpiritMemoryJob $job): string
    {
        return match ($job->getType()) {
            SpiritMemoryJob::TYPE_EXTRACT_RECURSIVE => '❌ Memory Extraction Failed',
            SpiritMemoryJob::TYPE_ANALYZE_RELATIONSHIPS => '❌ Relationship Analysis Failed',
            default => '❌ Memory Job Failed'
        };
    }
}
