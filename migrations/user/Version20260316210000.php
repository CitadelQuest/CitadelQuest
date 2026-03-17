<?php

class UserMigration_20260316210000
{
    public function up(PDO $db): void
    {
        // Add sender_username and sender_domain to cq_chat_msg
        // so messages from non-friend group members can still show sender info
        $db->exec('ALTER TABLE cq_chat_msg ADD COLUMN sender_username VARCHAR(255) DEFAULT NULL');
        $db->exec('ALTER TABLE cq_chat_msg ADD COLUMN sender_domain VARCHAR(255) DEFAULT NULL');

        // Add member_username and member_domain to cq_chat_group_members
        // so non-friend group members can still show in member badges
        $db->exec('ALTER TABLE cq_chat_group_members ADD COLUMN member_username VARCHAR(255) DEFAULT NULL');
        $db->exec('ALTER TABLE cq_chat_group_members ADD COLUMN member_domain VARCHAR(255) DEFAULT NULL');
    }

    public function down(PDO $db): void
    {
        // SQLite doesn't support DROP COLUMN in older versions
    }
}
