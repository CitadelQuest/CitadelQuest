<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\Finder\Finder;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

class BackupManager
{
    private const BACKUP_VERSION = '1.0';
    private const SCHEMA_VERSION = '1.0';
    private const MIN_COMPATIBLE_BACKUP_VERSION = '1.0';
    private const BACKUP_FILENAME_FORMAT = 'backup_%s_%s.citadel';
    private const BACKUP_EXTENSION = '.citadel';

    public function __construct(
        private Security $security,
        private ParameterBagInterface $params,
        private RequestStack $requestStack,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private UserDatabaseManager $userDatabaseManager
    ) {}

    /**
     * Create a backup for the current user
     * 
     * @return string Path to the created backup file
     * @throws \RuntimeException if backup creation fails
     */
    public function createBackup(): string
    {
        $user = $this->security->getUser();
        if (!$user instanceof UserInterface) {
            throw new \RuntimeException('User must be logged in to create a backup');
        }

        // Ensure data directory exists
        $dataDir = $this->params->get('kernel.project_dir') . '/var/data';
        if (!is_dir($dataDir)) {
            if (!mkdir($dataDir, 0755, true)) {
                throw new \RuntimeException('Failed to create data directory');
            }
        }

        // Create temporary backup file
        $tmpDir = $this->params->get('kernel.project_dir') . '/var/tmp';
        if (!is_dir($tmpDir)) {
            if (!mkdir($tmpDir, 0755, true)) {
                throw new \RuntimeException('Failed to create temporary directory');
            }
        }
        $timestamp = date('Y-m-d_His');
        $username = $user->getUserIdentifier();
        $backupFile = sprintf('%s/' . self::BACKUP_FILENAME_FORMAT, $tmpDir, $username, $timestamp);

        try {
            // Create backup manifest
            $manifest = $this->createManifest($user);

            // Backup user's SQLite database
            $dbPath = $this->userDatabaseManager->getUserDatabaseFullPath($user);
            if (!file_exists($dbPath)) {
                throw new \RuntimeException('User database not found');
            }

            // Create temporary directory for backup
            $tempDir = $this->params->get('kernel.project_dir') . '/var/tmp/backup_staging_' . uniqid();
            if (!mkdir($tempDir, 0755, true)) {
                throw new \RuntimeException('Failed to create temporary directory');
            }

            try {
                // Copy database
                if (!copy($dbPath, $tempDir . '/user.db')) {
                    throw new \RuntimeException('Failed to copy user database');
                }

                // Save manifest
                file_put_contents($tempDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

                // Create backup archive
                $zip = new \ZipArchive();
                if ($zip->open($backupFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                    throw new \RuntimeException('Failed to create backup archive');
                }

                // Add files to archive
                $zip->addFile($tempDir . '/user.db', 'user.db');
                $zip->addFile($tempDir . '/manifest.json', 'manifest.json');
                
                // Add user preferences and settings if they exist
                $prefsPath = $this->params->get('kernel.project_dir') . '/var/data/' . $user->getUserIdentifier() . '_prefs.json';
                if (file_exists($prefsPath)) {
                    $zip->addFile($prefsPath, 'preferences.json');
                }

                $zip->close();

                // Verify backup
                if (!$this->verifyBackup($backupFile)) {
                    throw new \RuntimeException('Backup verification failed');
                }

                // Store in user's backup directory
                $storedPath = $this->storeBackup($backupFile, $user);
                return $storedPath;
            } finally {
                // Clean up temporary directory
                $this->removeDirectory($tempDir);
            }
        } catch (\Exception $e) {
            if (isset($backupFile) && file_exists($backupFile)) {
                unlink($backupFile);
            }
            throw new \RuntimeException('Backup creation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Verify the integrity of a backup file
     */
    private function verifyBackup(string $backupFile): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($backupFile) !== true) {
            return false;
        }

        // Check for required files
        $requiredFiles = ['user.db', 'manifest.json'];
        foreach ($requiredFiles as $file) {
            if ($zip->locateName($file) === false) {
                $zip->close();
                return false;
            }
        }

        // Verify manifest structure
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        if (!$manifest || !isset($manifest['version']) || !isset($manifest['timestamp'])) {
            $zip->close();
            return false;
        }

        $zip->close();
        return true;
    }

    /**
     * Create backup manifest with metadata
     */
    private function createManifest(UserInterface $user): array
    {
        if (!$user instanceof User) {
            throw new \RuntimeException('Invalid user type');
        }

        $request = $this->requestStack->getCurrentRequest();
        $instanceUrl = $request ? $request->getSchemeAndHttpHost() : 'unknown';

        return [
            'version' => self::BACKUP_VERSION,
            'schema_version' => self::SCHEMA_VERSION,
            'timestamp' => time(),
            'user_identifier' => $user->getUserIdentifier(),
            'user_id' => $user->getId()->__toString(),
            'citadel_version' => $this->params->get('app.version'),
            'backup_format' => 'citadel_backup_v1',
            'instance_url' => $instanceUrl,
            'compatibility' => [
                'min_version' => self::MIN_COMPATIBLE_BACKUP_VERSION,
                'max_version' => self::BACKUP_VERSION,
                'requires_migration' => self::SCHEMA_VERSION !== self::BACKUP_VERSION
            ],
            'contents' => [
                'user.db' => 'SQLite user database',
                'manifest.json' => 'Backup metadata',
                'preferences.json' => 'User preferences and settings (if exists)'
            ],
            'schema_info' => [
                'tables' => $this->getCurrentSchemaInfo()
            ]
        ];
    }

    /**
     * Store backup in user's backup directory
     */
    private function getCurrentSchemaInfo(): array
    {
        $connection = $this->entityManager->getConnection();
        $schemaManager = $connection->createSchemaManager();
        $tables = [];

        foreach ($schemaManager->listTables() as $table) {
            $columns = [];
            foreach ($table->getColumns() as $column) {
                $columns[$column->getName()] = [
                    'type' => $column->getType()->getName(),
                    'nullable' => !$column->getNotnull(),
                    'default' => $column->getDefault()
                ];
            }

            $tables[$table->getName()] = [
                'columns' => $columns,
                'primary_key' => $table->getPrimaryKey() ? $table->getPrimaryKey()->getColumns() : [],
                'indexes' => array_map(fn($idx) => [
                    'columns' => $idx->getColumns(),
                    'unique' => $idx->isUnique()
                ], $table->getIndexes())
            ];
        }

        return $tables;
    }

    private function importTableData(\SQLite3 $sourceDb, \SQLite3 $targetDb, string $table, array $columnMap): void
    {
        // Begin transaction
        $targetDb->exec('BEGIN TRANSACTION');

        try {
            // Prepare column lists
            $sourceColumns = implode(', ', array_keys($columnMap));
            $targetColumns = implode(', ', array_values($columnMap));
            $placeholders = implode(', ', array_fill(0, count($columnMap), '?'));

            // Prepare statements
            $selectStmt = $sourceDb->prepare("SELECT {$sourceColumns} FROM {$table}");
            $insertStmt = $targetDb->prepare("INSERT INTO {$table} ({$targetColumns}) VALUES ({$placeholders})");

            $result = $selectStmt->execute();
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                // Bind parameters
                foreach ($columnMap as $sourceCol => $targetCol) {
                    $insertStmt->bindValue(
                        array_search($targetCol, array_values($columnMap)) + 1,
                        $row[$sourceCol],
                        is_numeric($row[$sourceCol]) ? SQLITE3_NUM : SQLITE3_TEXT
                    );
                }
                $insertStmt->execute();
                $insertStmt->reset();
            }

            // Commit transaction
            $targetDb->exec('COMMIT');
        } catch (\Exception $e) {
            // Rollback on error
            $targetDb->exec('ROLLBACK');
            throw $e;
        }
    }

    private function createAutoBackup(): string
    {
        $backupPath = $this->createBackup();
        $autoBackupPath = str_replace(self::BACKUP_EXTENSION, "_auto" . self::BACKUP_EXTENSION, $backupPath);
        if (!rename($backupPath, $autoBackupPath)) {
            throw new \RuntimeException('Failed to rename auto-backup file');
        }
        return $autoBackupPath;
    }

    private function storeBackup(string $backupPath, User $user): string
    {
        $backupDir = sprintf('%s/%s', 
            $this->params->get('app.backup_dir'),
            $user->getId()
        );

        // Create backup directory if it doesn't exist
        if (!is_dir($backupDir)) {
            if (!mkdir($backupDir, 0755, true)) {
                throw new \RuntimeException('Failed to create backup directory');
            }
        }

        $storedBackupPath = $backupDir . '/' . basename($backupPath);
        if (!copy($backupPath, $storedBackupPath)) {
            throw new \RuntimeException('Failed to store backup file');
        }

        return $storedBackupPath;
    }

    /**
     * Restore a backup file
     * @throws \RuntimeException if restore fails
     */
    public function restoreBackup(string $filename): void
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \RuntimeException('User must be logged in to restore a backup');
        }

        // Get backup file path
        $backupDir = sprintf('%s/%s', $this->params->get('app.backup_dir'), $user->getId());
        $backupPath = $backupDir . '/' . $filename;

        if (!file_exists($backupPath)) {
            throw new \RuntimeException('Backup file not found');
        }

        // Create temporary directory for restore
        $tempDir = sys_get_temp_dir() . '/citadel_restore_' . uniqid();
        if (!mkdir($tempDir, 0755, true)) {
            throw new \RuntimeException('Failed to create temporary directory');
        }

        try {
            // Extract backup
            $zip = new \ZipArchive();
            if ($zip->open($backupPath) !== true) {
                throw new \RuntimeException('Failed to open backup archive');
            }

            $zip->extractTo($tempDir);
            $zip->close();

            // Read and validate manifest
            $manifest = json_decode(file_get_contents($tempDir . '/manifest.json'), true);
            if (!$manifest) {
                throw new \RuntimeException('Invalid backup manifest');
            }

            // Version compatibility check
            if (version_compare($manifest['version'], self::MIN_COMPATIBLE_VERSION, '<')) {
                throw new \RuntimeException('Backup version too old');
            }
            if (version_compare($manifest['version'], self::BACKUP_VERSION, '>')) {
                throw new \RuntimeException('Backup is from a newer version');
            }

            // Create auto-backup before restore
            $autoBackupPath = $this->createAutoBackup();
            $this->logger->info("Created auto-backup before restore: {$autoBackupPath}");

            // Create new database with current schema
            $tempDbPath = $tempDir . '/new_user.db';
            $newDb = new \SQLite3($tempDbPath);

            // Get current schema SQL
            $connection = $this->entityManager->getConnection();
            $schemaManager = $connection->createSchemaManager();
            $currentSchema = $schemaManager->introspectSchema();

            // Get table schemas from source database
            $sourceDb = new \SQLite3($tempDir . '/user.db');
            $result = $sourceDb->query("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $newDb->exec($row['sql']);
            }

            // Open source database
            $sourceDb = new \SQLite3($tempDir . '/user.db');

            // Get current schema info
            $currentTables = $this->getCurrentSchemaInfo();

            // Open source database to analyze its schema
            $sourceDb = new \SQLite3($tempDir . '/user.db');
            
            // Get table list from source database
            $result = $sourceDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
            $backupTables = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $tableName = $row['name'];
                $columns = [];
                $columnResult = $sourceDb->query("PRAGMA table_info(" . $tableName . ")");
                while ($columnRow = $columnResult->fetchArray(SQLITE3_ASSOC)) {
                    $columns[$columnRow['name']] = [
                        'type' => $columnRow['type'],
                        'nullable' => !$columnRow['notnull'],
                        'default' => $columnRow['dflt_value']
                    ];
                }
                $backupTables[$tableName] = ['columns' => $columns];
            }

            // Import data table by table
            foreach ($backupTables as $tableName => $tableInfo) {
                $this->logger->info("Importing data for table: {$tableName}");

                // Create column mapping
                $columnMap = [];
                foreach ($tableInfo['columns'] as $colName => $colInfo) {
                    if (isset($backupTables[$tableName]['columns'][$colName])) {
                        $columnMap[$colName] = $colName;
                    }
                }

                if (!empty($columnMap)) {
                    $this->importTableData($sourceDb, $newDb, $tableName, $columnMap);
                }
            }

            $sourceDb->close();
            $newDb->close();

            // Backup current database
            $currentDbPath = $this->userDatabaseManager->getUserDatabaseFullPath($user);
            if (file_exists($currentDbPath)) {
                $backupDbPath = $currentDbPath . '.bak';
                if (!copy($currentDbPath, $backupDbPath)) {
                throw new \RuntimeException('Failed to create backup copy of database');
            }
            }

            // Replace current database with new one
            if (file_exists($currentDbPath)) {
                unlink($currentDbPath);
            }
            if (!copy($tempDbPath, $currentDbPath)) {
                throw new \RuntimeException('Failed to replace current database');
            }

            // Restore preferences if they exist
            $prefsPath = $this->params->get('kernel.project_dir') . '/var/data/' . $user->getUserIdentifier() . '_prefs.json';
            if (file_exists($tempDir . '/preferences.json')) {
                if (file_exists($prefsPath)) {
                    unlink($prefsPath);
                }
                if (!copy($tempDir . '/preferences.json', $prefsPath)) {
                    throw new \RuntimeException('Failed to restore preferences');
                }
            }

            $this->logger->info('Backup restored successfully');
        } catch (\Exception $e) {
            $this->logger->error('Backup restore failed: ' . $e->getMessage());
            throw new \RuntimeException('Failed to restore backup: ' . $e->getMessage(), 0, $e);
        } finally {
            // Cleanup
            if (isset($tempDir) && is_dir($tempDir)) {
                $this->removeDirectory($tempDir);
            }
            
            // Remove .bak file since we have an auto-backup
            $bakFile = $currentDbPath . '.bak';
            if (file_exists($bakFile)) {
                unlink($bakFile);
            }
        }
    }

    /**
     * Get list of user's backups
     * @return array Array of backup info with timestamp and path
     */
    public function getUserBackups(): array
    {
        /** @var User $user */
        $user = $this->security->getUser();
        if (!$user) {
            throw new \RuntimeException('User not found');
        }

        $backupDir = sprintf('%s/%s', 
            $this->params->get('app.backup_dir'),
            $user->getId()
        );

        if (!is_dir($backupDir)) {
            return [];
        }

        $backups = [];
        $finder = new Finder();
        $finder->files()
            ->in($backupDir)
            ->name('*' . self::BACKUP_EXTENSION)
            ->sortByModifiedTime();

        foreach ($finder as $file) {
            $backups[] = [
                'filename' => $file->getFilename(),
                'timestamp' => $file->getMTime(),
                'size' => $file->getSize(),
                'path' => $file->getRealPath()
            ];
        }

        return array_reverse($backups); // Newest first
    }

    /**
     * Delete a backup file
     * 
     * @param string $filename Name of the backup file to delete
     * @throws \RuntimeException if deletion fails or file not found
     */
    /**
     * Helper method to recursively remove a directory
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    public function deleteBackup(string $filename): void
    {
        // Verify user is logged in
        $user = $this->security->getUser();
        if (!$user instanceof UserInterface) {
            throw new \RuntimeException('User must be logged in to delete a backup');
        }

        $backupDir = sprintf('%s/%s', 
            $this->params->get('app.backup_dir'),
            $user->getId()
        );
        $backupPath = $backupDir . '/' . $filename;
        
        // Verify file exists
        if (!file_exists($backupPath)) {
            throw new \RuntimeException('Backup file not found');
        }

        // Verify file belongs to current user by checking if it's in their backup list
        $userBackups = $this->getUserBackups();
        $found = false;
        foreach ($userBackups as $backup) {
            if ($backup['filename'] === $filename) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            throw new \RuntimeException('Backup file not found in user\'s backups');
        }

        // Delete the file
        if (!unlink($backupPath)) {
            throw new \RuntimeException('Failed to delete backup file');
        }
    }


}
