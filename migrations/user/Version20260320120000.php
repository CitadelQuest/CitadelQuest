<?php

/**
 * Add post reactions support:
 * - New cq_user_feed_post_reaction table (one reaction per contact per post)
 * - Add stats JSON column to cq_user_feed_post and cq_federation_feed_post
 */
class UserMigration_20260320120000
{
    public function up(PDO $db): void
    {
        // Reaction table for user's own feed posts
        $db->exec('
            CREATE TABLE IF NOT EXISTS cq_user_feed_post_reaction (
                id VARCHAR(36) PRIMARY KEY,
                cq_user_feed_post_id VARCHAR(36) NOT NULL,
                cq_contact_id VARCHAR(36) NOT NULL,
                cq_contact_url VARCHAR(255) NOT NULL,
                reaction INTEGER NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');

        // Unique: one reaction per contact per post
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_reaction_post_contact
            ON cq_user_feed_post_reaction(cq_user_feed_post_id, cq_contact_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_reaction_post
            ON cq_user_feed_post_reaction(cq_user_feed_post_id)');

        // Stats JSON column on own posts
        $db->exec('ALTER TABLE cq_user_feed_post ADD COLUMN stats TEXT DEFAULT \'{}\'');

        // Stats JSON column on cached federation posts
        $db->exec('ALTER TABLE cq_federation_feed_post ADD COLUMN stats TEXT DEFAULT \'{}\'');
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS cq_user_feed_post_reaction');
    }
}
