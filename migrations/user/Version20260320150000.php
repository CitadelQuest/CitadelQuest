<?php

/**
 * Add post comments support:
 * - New cq_user_feed_post_comment table (comments on user's own feed posts)
 * - Supports nested replies (parent_id), moderation (is_active), editing (updated_at)
 * - Max 2 nesting levels (comment + reply), max 2000 chars content
 */
class UserMigration_20260320150000
{
    public function up(PDO $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS cq_user_feed_post_comment (
                id VARCHAR(36) PRIMARY KEY,
                cq_user_feed_post_id VARCHAR(36) NOT NULL,
                parent_id VARCHAR(36) DEFAULT NULL,
                cq_contact_id VARCHAR(36) NOT NULL,
                cq_contact_url VARCHAR(255) NOT NULL,
                content TEXT NOT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $db->exec('CREATE INDEX IF NOT EXISTS idx_comment_post
            ON cq_user_feed_post_comment(cq_user_feed_post_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_comment_parent
            ON cq_user_feed_post_comment(parent_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_comment_contact
            ON cq_user_feed_post_comment(cq_user_feed_post_id, cq_contact_id)');
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS cq_user_feed_post_comment');
    }
}
