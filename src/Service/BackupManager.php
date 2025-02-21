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

        // Ensure required directories exist
        $projectDir = $this->params->get('kernel.project_dir');
        $requiredDirs = [
            $projectDir . '/var/tmp',
            $projectDir . '/var/user_backups',
            $projectDir . '/var/data'
        ];

        // Use umask to ensure proper permissions during directory creation
        $oldUmask = umask(0);
        try {
            foreach ($requiredDirs as $dir) {
                if (!is_dir($dir)) {
                    if (!mkdir($dir, 0755, true)) {
                        throw new \RuntimeException(sprintf('Failed to create directory: %s', $dir));
                    }
                }
                
                if (!is_writable($dir)) {
                    $this->logger->warning(sprintf('Directory %s is not writable. Some operations may fail.', $dir));
                }
            }
        } finally {
            umask($oldUmask);
        }

        $tmpDir = $projectDir . '/var/tmp';
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
     * Get schema information from the user's database
     */
    private function getCurrentSchemaInfo(): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \RuntimeException('User must be logged in to get schema info');
        }

        // Open the user's database directly with SQLite3
        $dbPath = $this->userDatabaseManager->getUserDatabaseFullPath($user);
        $db = new \SQLite3($dbPath);
        
        $tables = [];
        $result = $db->query("SELECT name FROM sqlite_master WHERE type='table'");
        
        while ($table = $result->fetchArray(SQLITE3_ASSOC)) {
            $tableName = $table['name'];
            $columns = [];
            $indexes = [];
            $primaryKey = [];
            
            // Get column info
            $tableInfo = $db->query("PRAGMA table_info('$tableName')");
            while ($column = $tableInfo->fetchArray(SQLITE3_ASSOC)) {
                $columns[$column['name']] = [
                    'type' => strtolower($column['type']),
                    'nullable' => $column['notnull'] == 0,
                    'default' => $column['dflt_value']
                ];
                
                if ($column['pk'] > 0) {
                    $primaryKey[] = $column['name'];
                }
            }
            
            // Get index info
            $indexList = $db->query("PRAGMA index_list('$tableName')");
            while ($index = $indexList->fetchArray(SQLITE3_ASSOC)) {
                $indexName = $index['name'];
                $indexInfo = $db->query("PRAGMA index_info('$indexName')");
                $indexColumns = [];
                
                while ($indexColumn = $indexInfo->fetchArray(SQLITE3_ASSOC)) {
                    $indexColumns[] = $indexColumn['name'];
                }
                
                $indexes[$indexName] = [
                    'columns' => $indexColumns,
                    'unique' => $index['unique'] == 1
                ];
            }
            
            $tables[$tableName] = [
                'columns' => $columns,
                'primary_key' => $primaryKey,
                'indexes' => $indexes
            ];
        }
        
        $db->close();
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
            $oldUmask = umask(0);
            try {
                if (!mkdir($backupDir, 0755, true)) {
                    throw new \RuntimeException('Failed to create backup directory');
                }
            } finally {
                umask($oldUmask);
            }
        }
        
        if (!is_writable($backupDir)) {
            throw new \RuntimeException('Backup directory is not writable: ' . $backupDir);
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

        $bakFile = '';

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
            if (version_compare($manifest['version'], self::MIN_COMPATIBLE_BACKUP_VERSION, '<')) {
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

            // Get table schemas from source database and recreate them
            $sourceDb = new \SQLite3($tempDir . '/user.db');
            $result = $sourceDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
            $backupTables = [];
            
            while ($table = $result->fetchArray(SQLITE3_ASSOC)) {
                $tableName = $table['name'];
                $columns = [];
                $primaryKey = [];
                
                // Get column information
                $columnInfo = $sourceDb->query("PRAGMA table_info(" . $tableName . ")");
                while ($column = $columnInfo->fetchArray(SQLITE3_ASSOC)) {
                    // Clean and normalize the type
                    $type = strtoupper($column['type']);
                    if (strpos($type, '(') !== false) {
                        // Extract base type and size/precision
                        preg_match('/([A-Z]+)\((\d+)\)/', $type, $matches);
                        if ($matches) {
                            $baseType = $matches[1];
                            $size = $matches[2];
                            $type = "{$baseType}({$size})";  // Rebuild with proper formatting
                        }
                    }
                    
                    $columns[$column['name']] = [
                        'type' => $type,
                        'nullable' => !$column['notnull'],
                        'default' => $column['dflt_value']
                    ];
                    
                    // Store primary key info
                    if ($column['pk'] > 0) {
                        $primaryKey[] = $column['name'];
                    }
                }
                
                // Create table SQL
                $columnDefs = [];
                foreach ($columns as $colName => $colInfo) {
                    $columnDefs[] = sprintf(
                        '"%s" %s%s%s',
                        $colName,
                        $colInfo['type'],
                        $colInfo['nullable'] ? '' : ' NOT NULL',
                        $colInfo['default'] !== null ? ' DEFAULT ' . $colInfo['default'] : ''
                    );
                }
                
                $createTable = sprintf(
                    'CREATE TABLE IF NOT EXISTS "%s" (%s%s)',
                    $tableName,
                    implode(', ', $columnDefs),
                    !empty($primaryKey) ? ', PRIMARY KEY("' . implode('", "', $primaryKey) . '")' : ''
                );
                
                // Execute create table
                $newDb->exec($createTable);
                
                // Get and recreate indexes
                $indexList = $sourceDb->query("PRAGMA index_list(" . $tableName . ")");
                while ($index = $indexList->fetchArray(SQLITE3_ASSOC)) {
                    if ($index['origin'] !== 'pk') { // Skip primary key indexes
                        $indexName = $index['name'];
                        $indexInfo = $sourceDb->query("PRAGMA index_info(" . $indexName . ")");
                        $indexColumns = [];
                        
                        while ($indexColumn = $indexInfo->fetchArray(SQLITE3_ASSOC)) {
                            $indexColumns[] = '"' . $indexColumn['name'] . '"';
                        }
                        
                        if (!empty($indexColumns)) {
                            $createIndex = sprintf(
                                'CREATE%sINDEX IF NOT EXISTS "%s" ON "%s" (%s)',
                                $index['unique'] ? ' UNIQUE ' : ' ',
                                $indexName,
                                $tableName,
                                implode(', ', $indexColumns)
                            );
                            $newDb->exec($createIndex);
                        }
                    }
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
                $bakFile = $currentDbPath . '.bak';
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
