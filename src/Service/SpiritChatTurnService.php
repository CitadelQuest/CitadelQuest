<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * Spirit Chat Turn Service
 *
 * Persists background "turn" jobs in the user database. A turn represents a full
 * Spirit Chat exchange (user message -> AI response -> tool-execution loop) that is
 * executed by a detached CLI worker (app:spirit-chat-turn) instead of being held open
 * on a single HTTP request. This prevents Cloudflare 524 timeouts on long AI turns.
 *
 * The user is resolved lazily (at query time) so this service works both in the normal
 * authenticated web context and inside the CLI worker (after a token is set on the
 * security token storage).
 */
class SpiritChatTurnService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_STOPPED = 'stopped';

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
            throw new \RuntimeException('SpiritChatTurnService: User not authenticated');
        }
        return $this->userDatabaseManager->getDatabaseConnection($user);
    }

    /**
     * Create a new pending turn job.
     *
     * @param array $payload lang, maxOutput, temperature, toolTemperature, cachedSystemPrompt
     * @return string The new turn job id
     */
    public function create(string $conversationId, ?string $userMessageId, array $payload): string
    {
        $db = $this->getUserDb();
        $id = Uuid::v4()->toRfc4122();

        $db->executeStatement(
            'INSERT INTO spirit_chat_turn (id, conversation_id, user_message_id, status, stop_requested, payload, created_at)
             VALUES (?, ?, ?, ?, 0, ?, ?)',
            [
                $id,
                $conversationId,
                $userMessageId,
                self::STATUS_PENDING,
                json_encode($payload),
                (new \DateTime())->format('Y-m-d H:i:s'),
            ]
        );

        return $id;
    }

    public function find(string $id): ?array
    {
        $db = $this->getUserDb();
        $row = $db->executeQuery(
            'SELECT * FROM spirit_chat_turn WHERE id = ?',
            [$id]
        )->fetchAssociative();

        return $row ?: null;
    }

    /**
     * Find the most recent still-running turn (pending or processing) for a conversation.
     * Used when the Spirit Chat modal is (re)opened to resume polling/UI state for a turn
     * whose detached worker is still running after the browser was closed.
     */
    public function findActiveByConversation(string $conversationId): ?array
    {
        $db = $this->getUserDb();
        $row = $db->executeQuery(
            'SELECT * FROM spirit_chat_turn WHERE conversation_id = ? AND status IN (?, ?) ORDER BY created_at DESC LIMIT 1',
            [$conversationId, self::STATUS_PENDING, self::STATUS_PROCESSING]
        )->fetchAssociative();

        return $row ?: null;
    }

    public function markProcessing(string $id): void
    {
        $this->getUserDb()->executeStatement(
            'UPDATE spirit_chat_turn SET status = ?, started_at = ? WHERE id = ?',
            [self::STATUS_PROCESSING, (new \DateTime())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function markCompleted(string $id): void
    {
        $this->getUserDb()->executeStatement(
            'UPDATE spirit_chat_turn SET status = ?, completed_at = ?, payload = "removed" WHERE id = ? AND status NOT IN (?, ?)',
            [
                self::STATUS_COMPLETED,
                (new \DateTime())->format('Y-m-d H:i:s'),
                $id,
                self::STATUS_FAILED,
                self::STATUS_STOPPED,
            ]
        );
    }

    public function markStopped(string $id): void
    {
        $this->getUserDb()->executeStatement(
            'UPDATE spirit_chat_turn SET status = ?, completed_at = ?, payload = "removed" WHERE id = ?',
            [self::STATUS_STOPPED, (new \DateTime())->format('Y-m-d H:i:s'), $id]
        );
    }

    public function markFailed(string $id, string $error): void
    {
        $this->getUserDb()->executeStatement(
            'UPDATE spirit_chat_turn SET status = ?, completed_at = ?, error = ? WHERE id = ?',
            [self::STATUS_FAILED, (new \DateTime())->format('Y-m-d H:i:s'), $error, $id]
        );
    }

    /**
     * Flag a turn to stop after its current AI call finishes.
     */
    public function requestStop(string $id): void
    {
        $this->getUserDb()->executeStatement(
            'UPDATE spirit_chat_turn SET stop_requested = 1 WHERE id = ?',
            [$id]
        );
    }

    public function isStopRequested(string $id): bool
    {
        $row = $this->getUserDb()->executeQuery(
            'SELECT stop_requested FROM spirit_chat_turn WHERE id = ?',
            [$id]
        )->fetchAssociative();

        return $row ? ((int) $row['stop_requested'] === 1) : false;
    }
}
