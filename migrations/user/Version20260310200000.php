<?php

/**
 * Migration: Add url_slug and icon_color columns to cq_share_group
 * 
 * url_slug — URL-friendly slug for profile content navigation (/{username}/{slug})
 * icon_color — custom color for the group icon (hex string like #00b894)
 * 
 * @see /docs/features/CQ-SHARE-GROUPS.md
 */
class UserMigration_20260310200000
{
    public function up(\PDO $db): void
    {
        // Add url_slug column
        $db->exec("ALTER TABLE cq_share_group ADD COLUMN url_slug VARCHAR(128) DEFAULT NULL");
        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_cq_share_group_slug ON cq_share_group(url_slug)");

        // Add icon_color column
        $db->exec("ALTER TABLE cq_share_group ADD COLUMN icon_color VARCHAR(16) DEFAULT NULL");

        // Generate slugs for existing groups that don't have one
        $stmt = $db->query("SELECT id, title FROM cq_share_group WHERE url_slug IS NULL OR url_slug = ''");
        $groups = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($groups as $group) {
            $slug = $this->generateSlug($group['title']);
            // Ensure uniqueness
            $checkStmt = $db->prepare("SELECT COUNT(*) FROM cq_share_group WHERE url_slug = ?");
            $checkStmt->execute([$slug]);
            if ((int) $checkStmt->fetchColumn() > 0) {
                $slug .= '-' . substr($group['id'], 0, 8);
            }
            $updateStmt = $db->prepare("UPDATE cq_share_group SET url_slug = ? WHERE id = ?");
            $updateStmt->execute([$slug, $group['id']]);
        }
    }

    public function down(\PDO $db): void
    {
        // SQLite doesn't support DROP COLUMN before 3.35.0, so we skip for safety
    }

    private function generateSlug(string $title): string
    {
        $slug = mb_strtolower($title);
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = preg_replace('/[\s-]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug ?: 'group';
    }
}
