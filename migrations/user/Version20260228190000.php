<?php

/**
 * Migration: CQ Contact ID Consolidation
 * 
 * Merges the redundant `id` (local UUID) and `cq_contact_id` (global Federation UUID)
 * columns in the cq_contact table into a single `id` column that holds the global
 * Federation UUID. Uses COALESCE(cq_contact_id, id) to handle SENT contacts
 * where cq_contact_id may be NULL.
 * 
 * Updates all foreign key references in related tables.
 */
class UserMigration_20260228190000
{
    public function up(\PDO $db): void
    {
        // Step 1: Create new cq_contact table without cq_contact_id column
        $db->exec("
            CREATE TABLE IF NOT EXISTS cq_contact_new (
                id VARCHAR(36) PRIMARY KEY,
                cq_contact_url VARCHAR(255) NOT NULL,
                cq_contact_domain VARCHAR(255) NOT NULL,
                cq_contact_username VARCHAR(255) NOT NULL,
                cq_contact_api_key VARCHAR(255),
                friend_request_status VARCHAR(36),
                description TEXT,
                profile_photo_project_file_id VARCHAR(36),
                is_active BOOLEAN DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            )
        ");

        // Step 2: Copy data — use cq_contact_id (global UUID) as new id, fallback to old id
        $db->exec("
            INSERT INTO cq_contact_new (
                id, cq_contact_url, cq_contact_domain, cq_contact_username,
                cq_contact_api_key, friend_request_status, description,
                profile_photo_project_file_id, is_active, created_at, updated_at
            )
            SELECT 
                COALESCE(cq_contact_id, id),
                cq_contact_url, cq_contact_domain, cq_contact_username,
                cq_contact_api_key, friend_request_status, description,
                profile_photo_project_file_id, is_active, created_at, updated_at
            FROM cq_contact
        ");

        // Step 3: Build old_id → new_id mapping and update all FK references
        // We need to update related tables that reference cq_contact.id (the old local UUID)
        // to use the new id (which is COALESCE(cq_contact_id, old_id))

        // cq_chat.cq_contact_id references cq_contact.id
        $db->exec("
            UPDATE cq_chat SET cq_contact_id = (
                SELECT COALESCE(c.cq_contact_id, c.id) FROM cq_contact c WHERE c.id = cq_chat.cq_contact_id
            ) WHERE cq_contact_id IS NOT NULL AND cq_contact_id IN (SELECT id FROM cq_contact)
        ");

        // cq_chat.group_host_contact_id references cq_contact.id
        $db->exec("
            UPDATE cq_chat SET group_host_contact_id = (
                SELECT COALESCE(c.cq_contact_id, c.id) FROM cq_contact c WHERE c.id = cq_chat.group_host_contact_id
            ) WHERE group_host_contact_id IS NOT NULL AND group_host_contact_id IN (SELECT id FROM cq_contact)
        ");

        // cq_chat_msg.cq_contact_id references cq_contact.id
        $db->exec("
            UPDATE cq_chat_msg SET cq_contact_id = (
                SELECT COALESCE(c.cq_contact_id, c.id) FROM cq_contact c WHERE c.id = cq_chat_msg.cq_contact_id
            ) WHERE cq_contact_id IS NOT NULL AND cq_contact_id IN (SELECT id FROM cq_contact)
        ");

        // cq_chat_group_members.cq_contact_id references cq_contact.id
        $db->exec("
            UPDATE cq_chat_group_members SET cq_contact_id = (
                SELECT COALESCE(c.cq_contact_id, c.id) FROM cq_contact c WHERE c.id = cq_chat_group_members.cq_contact_id
            ) WHERE cq_contact_id IS NOT NULL AND cq_contact_id IN (SELECT id FROM cq_contact)
        ");

        // cq_chat_msg_delivery.cq_contact_id references cq_contact.id
        $db->exec("
            UPDATE cq_chat_msg_delivery SET cq_contact_id = (
                SELECT COALESCE(c.cq_contact_id, c.id) FROM cq_contact c WHERE c.id = cq_chat_msg_delivery.cq_contact_id
            ) WHERE cq_contact_id IS NOT NULL AND cq_contact_id IN (SELECT id FROM cq_contact)
        ");

        // project_cq_contact.cq_contact_id references cq_contact.id
        $db->exec("
            UPDATE project_cq_contact SET cq_contact_id = (
                SELECT COALESCE(c.cq_contact_id, c.id) FROM cq_contact c WHERE c.id = project_cq_contact.cq_contact_id
            ) WHERE cq_contact_id IS NOT NULL AND cq_contact_id IN (SELECT id FROM cq_contact)
        ");

        // Note: project_file_remote.source_cq_contact_id already stores global UUID — no update needed

        // Step 4: Drop old table and rename new one
        $db->exec("DROP TABLE cq_contact");
        $db->exec("ALTER TABLE cq_contact_new RENAME TO cq_contact");
    }

    public function down(\PDO $db): void
    {
        // This migration is not reversible in a meaningful way
        // The old local UUIDs are lost after consolidation
    }
}
