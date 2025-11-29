<?php

namespace App\Api\Controller;

use App\Service\UserDatabaseManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/database')]
class DatabaseApiController extends AbstractController
{
    private UserDatabaseManager $userDatabaseManager;

    public function __construct(UserDatabaseManager $userDatabaseManager)
    {
        $this->userDatabaseManager = $userDatabaseManager;
    }

    /**
     * Vacuum the user database to reclaim space
     * This is an async operation that should be called after data cleanup
     */
    #[Route('/vacuum', name: 'api_database_vacuum', methods: ['POST'])]
    public function vacuum(): JsonResponse
    {
        try {
            $user = $this->getUser();
            if (!$user) {
                return new JsonResponse(['error' => 'Unauthorized'], 401);
            }

            $db = $this->userDatabaseManager->getDatabaseConnection($user);
            
            // Get database size before vacuum
            $dbPath = $this->userDatabaseManager->getUserDatabaseFullPath($user);
            $sizeBefore = file_exists($dbPath) ? filesize($dbPath) : 0;
            
            // Execute VACUUM
            $startTime = microtime(true);
            $db->executeStatement('VACUUM;');
            $duration = round((microtime(true) - $startTime) * 1000, 2); // ms
            
            // Get database size after vacuum (clear stat cache first)
            clearstatcache(true, $dbPath);
            $sizeAfter = file_exists($dbPath) ? filesize($dbPath) : 0;
            $spaceSaved = $sizeBefore - $sizeAfter;
            
            return new JsonResponse([
                'success' => true,
                'message' => 'Database vacuumed successfully',
                'stats' => [
                    'duration_ms' => $duration,
                    'size_before' => $this->formatBytes($sizeBefore),
                    'size_after' => $this->formatBytes($sizeAfter),
                    'space_saved' => $this->formatBytes($spaceSaved),
                    'space_saved_bytes' => $spaceSaved
                ]
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to vacuum database: ' . $e->getMessage()
            ], 500);
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

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) return '0 B';
        
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes, 1024));
        
        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }
}
