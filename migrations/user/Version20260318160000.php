<?php

/**
 * CQ Feed — database tables for user feeds, posts, and federation feed subscriptions.
 * 
 */
class UserMigration_20260318160000
{
    public function up(PDO $db): void
    {
        // User's own feeds
        $db->exec('
            CREATE TABLE IF NOT EXISTS cq_user_feed (
                id VARCHAR(36) PRIMARY KEY,
                scope INTEGER NOT NULL DEFAULT 1,
                feed_url_slug TEXT NOT NULL,
                image_project_file_id VARCHAR(36),
                title TEXT NOT NULL,
                description TEXT,
                is_active BOOLEAN DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');

        // User's own feed posts
        $db->exec('
            CREATE TABLE IF NOT EXISTS cq_user_feed_post (
                id VARCHAR(36) PRIMARY KEY,
                cq_user_feed_id VARCHAR(36) NOT NULL,
                post_url_slug TEXT NOT NULL,
                content TEXT NOT NULL,
                is_active BOOLEAN DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');

        // Subscribed federation feeds (from other Citadels)
        $db->exec('
            CREATE TABLE IF NOT EXISTS cq_federation_feed (
                id VARCHAR(36) PRIMARY KEY,
                cq_contact_id VARCHAR(36),
                cq_contact_url VARCHAR(255) NOT NULL,
                cq_contact_domain VARCHAR(255) NOT NULL,
                cq_contact_username VARCHAR(255) NOT NULL,
                feed_url_slug TEXT NOT NULL,
                title TEXT NOT NULL,
                description TEXT,
                is_active BOOLEAN DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_visited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');

        // Cached federation feed posts
        $db->exec('
            CREATE TABLE IF NOT EXISTS cq_federation_feed_post (
                id VARCHAR(36) PRIMARY KEY,
                cq_feed_id VARCHAR(36) NOT NULL,
                cq_contact_id VARCHAR(36),
                cq_contact_url VARCHAR(255) NOT NULL,
                cq_contact_domain VARCHAR(255) NOT NULL,
                cq_contact_username VARCHAR(255) NOT NULL,
                post_url_slug TEXT NOT NULL,
                content TEXT NOT NULL,
                is_active BOOLEAN DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_visited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');

        // Indexes
        $db->exec('CREATE INDEX IF NOT EXISTS idx_user_feed_slug ON cq_user_feed(feed_url_slug)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_user_feed_post_feed_id ON cq_user_feed_post(cq_user_feed_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_fed_feed_contact ON cq_federation_feed(cq_contact_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_fed_feed_slug ON cq_federation_feed(feed_url_slug)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_fed_feed_post_feed_id ON cq_federation_feed_post(cq_feed_id)');
    }

    public function down(PDO $db): void
    {
        $db->exec('DROP TABLE IF EXISTS cq_federation_feed_post');
        $db->exec('DROP TABLE IF EXISTS cq_federation_feed');
        $db->exec('DROP TABLE IF EXISTS cq_user_feed_post');
        $db->exec('DROP TABLE IF EXISTS cq_user_feed');
    }
}
