<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class StorageService
{
    private string $projectDir;

    public function __construct(ParameterBagInterface $params)
    {
        $this->projectDir = $params->get('kernel.project_dir');
    }

    /**
     * Get the size of a directory in bytes
     */
    private function getDirectorySize(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    /**
     * Get the size of a file in bytes
     */
    private function getFileSize(string $path): int
    {
        if (!file_exists($path)) {
            return 0;
        }

        return filesize($path);
    }

    /**
     * Format bytes to human-readable size
     */
    public function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        } elseif ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        } elseif ($bytes < 1073741824) {
            return round($bytes / 1048576, 1) . ' MB';
        } else {
            return round($bytes / 1073741824, 2) . ' GB';
        }
    }

    /**
     * Get user data folder size
     * Path: var/user_data/{user.id}/*
     */
    public function getUserDataSize(User $user): array
    {
        $path = $this->projectDir . '/var/user_data/' . $user->getId();
        $bytes = $this->getDirectorySize($path);

        return [
            'bytes' => $bytes,
            'formatted' => $this->formatSize($bytes),
            'path' => $path
        ];
    }

    /**
     * Get user database size
     * Path: var/user_databases/{user.databasePath}
     */
    public function getUserDatabaseSize(User $user): array
    {
        $path = $this->projectDir . '/var/user_databases/' . basename($user->getDatabasePath());
        $bytes = $this->getFileSize($path);

        return [
            'bytes' => $bytes,
            'formatted' => $this->formatSize($bytes),
            'path' => $path
        ];
    }

    /**
     * Get user backups folder size
     * Path: var/user_backups/{user.id}/*
     */
    public function getUserBackupsSize(User $user): array
    {
        $path = $this->projectDir . '/var/user_backups/' . $user->getId();
        $bytes = $this->getDirectorySize($path);

        return [
            'bytes' => $bytes,
            'formatted' => $this->formatSize($bytes),
            'path' => $path
        ];
    }

    /**
     * Get total user storage size
     */
    public function getTotalUserStorageSize(User $user): array
    {
        $dataSize = $this->getUserDataSize($user);
        $dbSize = $this->getUserDatabaseSize($user);
        $backupsSize = $this->getUserBackupsSize($user);

        $totalBytes = $dataSize['bytes'] + $dbSize['bytes'] + $backupsSize['bytes'];

        return [
            'bytes' => $totalBytes,
            'formatted' => $this->formatSize($totalBytes),
            'data' => $dataSize,
            'database' => $dbSize,
            'backups' => $backupsSize
        ];
    }
}
