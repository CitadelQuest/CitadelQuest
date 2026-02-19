<?php

class UserMigration_20260219145000
{
    public function up(\PDO $db): void
    {
        $tablesToDrop = [
            'contacts',
            'content',
            'diary_entries',
            'example',
            'keys',
        ];

        foreach ($tablesToDrop as $table) {
            $db->exec("DROP TABLE IF EXISTS {$table}");
        }
    }

    public function down(\PDO $db): void
    {
    }
}
