<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Finder\Finder;
use Psr\Log\LoggerInterface;

/**
 * Simplified backup manager for CitadelQuest
 * 
 * Backup contains:
 * - user.db: User's SQLite database (as-is)
 * - user_data/: User's files directory (if exists)
 * 
 * Restore process:
 * 1. Extract ZIP directly
 * 2. Run migrations to update schema if needed
 */
class BackupManager
{
    private const BACKUP_FILENAME_FORMAT = 'backup_%s_%s.citadel';
    private const BACKUP_EXTENSION = '.citadel';

    public function __construct(
        private Security $security,
        private ParameterBagInterface $params,
        private LoggerInterface $logger,
        private UserDatabaseManager $userDatabaseManager
    ) {}

    /**
     * Create a backup for the current user
     */
    public function createBackup(): string
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \RuntimeException('User must be logged in to create a backup');
        }

        $projectDir = $this->params->get('kernel.project_dir');
        $varDir = $projectDir . '/var';
        
        // Ensure backup directory exists
        $backupDir = $this->params->get('app.backup_dir') . '/' . $user->getId();
        $this->ensureDirectoryExists($backupDir);

        // Prepare backup file path
        $timestamp = date('Y-m-d_His');
        $username = $user->getUserIdentifier();
        $backupFile = sprintf('%s/' . self::BACKUP_FILENAME_FORMAT, $backupDir, $username, $timestamp);

        try {
            $dbPath = $this->userDatabaseManager->getUserDatabaseFullPath($user);
            $userDataDir = $varDir . '/user_data/' . $user->getId();

            if (!file_exists($dbPath)) {
                throw new \RuntimeException('User database not found');
            }

            $zip = new \ZipArchive();
            if ($zip->open($backupFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Failed to create backup archive');
            }

            // Add database file
            $zip->addFile($dbPath, 'user.db');

            // Add user_data directory if exists
            if (is_dir($userDataDir)) {
                $this->addDirectoryToZip($zip, $userDataDir, 'user_data');
            }

            $zip->close();

            if (!$this->verifyBackup($backupFile)) {
                throw new \RuntimeException('Backup verification failed');
            }

            $this->logger->info("Backup created: {$backupFile}");
            return $backupFile;

        } catch (\Exception $e) {
            if (isset($backupFile) && file_exists($backupFile)) {
                unlink($backupFile);
            }
            throw new \RuntimeException('Backup creation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Restore a backup file
     */
    public function restoreBackup(string $filename): void
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \RuntimeException('User must be logged in to restore a backup');
        }

        $backupDir = $this->params->get('app.backup_dir') . '/' . $user->getId();
        $backupPath = $backupDir . '/' . $filename;

        if (!file_exists($backupPath)) {
            throw new \RuntimeException('Backup file not found');
        }

        $projectDir = $this->params->get('kernel.project_dir');
        $varDir = $projectDir . '/var';

        try {
            // Create auto-backup before restore
            //$autoBackupPath = $this->createAutoBackup();
            //$this->logger->info("Created auto-backup before restore: {$autoBackupPath}");

            $zip = new \ZipArchive();
            if ($zip->open($backupPath) !== true) {
                throw new \RuntimeException('Failed to open backup archive');
            }

            if ($zip->locateName('user.db') === false) {
                $zip->close();
                throw new \RuntimeException('Invalid backup: user.db not found');
            }

            $currentDbPath = $this->userDatabaseManager->getUserDatabaseFullPath($user);
            $userDataDir = $varDir . '/user_data/' . $user->getId();

            // Extract user.db using stream to avoid memory issues with large files
            $dbIndex = $zip->locateName('user.db');
            $dbStream = $zip->getStream('user.db');
            if ($dbStream === false) {
                $zip->close();
                throw new \RuntimeException('Failed to read user.db from backup');
            }

            $targetStream = fopen($currentDbPath, 'wb');
            if ($targetStream === false) {
                fclose($dbStream);
                $zip->close();
                throw new \RuntimeException('Failed to open target database for writing');
            }

            // Stream copy - memory efficient for large files
            while (!feof($dbStream)) {
                $chunk = fread($dbStream, 8192); // 8KB chunks
                fwrite($targetStream, $chunk);
            }

            fclose($dbStream);
            fclose($targetStream);

            // Extract user_data directory if exists in backup
            $this->extractUserDataFromZip($zip, $userDataDir);

            $zip->close();

            // Run migrations to update schema if needed
            $this->userDatabaseManager->updateDatabaseSchema($user);

            $this->logger->info('Backup restored successfully');

        } catch (\Exception $e) {
            $this->logger->error('Backup restore failed: ' . $e->getMessage());
            throw new \RuntimeException('Failed to restore backup: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get list of user's backups
     */
    public function getUserBackups(): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \RuntimeException('User not found');
        }

        $backupDir = $this->params->get('app.backup_dir') . '/' . $user->getId();

        if (!is_dir($backupDir)) {
            return [];
        }

        $backups = [];
        $finder = new Finder();
        $finder->files()->in($backupDir)->name('*' . self::BACKUP_EXTENSION)->sortByModifiedTime();

        foreach ($finder as $file) {
            $backups[] = [
                'filename' => $file->getFilename(),
                'timestamp' => $file->getMTime(),
                'size' => $file->getSize(),
                'path' => $file->getRealPath()
            ];
        }

        return array_reverse($backups);
    }

    /**
     * Upload and save a backup file
     * 
     * @param \Symfony\Component\HttpFoundation\File\UploadedFile $uploadedFile
     * @return array Information about the uploaded backup
     */
    public function uploadBackup(\Symfony\Component\HttpFoundation\File\UploadedFile $uploadedFile): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \RuntimeException('User must be logged in to upload a backup');
        }

        // Validate file extension
        $originalName = $uploadedFile->getClientOriginalName();
        if (!str_ends_with(strtolower($originalName), self::BACKUP_EXTENSION)) {
            throw new \InvalidArgumentException('Invalid file format. Only .citadel files are accepted.');
        }

        // Validate file size (1000MB max)
        $maxSize = 1048576000; // 1000MB
        if ($uploadedFile->getSize() > $maxSize) {
            throw new \InvalidArgumentException('File is too large. Maximum size is 1000MB.');
        }

        // Ensure backup directory exists
        $backupDir = $this->params->get('app.backup_dir') . '/' . $user->getId();
        $this->ensureDirectoryExists($backupDir);

        // Generate unique filename to avoid conflicts
        $filename = $this->generateUniqueFilename($backupDir, $originalName);
        $targetPath = $backupDir . '/' . $filename;

        try {
            // Move uploaded file to backup directory
            $uploadedFile->move($backupDir, $filename);

            // Verify the backup is valid
            if (!$this->verifyBackup($targetPath)) {
                // Remove invalid backup
                if (file_exists($targetPath)) {
                    unlink($targetPath);
                }
                throw new \RuntimeException('Invalid backup file: user.db not found in archive');
            }

            $this->logger->info("Backup uploaded: {$targetPath}");

            return [
                'filename' => $filename,
                'size' => filesize($targetPath),
                'timestamp' => filemtime($targetPath)
            ];

        } catch (\Exception $e) {
            // Clean up on error
            if (isset($targetPath) && file_exists($targetPath)) {
                unlink($targetPath);
            }
            throw new \RuntimeException('Backup upload failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Generate unique filename for uploaded backup
     */
    private function generateUniqueFilename(string $directory, string $originalName): string
    {
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        $extension = self::BACKUP_EXTENSION;
        
        // If file doesn't exist, use original name
        if (!file_exists($directory . '/' . $originalName)) {
            return $originalName;
        }
        
        // Otherwise, add timestamp to make it unique
        $timestamp = date('Y-m-d_His');
        return $baseName . '_' . $timestamp . $extension;
    }

    /**
     * Delete a backup file
     */
    public function deleteBackup(string $filename): void
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new \RuntimeException('User must be logged in to delete a backup');
        }

        $backupDir = $this->params->get('app.backup_dir') . '/' . $user->getId();
        $backupPath = $backupDir . '/' . $filename;
        
        if (!file_exists($backupPath)) {
            throw new \RuntimeException('Backup file not found');
        }

        // Verify file belongs to current user
        $userBackups = $this->getUserBackups();
        $found = array_filter($userBackups, fn($b) => $b['filename'] === $filename);
        if (empty($found)) {
            throw new \RuntimeException('Backup file not found in user\'s backups');
        }

        if (!unlink($backupPath)) {
            throw new \RuntimeException('Failed to delete backup file');
        }
    }

    /**
     * Verify backup contains required files
     */
    private function verifyBackup(string $backupFile): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($backupFile) !== true) {
            return false;
        }

        $hasDb = $zip->locateName('user.db') !== false;
        $zip->close();

        return $hasDb;
    }

    /**
     * Create auto-backup before restore
     */
    private function createAutoBackup(): string
    {
        $backupPath = $this->createBackup();
        $autoBackupPath = str_replace(self::BACKUP_EXTENSION, '_auto' . self::BACKUP_EXTENSION, $backupPath);
        if (!rename($backupPath, $autoBackupPath)) {
            throw new \RuntimeException('Failed to rename auto-backup file');
        }
        return $autoBackupPath;
    }

    /**
     * Add directory recursively to ZIP
     */
    private function addDirectoryToZip(\ZipArchive $zip, string $dir, string $zipPath): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = $zipPath . '/' . substr($filePath, strlen($dir) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }
    }

    /**
     * Extract user_data from ZIP to target directory
     */
    private function extractUserDataFromZip(\ZipArchive $zip, string $targetDir): void
    {
        // Check if backup contains user_data
        $hasUserData = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (strpos($zip->getNameIndex($i), 'user_data/') === 0) {
                $hasUserData = true;
                break;
            }
        }

        if (!$hasUserData) {
            return;
        }

        // Clear existing user_data directory
        if (is_dir($targetDir)) {
            $this->removeDirectory($targetDir);
        }
        $this->ensureDirectoryExists($targetDir);

        // Extract user_data files
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (strpos($name, 'user_data/') === 0) {
                $relativePath = substr($name, strlen('user_data/'));
                if (empty($relativePath)) {
                    continue;
                }

                $targetPath = $targetDir . '/' . $relativePath;
                
                // Create directory if needed
                $dir = dirname($targetPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }

                // Extract file
                if (substr($name, -1) !== '/') {
                    $content = $zip->getFromIndex($i);
                    file_put_contents($targetPath, $content);
                }
            }
        }
    }

    /**
     * Ensure directory exists
     */
    private function ensureDirectoryExists(string $dir): void
    {
        if (!is_dir($dir)) {
            $oldUmask = umask(0);
            if (!mkdir($dir, 0755, true)) {
                umask($oldUmask);
                throw new \RuntimeException("Failed to create directory: {$dir}");
            }
            umask($oldUmask);
        }
    }

    /**
     * Remove directory recursively
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
