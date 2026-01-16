<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\DefaultSchemaManagerFactory;
use Doctrine\DBAL\Configuration;
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
            $configuration = new Configuration();
            $configuration->setSchemaManagerFactory(new DefaultSchemaManagerFactory());

            $connection = DriverManager::getConnection([
                'driver' => 'pdo_sqlite',
                'path' => $dbFullPath,
                'driverOptions' => [
                    PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READWRITE | PDO::SQLITE_OPEN_CREATE,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ],
            ], $configuration);

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

        // Run migrations for this user database
        $this->runMigrationsForUserDatabase($connection, $dbFullPath);
    }

    /**
     * Initialize the user database schema.
     * IMPORTANT: Never modify this schema, always create a corresponding user migration in migrations/user/
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
            'CREATE INDEX IF NOT EXISTS idx_diary_entries_is_favorite ON diary_entries(is_favorite)',
            
            // Spirit system tables
            'CREATE TABLE IF NOT EXISTS spirits (
                id VARCHAR(36) PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                level INTEGER DEFAULT 1,
                experience INTEGER DEFAULT 0,
                visual_state VARCHAR(50) DEFAULT "initial",
                consciousness_level INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_interaction DATETIME DEFAULT CURRENT_TIMESTAMP,
                system_prompt TEXT,
                ai_model VARCHAR(50) DEFAULT ""
            )',
            'CREATE TABLE IF NOT EXISTS spirit_abilities (
                id VARCHAR(36) PRIMARY KEY,
                spirit_id VARCHAR(36) NOT NULL,
                ability_type VARCHAR(50) NOT NULL,
                ability_name VARCHAR(255) NOT NULL,
                unlocked BOOLEAN DEFAULT 0,
                unlocked_at DATETIME,
                FOREIGN KEY (spirit_id) REFERENCES spirits(id)
            )',
            'CREATE TABLE IF NOT EXISTS spirit_interactions (
                id VARCHAR(36) PRIMARY KEY,
                spirit_id VARCHAR(36) NOT NULL,
                interaction_type VARCHAR(50) NOT NULL,
                context TEXT,
                experience_gained INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (spirit_id) REFERENCES spirits(id)
            )',
            'CREATE INDEX IF NOT EXISTS idx_spirit_interactions_spirit_id ON spirit_interactions(spirit_id)',
            'CREATE INDEX IF NOT EXISTS idx_spirit_interactions_created_at ON spirit_interactions(created_at)'
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

        $configuration = new Configuration();
        $configuration->setSchemaManagerFactory(new DefaultSchemaManagerFactory());

        return DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $dbFullPath,
        ], $configuration);
    }

    public function deleteUserDatabase(User $user): void
    {
        if ($user->getDatabasePath()) {
            $dbFullPath = $this->getUserDatabaseFullPath($user);
            if (file_exists($dbFullPath)) {
                unlink($dbFullPath);
            }

            // server-sent events db
            $sseFullPath = $this->databasesDir . '/sse-' . $user->getDatabasePath();
            if (file_exists($sseFullPath)) {
                unlink($sseFullPath);
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
        $dbPath = $this->getUserDatabaseFullPath($user);
        
        // Run migrations for this user database using the Doctrine connection
        $this->runMigrationsForUserDatabase($connection, $dbPath);
    }
    
    /**
     * Run migrations for a specific user database
     * 
     * Based on the logic from runUserDbMigrations() in public/.update
     * @param \Doctrine\DBAL\Connection $userDb The database connection
     */
    private function runMigrationsForUserDatabase(\Doctrine\DBAL\Connection $userDb, string $dbPath): void
    {
        // Create migration versions table if not exists
        $userDb->executeStatement(
            'CREATE TABLE IF NOT EXISTS migration_versions ('
            . 'version VARCHAR(191) PRIMARY KEY,'
            . 'executed_at DATETIME DEFAULT NULL,'
            . 'execution_time INTEGER DEFAULT NULL'
            . ')'
        );
        
        // Scan for user migration files
        // Get project directory from the databases directory path
        $projectDir = dirname(dirname($this->databasesDir));
        $migrationsDir = $projectDir . '/migrations/user';
        if (!is_dir($migrationsDir)) {
            throw new \Exception('User migrations directory not found');
        }
        
        // Get all migration files
        $migrations = [];
        foreach (new \DirectoryIterator($migrationsDir) as $file) {
            if ($file->isDot() || $file->isDir()) continue;
            
            if (preg_match('/^Version(\d+)\.php$/', $file->getFilename(), $matches)) {
                $version = $matches[1];
                $migrations[$version] = $file->getPathname();
            }
        }
        
        // Sort migrations by version
        ksort($migrations);
        
        // Get list of applied migrations
        $appliedMigrations = [];
        $result = $userDb->executeQuery('SELECT version FROM migration_versions ORDER BY version ASC');
        while ($row = $result->fetchAssociative()) {
            $appliedMigrations[$row['version']] = true;
        }
        
        // Run new migrations
        foreach ($migrations as $version => $migrationFile) {
            $migrationVersion = 'UserMigration_' . $version;
            
            // Skip if already applied
            if (isset($appliedMigrations[$migrationVersion])) {
                continue;
            }
            
            // Include and instantiate migration class
            require_once $migrationFile;
            $className = 'UserMigration_' . $version;
            $migration = new $className();
            
            // Start transaction
            $userDb->beginTransaction();
            
            try {
                // Get start time
                $startTime = microtime(true);
                
                // Get the PDO connection for the migration
                // This is necessary because our migration classes expect a PDO connection
                $pdoConnection = $userDb->getWrappedConnection();
                if (method_exists($pdoConnection, 'getNativeConnection')) {
                    // For newer Doctrine DBAL versions
                    $pdoConnection = $pdoConnection->getNativeConnection();
                }
                
                // Run migration with PDO connection
                $migration->up($pdoConnection);
                
                // Calculate execution time
                $executionTime = round((microtime(true) - $startTime) * 1000);
                
                // Record migration
                $userDb->executeStatement(
                    'INSERT INTO migration_versions (version, executed_at, execution_time) VALUES (?, datetime("now"), ?)',
                    [$migrationVersion, $executionTime]
                );
                
                // Commit transaction
                $userDb->commit();
                
            } catch (\Exception $e) {
                // Rollback on error
                $userDb->rollBack();
                throw new \Exception("Migration Version{$version} failed: " . $e->getMessage());
            }
        }
    }
}
