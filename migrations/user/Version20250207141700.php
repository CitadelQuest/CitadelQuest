<?php

class UserMigration_20250207141700
{
    public function getDescription(): string
    {
        return 'Example user database migration';
    }
    
    public function up(PDO $db): void
    {
        // Example migration
        $db->exec(
            'CREATE TABLE IF NOT EXISTS example ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . 'name TEXT NOT NULL,'
            . 'created_at DATETIME DEFAULT CURRENT_TIMESTAMP'
            . ')'
        );
    }
    
    public function getVersion(): string
    {
        return '20250207141700';
    }
}
