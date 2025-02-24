<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\DriverManager;
use PDO;
use RuntimeException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class UserDatabaseManager
{
    private string $databasesDir;

    public function __construct(
        ParameterBagInterface $params
    ) {
        $this->databasesDir = $params->get('kernel.project_dir') . '/var/user_databases';
    }

    public function getUserDatabaseFullPath(User $user): string
    {
        return $this->databasesDir . '/' . $user->getDatabasePath();
    }

    public function createUserDatabase(User $user): void
    {
        // Ensure databases directory exists
        // Use umask to set permissions during creation
        $oldUmask = umask(0);
        if (!is_dir($this->databasesDir)) {
            mkdir($this->databasesDir, 0777, true);
        }
        umask($oldUmask);

        // Generate unique database filename
        $dbFilename = sprintf('%s.db', bin2hex(random_bytes(16)));

        // Set database filename in user entity
        $user->setDatabasePath($dbFilename);

        // Get full path to database
        $dbFullPath = $this->getUserDatabaseFullPath($user);

        // Set umask before creating database
        $oldUmask = umask(0);
        
        try {
            // Touch the database file first to ensure proper permissions
            touch($dbFullPath);
            chmod($dbFullPath, 0666);
            
            // Create connection with SQLite-specific options
            $connection = DriverManager::getConnection([
                'driver' => 'pdo_sqlite',
                'path' => $dbFullPath,
                'driverOptions' => [
                    PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READWRITE | PDO::SQLITE_OPEN_CREATE,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]
            ]);

            // Force SQLite to create the database file
            $connection->executeQuery('PRAGMA journal_mode=WAL');
            $connection->executeQuery('PRAGMA synchronous=NORMAL');
            $connection->executeQuery('PRAGMA temp_store=FILE');
        } finally {
            // Restore original umask
            umask($oldUmask);
        }

        // Initialize database schema
        $this->initializeDatabaseSchema($connection);
    }

    /**
     * Initialize the user database schema.
     * IMPORTANT: When modifying this schema, always create a corresponding user migration in migrations/user/
     * User migrations must be simple SQL (no Doctrine) since they run from the standalone update script.
     * See migrations/user/Version20250218135524.php for an example.
     */
    private function initializeDatabaseSchema($connection): void
    {
        // Create basic tables for user's personal data
        $schema = [
            'CREATE TABLE IF NOT EXISTS content (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title VARCHAR(255) NOT NULL,
                content TEXT NOT NULL,
                encrypted BOOLEAN DEFAULT true,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )',
            'CREATE TABLE IF NOT EXISTS keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                key_type VARCHAR(50) NOT NULL,
                public_key TEXT,
                encrypted_private_key TEXT,
                key_salt VARCHAR(32),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME
            )',
            'CREATE TABLE IF NOT EXISTS contacts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username VARCHAR(180) NOT NULL,
                public_key TEXT,
                last_seen DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )',
            'CREATE TABLE IF NOT EXISTS notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                type VARCHAR(50) NOT NULL,
                is_read BOOLEAN DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )',
            'CREATE TABLE IF NOT EXISTS diary_entries (
                id VARCHAR(36) PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                content TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                is_encrypted BOOLEAN DEFAULT 0,
                is_favorite BOOLEAN DEFAULT 0,
                tags TEXT DEFAULT NULL,
                mood VARCHAR(50) DEFAULT NULL,
                content_formatted TEXT
            )',
            'CREATE INDEX IF NOT EXISTS idx_diary_entries_created_at ON diary_entries(created_at)',
            'CREATE INDEX IF NOT EXISTS idx_diary_entries_is_favorite ON diary_entries(is_favorite)'
        ];

        foreach ($schema as $query) {
            $connection->executeStatement($query);
        }
    }

    public function getDatabaseConnection(User $user): \Doctrine\DBAL\Connection
    {
        if (!$user->getDatabasePath()) {
            throw new \RuntimeException('User database path not set');
        }

        $dbFullPath = $this->getUserDatabaseFullPath($user);
        if (!file_exists($dbFullPath)) {
            throw new \RuntimeException('User database file not found');
        }

        return DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $dbFullPath,
        ]);
    }

    public function deleteUserDatabase(User $user): void
    {
        if ($user->getDatabasePath()) {
            $dbFullPath = $this->getUserDatabaseFullPath($user);
            if (file_exists($dbFullPath)) {
                unlink($dbFullPath);
            }
        }
    }

    public function getUserDatabase(User $user): string
    {
        if (!$user->getDatabasePath()) {
            throw new RuntimeException('User database path not set');
        }

        $dbFullPath = $this->getUserDatabaseFullPath($user);
        if (!file_exists($dbFullPath)) {
            throw new RuntimeException('User database file not found');
        }

        return $dbFullPath;
    }

    public function updateDatabaseSchema(User $user): void
    {
        $connection = $this->getDatabaseConnection($user);
        $this->initializeDatabaseSchema($connection);
    }
}
