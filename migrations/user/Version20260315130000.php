<?php

class UserMigration_20260315130000
{
    public function up(PDO $db): void
    {
        $db->exec('ALTER TABLE notifications ADD COLUMN url VARCHAR(500) DEFAULT NULL');
    }

    public function down(PDO $db): void
    {
        // SQLite doesn't support DROP COLUMN in older versions
        // The column will simply be ignored if not used
    }
}
