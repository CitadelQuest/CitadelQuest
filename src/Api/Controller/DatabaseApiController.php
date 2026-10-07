<?php

namespace App\Api\Controller;

use App\Entity\User;
use App\Service\BackgroundWorkerSpawner;
use App\Service\MaintenanceJobService;
use App\Service\UserDatabaseManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/database')]
#[IsGranted('ROLE_USER')]
class DatabaseApiController extends AbstractController
{
    public function __construct(
        private UserDatabaseManager $userDatabaseManager,
        private MaintenanceJobService $maintenanceJobService,
        private BackgroundWorkerSpawner $spawner
    ) {
    }

    /**
     * Start a background job that optimizes (trims payloads, drops orphans, VACUUM)
     * the user database to reclaim space.
     *
     * The work is done by a detached CLI worker (app:db-optimize) so the request
     * returns immediately and never hits Cloudflare's 100s proxy read timeout
     * (HTTP 524) on large databases. The browser polls /api/database/vacuum/status/{jobId}.
     */
    #[Route('/vacuum', name: 'api_database_vacuum', methods: ['POST'])]
    public function vacuum(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        try {
            $this->maintenanceJobService->failStaleJobs($user);

            // One maintenance job per user at a time (backup and optimize both touch
            // the same database file).
            $active = $this->maintenanceJobService->findActive(null, $user);
            if ($active) {
                if ($active['type'] === MaintenanceJobService::TYPE_DB_OPTIMIZE) {
                    return new JsonResponse([
                        'alreadyRunning' => true,
                        'jobId' => $active['id'],
                        'status' => $active['status'],
                    ], 409);
                }
                return new JsonResponse([
                    'error' => 'Another maintenance task is in progress. Please try again shortly.',
                ], 409);
            }

            $jobId = $this->maintenanceJobService->create(MaintenanceJobService::TYPE_DB_OPTIMIZE, [], $user);

            try {
                $this->spawner->spawn('app:db-optimize', [(string) $user->getId(), $jobId], 'db-optimize-worker.log');
            } catch (\Throwable $spawnError) {
                // Could not start the background worker — fail the job now so the UI
                // shows a clear error instead of polling a job that will never run.
                $this->maintenanceJobService->markFailed($jobId, 'Could not start background worker: ' . $spawnError->getMessage(), $user);
                return new JsonResponse([
                    'error' => 'Could not start background processing. ' . $spawnError->getMessage(),
                ], 500);
            }

            return new JsonResponse([
                'success' => true,
                'jobId' => $jobId,
                'status' => MaintenanceJobService::STATUS_PENDING,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to start database optimization: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Poll the status of a background database optimization job.
     * Lightweight + fast — always returns well under Cloudflare's 100s window.
     */
    #[Route('/vacuum/status/{jobId}', name: 'api_database_vacuum_status', methods: ['GET'])]
    public function vacuumStatus(string $jobId): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        try {
            $job = $this->maintenanceJobService->find($jobId, $user);
            if (!$job || $job['type'] !== MaintenanceJobService::TYPE_DB_OPTIMIZE) {
                return new JsonResponse(['error' => 'Optimize job not found'], 404);
            }

            $done = in_array($job['status'], [
                MaintenanceJobService::STATUS_COMPLETED,
                MaintenanceJobService::STATUS_FAILED,
            ], true);

            return new JsonResponse([
                'success' => true,
                'status' => $job['status'],
                'done' => $done,
                'error' => $job['error'] ?? null,
                'stats' => $job['result'] ?: null,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get database statistics
     */
    #[Route('/stats', name: 'api_database_stats', methods: ['GET'])]
    public function stats(): JsonResponse
    {
        try {
            $user = $this->getUser();
            if (!$user) {
                return new JsonResponse(['error' => 'Unauthorized'], 401);
            }

            $db = $this->userDatabaseManager->getDatabaseConnection($user);
            $dbPath = $this->userDatabaseManager->getUserDatabaseFullPath($user);
            
            // Get database file size
            $fileSize = file_exists($dbPath) ? filesize($dbPath) : 0;
            
            // Get page count and page size
            $pageCount = $db->fetchOne('PRAGMA page_count;');
            $pageSize = $db->fetchOne('PRAGMA page_size;');
            $freePages = $db->fetchOne('PRAGMA freelist_count;');
            
            // Calculate fragmentation
            $usedPages = $pageCount - $freePages;
            $fragmentation = $pageCount > 0 ? round(($freePages / $pageCount) * 100, 2) : 0;
            
            return new JsonResponse([
                'success' => true,
                'stats' => [
                    'file_size' => $this->formatBytes($fileSize),
                    'file_size_bytes' => $fileSize,
                    'page_count' => $pageCount,
                    'page_size' => $pageSize,
                    'free_pages' => $freePages,
                    'used_pages' => $usedPages,
                    'fragmentation_percent' => $fragmentation,
                    'potential_savings' => $this->formatBytes($freePages * $pageSize)
                ]
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to get database stats: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download the raw user SQLite database file
     */
    #[Route('/download', name: 'api_database_download', methods: ['GET'])]
    public function download(): BinaryFileResponse|JsonResponse
    {
        try {
            $user = $this->getUser();
            if (!$user) {
                return new JsonResponse(['error' => 'Unauthorized'], 401);
            }

            $dbPath = $this->userDatabaseManager->getUserDatabaseFullPath($user);
            if (!file_exists($dbPath)) {
                throw new \RuntimeException('Database file not found');
            }

            $response = new BinaryFileResponse($dbPath);
            $response->headers->set('Content-Type', 'application/vnd.sqlite3');
            $response->setContentDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                basename($dbPath)
            );

            return $response;
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to download database: ' . $e->getMessage()
            ], 500);
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) return '0 B';
        
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes, 1024));
        
        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }
}
