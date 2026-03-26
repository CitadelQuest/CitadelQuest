<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;
use Psr\Log\LoggerInterface;

/**
 * CQ Share Group — organize shared items into named, ordered groups on CQ Profile.
 * 
 * @see /docs/features/CQ-SHARE-GROUPS.md
 */
class CQShareGroupService
{
    private ?User $user;

    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly Security $security,
        private readonly LoggerInterface $logger
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
            throw new \RuntimeException('User not set');
        }
        return $this->userDatabaseManager->getDatabaseConnection($this->user);
    }

    // ========================================
    // Group CRUD
    // ========================================

    /**
     * Create a new share group
     */
    public function createGroup(string $title, string $mdiIcon = 'mdi-folder', int $scope = CQShareService::SCOPE_PUBLIC, bool $showInNav = true, ?string $urlSlug = null, ?string $iconColor = null): array
    {
        $db = $this->getUserDb();

        $id = Uuid::v4()->toRfc4122();
        $now = date('Y-m-d H:i:s');

        // Get next order value
        $maxOrder = $db->executeQuery('SELECT COALESCE(MAX("order"), -1) FROM cq_share_group')->fetchOne();
        $order = ((int) $maxOrder) + 1;

        // Auto-generate slug from title if not provided
        if (empty($urlSlug)) {
            $urlSlug = $this->generateSlug($title);
        }
        $urlSlug = $this->ensureUniqueSlug($urlSlug, null);

        $db->executeStatement(
            'INSERT INTO cq_share_group (id, title, mdi_icon, scope, show_in_nav, is_active, "order", url_slug, icon_color, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)',
            [$id, $title, $mdiIcon, $scope, $showInNav ? 1 : 0, $order, $urlSlug, $iconColor, $now, $now]
        );

        return $this->findGroupById($id);
    }

    /**
     * Find group by ID
     */
    public function findGroupById(string $id): ?array
    {
        $db = $this->getUserDb();
        $row = $db->executeQuery('SELECT * FROM cq_share_group WHERE id = ?', [$id])->fetchAssociative();
        return $row ?: null;
    }

    /**
     * List all groups ordered by "order" column
     */
    public function listGroups(): array
    {
        $db = $this->getUserDb();
        return $db->executeQuery(
            'SELECT * FROM cq_share_group ORDER BY "order" ASC, created_at ASC'
        )->fetchAllAssociative();
    }

    /**
     * List active groups filtered by scope, with their items and enriched share data.
     * Used by public profile and federation endpoints.
     *
     * @param int[] $allowedScopes e.g. [0] for public, [0,1] for federation
     */
    public function listActiveGroupsWithItems(array $allowedScopes): array
    {
        $db = $this->getUserDb();

        $placeholders = implode(',', array_fill(0, count($allowedScopes), '?'));

        $groups = $db->executeQuery(
            'SELECT * FROM cq_share_group
             WHERE is_active = 1 AND scope IN (' . $placeholders . ')
             ORDER BY "order" ASC, created_at ASC',
            $allowedScopes
        )->fetchAllAssociative();

        foreach ($groups as &$group) {
            $group['items'] = $db->executeQuery(
                'SELECT gi.*, s.source_type, s.source_id, s.title AS share_title, s.share_url,
                        s.scope AS share_scope, s.views, s.display_style AS share_display_style,
                        s.description, s.description_display_style AS share_description_display_style,
                        s.created_at AS share_created_at, s.updated_at AS share_updated_at
                 FROM cq_share_group_item gi
                 JOIN cq_share s ON s.id = gi.share_id
                 WHERE gi.group_id = ? AND s.is_active = 1 AND s.scope IN (' . $placeholders . ')
                 ORDER BY gi."order" ASC',
                array_merge([$group['id']], $allowedScopes)
            )->fetchAllAssociative();

            // Apply per-item overrides
            foreach ($group['items'] as &$item) {
                $item['effective_display_style'] = $item['display_style'] ?? $item['share_display_style'];
                $item['effective_description_display_style'] = $item['description_display_style'] ?? $item['share_description_display_style'];
            }
            unset($item);
        }
        unset($group);

        return $groups;
    }

    /**
     * Get IDs of all shares that belong to at least one active group (for a given set of scopes).
     * Used to determine "ungrouped" shares.
     */
    public function getGroupedShareIds(array $allowedScopes): array
    {
        $db = $this->getUserDb();

        $placeholders = implode(',', array_fill(0, count($allowedScopes), '?'));

        $rows = $db->executeQuery(
            'SELECT DISTINCT gi.share_id
             FROM cq_share_group_item gi
             JOIN cq_share_group g ON g.id = gi.group_id
             WHERE g.is_active = 1 AND g.scope IN (' . $placeholders . ')',
            $allowedScopes
        )->fetchAllAssociative();

        return array_column($rows, 'share_id');
    }

    /**
     * Update group properties
     */
    public function updateGroup(string $id, array $data): ?array
    {
        $db = $this->getUserDb();

        $fieldMap = [
            'title' => 'title',
            'mdi_icon' => 'mdi_icon',
            'scope' => 'scope',
            'show_in_nav' => 'show_in_nav',
            'is_active' => 'is_active',
            'order' => '"order"',
            'url_slug' => 'url_slug',
            'icon_color' => 'icon_color',
        ];

        // Auto-generate slug from title if slug is empty but title changed
        if (array_key_exists('url_slug', $data) && empty($data['url_slug']) && !empty($data['title'])) {
            $data['url_slug'] = $this->generateSlug($data['title']);
        }
        // Ensure slug uniqueness
        if (!empty($data['url_slug'])) {
            $data['url_slug'] = $this->ensureUniqueSlug($data['url_slug'], $id);
        }

        $sets = [];
        $params = [];

        foreach ($fieldMap as $inputKey => $dbColumn) {
            if (array_key_exists($inputKey, $data)) {
                $sets[] = "{$dbColumn} = ?";
                $params[] = $data[$inputKey];
            }
        }

        if (empty($sets)) {
            return $this->findGroupById($id);
        }

        $sets[] = "updated_at = ?";
        $params[] = date('Y-m-d H:i:s');
        $params[] = $id;

        $db->executeStatement(
            'UPDATE cq_share_group SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        );

        return $this->findGroupById($id);
    }

    /**
     * Delete a group (cascade deletes items via FK)
     */
    public function deleteGroup(string $id): bool
    {
        $db = $this->getUserDb();
        // Delete items first (in case FK cascade is not enforced)
        $db->executeStatement('DELETE FROM cq_share_group_item WHERE group_id = ?', [$id]);
        return $db->executeStatement('DELETE FROM cq_share_group WHERE id = ?', [$id]) > 0;
    }

    /**
     * Batch reorder groups
     * @param array $orderedIds Array of group IDs in desired order
     */
    public function reorderGroups(array $orderedIds): void
    {
        $db = $this->getUserDb();
        foreach ($orderedIds as $index => $id) {
            $db->executeStatement(
                'UPDATE cq_share_group SET "order" = ?, updated_at = ? WHERE id = ?',
                [$index, date('Y-m-d H:i:s'), $id]
            );
        }
    }

    // ========================================
    // Group Item CRUD
    // ========================================

    /**
     * Add a share to a group
     */
    public function addItem(string $groupId, string $shareId, array $config = []): array
    {
        $db = $this->getUserDb();

        $id = Uuid::v4()->toRfc4122();

        // Get next order within group
        $maxOrder = $db->executeQuery(
            'SELECT COALESCE(MAX("order"), -1) FROM cq_share_group_item WHERE group_id = ?',
            [$groupId]
        )->fetchOne();
        $order = $config['order'] ?? ((int) $maxOrder) + 1;

        $db->executeStatement(
            'INSERT INTO cq_share_group_item (id, group_id, share_id, display_style, description_display_style, show_header, "order")
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $id,
                $groupId,
                $shareId,
                $config['display_style'] ?? null,
                $config['description_display_style'] ?? null,
                $config['show_header'] ?? 1,
                $order,
            ]
        );

        // Bump group updated_at so federation feed polling detects the change
        $db->executeStatement(
            'UPDATE cq_share_group SET updated_at = ? WHERE id = ?',
            [date('Y-m-d H:i:s'), $groupId]
        );

        return $this->findItemById($id);
    }

    /**
     * Find group item by ID
     */
    public function findItemById(string $id): ?array
    {
        $db = $this->getUserDb();
        $row = $db->executeQuery('SELECT * FROM cq_share_group_item WHERE id = ?', [$id])->fetchAssociative();
        return $row ?: null;
    }

    /**
     * List items in a group
     */
    public function listItems(string $groupId): array
    {
        $db = $this->getUserDb();
        return $db->executeQuery(
            'SELECT gi.*, s.title AS share_title, s.source_type, s.source_id, s.share_url, s.scope AS share_scope, s.is_active AS share_is_active,
                    s.display_style AS share_display_style, s.description, s.description_display_style AS share_description_display_style,
                    s.views, pf.path AS source_file_path, pf.name AS source_file_name
             FROM cq_share_group_item gi
             JOIN cq_share s ON s.id = gi.share_id
             LEFT JOIN project_file pf ON pf.id = s.source_id
             WHERE gi.group_id = ?
             ORDER BY gi."order" ASC',
            [$groupId]
        )->fetchAllAssociative();
    }

    /**
     * Update item config
     */
    public function updateItem(string $itemId, array $data): ?array
    {
        $db = $this->getUserDb();

        $fieldMap = [
            'display_style' => 'display_style',
            'description_display_style' => 'description_display_style',
            'show_header' => 'show_header',
            'order' => '"order"',
        ];

        $sets = [];
        $params = [];

        foreach ($fieldMap as $inputKey => $dbColumn) {
            if (array_key_exists($inputKey, $data)) {
                $sets[] = "{$dbColumn} = ?";
                $params[] = $data[$inputKey];
            }
        }

        if (empty($sets)) {
            return $this->findItemById($itemId);
        }

        $params[] = $itemId;

        $db->executeStatement(
            'UPDATE cq_share_group_item SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        );

        return $this->findItemById($itemId);
    }

    /**
     * Remove item from group
     */
    public function deleteItem(string $itemId): bool
    {
        $db = $this->getUserDb();
        return $db->executeStatement('DELETE FROM cq_share_group_item WHERE id = ?', [$itemId]) > 0;
    }

    /**
     * Batch reorder items within a group
     * @param array $orderedItemIds Array of item IDs in desired order
     */
    public function reorderItems(array $orderedItemIds): void
    {
        $db = $this->getUserDb();
        foreach ($orderedItemIds as $index => $id) {
            $db->executeStatement(
                'UPDATE cq_share_group_item SET "order" = ? WHERE id = ?',
                [$index, $id]
            );
        }
    }

    /**
     * Get the most recent updated_at from active public share groups.
     * Tracks group-level changes (new group created, group metadata updated, item added/removed).
     * Used by federation last-updated endpoint for feed polling.
     */
    public function getLastPublicGroupUpdatedAt(): ?string
    {
        $db = $this->getUserDb();
        $result = $db->executeQuery(
            'SELECT MAX(updated_at) FROM cq_share_group WHERE is_active = 1 AND scope = ?',
            [CQShareService::SCOPE_PUBLIC]
        )->fetchOne();

        return $result ?: null;
    }

    /**
     * Get the most recent updated_at from shares that belong to active public groups.
     * Tracks actual content changes (share file updated) within grouped items.
     * Used by federation last-updated endpoint for feed polling.
     */
    public function getLastPublicGroupShareUpdatedAt(): ?string
    {
        $db = $this->getUserDb();
        $result = $db->executeQuery(
            'SELECT MAX(s.updated_at)
             FROM cq_share_group_item gi
             JOIN cq_share_group g ON g.id = gi.group_id
             JOIN cq_share s ON s.id = gi.share_id
             WHERE g.is_active = 1 AND g.scope = ? AND s.is_active = 1',
            [CQShareService::SCOPE_PUBLIC]
        )->fetchOne();

        return $result ?: null;
    }

    // ========================================
    // Slug helpers
    // ========================================

    /**
     * Find an active group by its URL slug
     */
    public function findGroupBySlug(string $slug): ?array
    {
        $db = $this->getUserDb();
        $row = $db->executeQuery(
            'SELECT * FROM cq_share_group WHERE url_slug = ? AND is_active = 1',
            [$slug]
        )->fetchAssociative();
        return $row ?: null;
    }

    /**
     * Generate a URL-friendly slug from a title
     */
    public function generateSlug(string $title): string
    {
        $slug = mb_strtolower($title);
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = preg_replace('/[\s-]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug ?: 'content';
    }

    /**
     * Ensure a slug is unique, appending a suffix if needed
     */
    public function ensureUniqueSlug(string $slug, ?string $excludeId): string
    {
        $db = $this->getUserDb();
        $base = $slug;
        $counter = 1;

        while (true) {
            $params = [$slug];
            $sql = 'SELECT COUNT(*) FROM cq_share_group WHERE url_slug = ?';
            if ($excludeId) {
                $sql .= ' AND id != ?';
                $params[] = $excludeId;
            }
            $count = (int) $db->executeQuery($sql, $params)->fetchOne();
            if ($count === 0) {
                return $slug;
            }
            $counter++;
            $slug = $base . '-' . $counter;
        }
    }
}
