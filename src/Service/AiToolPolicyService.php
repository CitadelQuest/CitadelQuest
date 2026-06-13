<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Citadel-level (system) AI tool policy.
 *
 * Reads/writes the `ai_tools` table in main.db, which stores the `adminOnly`
 * flag per tool name. This is a system-wide policy (one per Citadel), NOT
 * stored in per-user databases.
 *
 * Tools flagged adminOnly are hidden from non-admin users everywhere
 * (Settings, Chat Settings, AI tool definitions) and blocked at execution.
 */
class AiToolPolicyService
{
    /** @var string[]|null In-request cache of admin-only tool names */
    private ?array $adminOnlyNamesCache = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return string[] Names of tools that are admin-only
     */
    public function getAdminOnlyToolNames(): array
    {
        if ($this->adminOnlyNamesCache !== null) {
            return $this->adminOnlyNamesCache;
        }

        try {
            $names = $this->connection->executeQuery(
                'SELECT tool_name FROM ai_tools WHERE admin_only = 1'
            )->fetchFirstColumn();
            $this->adminOnlyNamesCache = $names ?: [];
        } catch (\Throwable $e) {
            // Table may not exist yet (migration not applied) — fail open to empty policy
            $this->logger->warning('AiToolPolicyService: could not read ai_tools table: ' . $e->getMessage());
            $this->adminOnlyNamesCache = [];
        }

        return $this->adminOnlyNamesCache;
    }

    public function isAdminOnly(string $toolName): bool
    {
        return in_array($toolName, $this->getAdminOnlyToolNames(), true);
    }

    /**
     * Set (or clear) the admin-only flag for a tool name.
     */
    public function setAdminOnly(string $toolName, bool $adminOnly): void
    {
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        $existingId = $this->connection->executeQuery(
            'SELECT id FROM ai_tools WHERE tool_name = ?',
            [$toolName]
        )->fetchOne();

        if ($existingId) {
            $this->connection->executeStatement(
                'UPDATE ai_tools SET admin_only = ?, updated_at = ? WHERE tool_name = ?',
                [$adminOnly ? 1 : 0, $now, $toolName]
            );
        } else {
            $this->connection->executeStatement(
                'INSERT INTO ai_tools (id, tool_name, admin_only, updated_at) VALUES (?, ?, ?, ?)',
                [Uuid::v7()->toRfc4122(), $toolName, $adminOnly ? 1 : 0, $now]
            );
        }

        // Invalidate cache
        $this->adminOnlyNamesCache = null;
    }
}
