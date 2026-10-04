<?php

namespace App\Service;

use App\Entity\User;

/**
 * Optimizes a user's SQLite database: trims stored AI request/response
 * payloads, removes orphaned spirit conversation messages and runs VACUUM.
 *
 * Shared by the user-facing Settings -> Database action and the admin
 * Users action, so an admin can reclaim space without the user doing it.
 */
class UserDatabaseOptimizer
{
    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly SpiritConversationService $spiritConversationService
    ) {
    }

    /**
     * @return array{duration_ms: float, size_before: string, size_after: string, space_saved: string, space_saved_bytes: int, orphaned_messages_deleted: int}
     */
    public function optimize(User $user): array
    {
        $db = $this->userDatabaseManager->getDatabaseConnection($user);
        $dbPath = $this->userDatabaseManager->getUserDatabaseFullPath($user);
        $sizeBefore = file_exists($dbPath) ? filesize($dbPath) : 0;

        // Remove messages from all spirit conversations (2 bulk queries)
        $this->spiritConversationService->setMessagesRemovedFromAiServiceRequestAndResponse(null, $user);

        // Remove orphaned spirit_conversation_message records (from previously deleted conversations)
        $orphanedMessagesDeleted = $this->spiritConversationService->deleteOrphanedMessages($user);

        // Execute VACUUM
        $startTime = microtime(true);
        $db->executeStatement('VACUUM;');
        $duration = round((microtime(true) - $startTime) * 1000, 2); // ms

        // Get database size after vacuum (clear stat cache first)
        clearstatcache(true, $dbPath);
        $sizeAfter = file_exists($dbPath) ? filesize($dbPath) : 0;
        $spaceSaved = $sizeBefore - $sizeAfter;

        return [
            'duration_ms' => $duration,
            'size_before' => $this->formatBytes($sizeBefore),
            'size_after' => $this->formatBytes($sizeAfter),
            'space_saved' => $this->formatBytes($spaceSaved),
            'space_saved_bytes' => $spaceSaved,
            'orphaned_messages_deleted' => $orphanedMessagesDeleted,
        ];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes, 1024));

        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }
}
