<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\DriverManager;
use PDO;
use RuntimeException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;

class UserDatabaseManager
{
    private string $databasesDir;
    private Filesystem $filesystem;

    public function __construct(
        ParameterBagInterface $params,
        Filesystem $filesystem
    ) {
        $this->databasesDir = $params->get('kernel.project_dir') . '/var/user_databases';
        $this->filesystem = $filesystem;
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

        // Generate unique database path
        $dbPath = sprintf(
            '%s/%s.db',
            $this->databasesDir,
            bin2hex(random_bytes(16))
        );

        // Set umask before creating database
        $oldUmask = umask(0);
        
        try {
            // Touch the database file first to ensure proper permissions
            touch($dbPath);
            chmod($dbPath, 0666);
            
            // Create connection with SQLite-specific options
            $connection = DriverManager::getConnection([
                'driver' => 'pdo_sqlite',
                'path' => $dbPath,
                'driverOptions' => [
                    PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READWRITE | PDO::SQLITE_OPEN_CREATE,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]
            ]);

            // Force SQLite to create the database file
            $connection->executeQuery('PRAGMA journal_mode=WAL');
            $connection->executeQuery('PRAGMA synchronous=NORMAL');
            $connection->executeQuery('PRAGMA temp_store=FILE');
            
            // Ensure WAL files have correct permissions
            $walFile = $dbPath . '-wal';
            $shmFile = $dbPath . '-shm';
            if (file_exists($walFile)) {
                chmod($walFile, 0666);
            }
            if (file_exists($shmFile)) {
                chmod($shmFile, 0666);
            }
        } finally {
            // Restore original umask
            umask($oldUmask);
        }

        // Initialize database schema
        $this->initializeDatabaseSchema($connection);

        // Set database path in user entity
        $user->setDatabasePath($dbPath);
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
            )'
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

        if (!file_exists($user->getDatabasePath())) {
            throw new \RuntimeException('User database file not found');
        }

        return DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $user->getDatabasePath(),
        ]);
    }

    public function deleteUserDatabase(User $user): void
    {
        if ($user->getDatabasePath() && file_exists($user->getDatabasePath())) {
            $this->filesystem->remove($user->getDatabasePath());
        }
    }

    public function getUserDatabase(User $user): string
    {
        if (!$user->getDatabasePath()) {
            throw new RuntimeException('User database path not set');
        }

        if (!file_exists($user->getDatabasePath())) {
            throw new RuntimeException('User database file not found');
        }

        return $user->getDatabasePath();
    }

    public function updateDatabaseSchema(User $user): void
    {
        $connection = $this->getDatabaseConnection($user);
        $this->initializeDatabaseSchema($connection);
    }
}
