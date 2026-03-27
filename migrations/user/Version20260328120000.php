<?php

/**
 * CQ Feed Post Attachments — links feed posts to CQ Share items.
 * 
 * Each attachment references a cq_share record and has a display_style override
 * that controls how the shared content appears in the feed timeline.
 * 
 * display_style values:
 *   0 = header only (title, no preview)
 *   1 = preview (thumbnail/excerpt)
 *   2 = full content
 */
class UserMigration_20260328120000
{
    public function up(\PDO $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS cq_user_feed_post_attachment (
                id VARCHAR(36) PRIMARY KEY,
                cq_user_feed_post_id VARCHAR(36) NOT NULL,
                cq_share_id VARCHAR(36) NOT NULL,
                display_style INTEGER NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $db->exec('CREATE INDEX IF NOT EXISTS idx_feed_post_attachment_post ON cq_user_feed_post_attachment(cq_user_feed_post_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_feed_post_attachment_share ON cq_user_feed_post_attachment(cq_share_id)');

        // Add attachments_json column to federation cached posts
        // (stores serialized attachment data from remote Citadels)
        try {
            $db->exec('ALTER TABLE cq_federation_feed_post ADD COLUMN attachments_json TEXT DEFAULT NULL');
        } catch (\Exception $e) {
            // Column may already exist
        }
    }

    public function down(\PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS cq_user_feed_post_attachment');
    }
}
