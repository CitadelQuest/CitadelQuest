<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\DriverManager;
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
        // Create databases directory if it doesn't exist
        $this->filesystem->mkdir($this->databasesDir);

        // Generate unique database path
        $dbPath = sprintf(
            '%s/%s.sqlite',
            $this->databasesDir,
            bin2hex(random_bytes(16))
        );

        // Create SQLite database with proper permissions
        touch($dbPath);
        chmod($dbPath, 0666);

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $dbPath,
        ]);

        // Initialize database schema
        $this->initializeDatabaseSchema($connection);

        // Set database path in user entity
        $user->setDatabasePath($dbPath);
    }

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
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME
            )',
            'CREATE TABLE IF NOT EXISTS contacts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username VARCHAR(180) NOT NULL,
                public_key TEXT,
                last_seen DATETIME,
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
}
