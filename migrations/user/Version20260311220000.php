<?php

/**
 * Migration: Create cq_follow and cq_followers tables for CQ Follow feature
 * 
 * cq_follow — tracks profiles the user is following
 * cq_followers — tracks who is following this user (received from remote Citadels)
 * 
 * @see /docs/features/CQ-FOLLOW.md
 */
class UserMigration_20260311220000
{
    public function up(\PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS cq_follow (
            id VARCHAR(36) PRIMARY KEY,
            cq_contact_id VARCHAR(36) NOT NULL,
            cq_contact_url TEXT NOT NULL,
            cq_contact_domain VARCHAR(255) NOT NULL,
            cq_contact_username VARCHAR(255) NOT NULL,
            last_visited_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_cq_follow_contact_id ON cq_follow(cq_contact_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_cq_follow_domain ON cq_follow(cq_contact_domain)");

        $db->exec("CREATE TABLE IF NOT EXISTS cq_followers (
            id VARCHAR(36) PRIMARY KEY,
            cq_contact_id VARCHAR(36) NOT NULL,
            cq_contact_url TEXT NOT NULL,
            cq_contact_domain VARCHAR(255) NOT NULL,
            cq_contact_username VARCHAR(255) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_cq_followers_contact_id ON cq_followers(cq_contact_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_cq_followers_domain ON cq_followers(cq_contact_domain)");
    }

    public function down(\PDO $db): void
    {
        $db->exec("DROP TABLE IF EXISTS cq_follow");
        $db->exec("DROP TABLE IF EXISTS cq_followers");
    }
}
