<?php

namespace App\Service;

use App\Entity\ProjectFile;
use App\Entity\ProjectFileVersion;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Service for managing project files
 */
class ProjectFileService
{
    private const ALLOWED_MIME_TYPES = [
        // Text
        'text/plain', 'text/html', 'text/css', 'text/javascript', 'text/markdown', 'text/csv',
        // Documents
        'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        // Images, icons
        'image/jpeg', 'image/png', 'image/gif', 'image/svg+xml', 'image/webp', 'image/ico', 'image/bmp', 'image/avif', 'image/tiff', 'image/vnd.microsoft.icon', 'image/x-icon', 'image/*',
        // Archives
        'application/zip', 'application/x-rar-compressed', 'application/x-tar', 'application/gzip',
        // Data
        'application/json', 'application/xml',
        // Audio
        'audio/mpeg', 'audio/ogg', 'audio/wav',
        // Video
        'video/mp4', 'video/webm', 'video/ogg',
    ];
    
    private const MAX_FILE_SIZE = 209715200; // 200MB
    
    /**
     * @var string
     */
    private string $projectRootDir;
    
    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly Security $security,
        private readonly ParameterBagInterface $params
    ) {
        $this->projectRootDir = $this->params->get('kernel.project_dir') . '/var/user_data/' . $this->security->getUser()->getId() . '/p';
    }
    
    /**
     * Get a fresh database connection for the current user
     */
    private function getUserDb()
    {
        /** @var User $user */
        $user = $this->security->getUser();
        return $this->userDatabaseManager->getDatabaseConnection($user);
    }
    
    /**
     * Get the absolute path to a project directory
     */
    private function getProjectDir(string $projectId): string
    {
        return $this->projectRootDir . '/' . $projectId;
    }
    
    /**
     * Get the absolute path to a file within a project
     */
    private function getAbsoluteFilePath(string $projectId, string $path, string $name): string
    {
        $projectDir = $this->getProjectDir($projectId);
        $relativePath = ltrim($path, '/');
        
        if ($relativePath) {
            return $projectDir . '/' . $relativePath . '/' . $name;
        }
        
        return $projectDir . '/' . $name;
    }
    
    /**
     * Validate a file path to prevent directory traversal
     */
    private function validatePath(string $path): bool
    {
        // Prevent directory traversal
        if (strpos($path, '..') !== false) {
            return false;
        }
        
        // Ensure path is within allowed structure
        $normalizedPath = $this->normalizePath($path);
        
        // Additional validation can be added here
        
        return true;
    }
    
    /**
     * Normalize a file path
     */
    private function normalizePath(string $path): string
    {
        // Ensure path starts with /
        if (empty($path) || $path === '.') {
            return '/';
        }
        
        // Ensure path starts with /
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        
        // Remove trailing slash if not root
        if ($path !== '/' && substr($path, -1) === '/') {
            $path = substr($path, 0, -1);
        }
        
        return $path;
    }
    
    /**
     * Create a directory in a project
     */
    public function createDirectory(string $projectId, string $path, string $name): ProjectFile
    {
        // Ensure project directory structure exists
        $this->ensureProjectDirectoryStructure($projectId);
        
        // Normalize and validate path
        $path = $this->normalizePath($path);
        if (!$this->validatePath($path)) {
            throw new \InvalidArgumentException('Invalid path');
        }
        
        // Create directory in filesystem
        $dirPath = $this->getAbsoluteFilePath($projectId, $path, $name);
        
        // Use umask to ensure proper permissions during directory creation
        $oldUmask = umask(0);
        try {
            if (!is_dir($dirPath)) {
                if (!mkdir($dirPath, 0755, true)) {
                    throw new \RuntimeException(sprintf('Failed to create directory: %s', $dirPath));
                }
            }
            
            if (!is_writable($dirPath)) {
                throw new \RuntimeException(sprintf('Directory %s is not writable', $dirPath));
            }
        } finally {
            umask($oldUmask);
        }
        
        // Create directory record in database
        $directory = new ProjectFile(
            $projectId,
            $path,
            $name,
            'directory',
            true
        );
        
        $userDb = $this->getUserDb();
        $userDb->executeStatement(
            'INSERT INTO project_file (id, project_id, path, name, type, is_directory, created_at, updated_at) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $directory->getId(),
                $directory->getProjectId(),
                $directory->getPath(),
                $directory->getName(),
                $directory->getType(),
                $directory->isDirectory() ? 1 : 0,
                $directory->getCreatedAt()->format('Y-m-d H:i:s'),
                $directory->getUpdatedAt()->format('Y-m-d H:i:s')
            ]
        );
        
        return $directory;
    }
    
    /**
     * Create file with content
     */
    public function createFile(string $projectId, string $path, string $name, string $content, ?string $mimeType = null): ProjectFile
    {
        // Ensure project directory structure exists
        $this->ensureProjectDirectoryStructure($projectId);

        // Normalize and validate path
        $path = $this->normalizePath($path);
        if (!$this->validatePath($path)) {
            throw new \InvalidArgumentException('Invalid path');
        }
        
        // Determine file type from name
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $type = $extension ?: 'txt';
        
        // Validate mime type if provided
        if ($mimeType && !in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
            throw new \InvalidArgumentException('Unsupported file type: ' . $mimeType);
        }
        
        // Validate file size
        if (strlen($content) > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('File size exceeds maximum allowed size ' . self::MAX_FILE_SIZE);
        }
        
        // Ensure parent directory exists
        $parentDir = $this->getAbsoluteFilePath($projectId, $path, '');
        if (!is_dir($parentDir)) {
            $oldUmask = umask(0);
            try {
                if (!mkdir($parentDir, 0755, true)) {
                    throw new \RuntimeException(sprintf('Failed to create directory: %s', $parentDir));
                }
            } finally {
                umask($oldUmask);
            }
        }
        
        // Create file in filesystem
        $filePath = $this->getAbsoluteFilePath($projectId, $path, $name);
        try {
            if (file_put_contents($filePath, $content) === false) {
                throw new \RuntimeException(sprintf('Failed to create file: %s', $filePath));
            }
        } catch (\Exception $exception) {
            throw new \RuntimeException('Failed to create file: ' . $exception->getMessage());
        }
        
        // Create file record in database
        $file = new ProjectFile(
            $projectId,
            $path,
            $name,
            $type,
            false,
            $mimeType,
            strlen($content)
        );
        
        $userDb = $this->getUserDb();
        $userDb->executeStatement(
            'INSERT INTO project_file (id, project_id, path, name, type, mime_type, size, is_directory, created_at, updated_at) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $file->getId(),
                $file->getProjectId(),
                $file->getPath(),
                $file->getName(),
                $file->getType(),
                $file->getMimeType(),
                $file->getSize(),
                $file->isDirectory() ? 1 : 0,
                $file->getCreatedAt()->format('Y-m-d H:i:s'),
                $file->getUpdatedAt()->format('Y-m-d H:i:s')
            ]
        );
        
        // Create initial version
        $this->createFileVersion($file->getId(), $file->getSize(), hash('sha256', $content));
        
        return $file;
    }
    
    /**
     * Upload a file to a project
     */
    public function uploadFile(string $projectId, string $path, UploadedFile $uploadedFile): ProjectFile
    {
        // Ensure project directory structure exists
        $this->ensureProjectDirectoryStructure($projectId);

        // Normalize and validate path
        $path = $this->normalizePath($path);
        if (!$this->validatePath($path)) {
            throw new \InvalidArgumentException('Invalid path');
        }
        
        // Store file information before moving the file
        $fileSize = $uploadedFile->getSize();
        $mimeType = $uploadedFile->getMimeType();
        $name = $uploadedFile->getClientOriginalName();
        $extension = $uploadedFile->getClientOriginalExtension();
        $type = $extension ?: 'file';
        
        // Validate file size
        if ($fileSize > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('File size exceeds maximum allowed size ' . self::MAX_FILE_SIZE);
        }
        
        // Validate mime type
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
            throw new \InvalidArgumentException('Unsupported file type: ' . $mimeType);
        }
        
        // Ensure parent directory exists
        $parentDir = $this->getAbsoluteFilePath($projectId, $path, '');
        if (!is_dir($parentDir)) {
            $oldUmask = umask(0);
            try {
                if (!mkdir($parentDir, 0755, true)) {
                    throw new \RuntimeException(sprintf('Failed to create directory: %s', $parentDir));
                }
            } finally {
                umask($oldUmask);
            }
        }
        
        // Move uploaded file to project directory
        $filePath = $this->getAbsoluteFilePath($projectId, $path, $name);
        try {
            $uploadedFile->move(dirname($filePath), $name);
        } catch (\Exception $exception) {
            throw new \RuntimeException('Failed to upload file: ' . $exception->getMessage());
        }
        
        // Verify the file was moved successfully
        if (!file_exists($filePath)) {
            throw new \RuntimeException('File was not uploaded successfully');
        }
        
        // Create file record in database
        $file = new ProjectFile(
            $projectId,
            $path,
            $name,
            $type,
            false,
            $mimeType,
            $fileSize
        );
        
        $userDb = $this->getUserDb();
        $userDb->executeStatement(
            'INSERT INTO project_file (id, project_id, path, name, type, mime_type, size, is_directory, created_at, updated_at) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $file->getId(),
                $file->getProjectId(),
                $file->getPath(),
                $file->getName(),
                $file->getType(),
                $file->getMimeType(),
                $file->getSize(),
                $file->isDirectory() ? 1 : 0,
                $file->getCreatedAt()->format('Y-m-d H:i:s'),
                $file->getUpdatedAt()->format('Y-m-d H:i:s')
            ]
        );
        
        // Create initial version
        $content = file_get_contents($filePath);
        $this->createFileVersion($file->getId(), $file->getSize(), hash('sha256', $content));
        
        return $file;
    }
    
    /**
     * Update file content
     */
    public function updateFile(string $fileId, string $content): ProjectFile
    {
        // Find file
        $file = $this->findById($fileId);
        if (!$file) {
            throw new \InvalidArgumentException('File not found');
        }
        
        // Validate file size
        if (strlen($content) > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('File size exceeds maximum allowed size');
        }
        
        // Update file in filesystem
        $filePath = $this->getAbsoluteFilePath($file->getProjectId(), $file->getPath(), $file->getName());
        try {
            if (file_put_contents($filePath, $content) === false) {
                throw new \RuntimeException(sprintf('Failed to update file: %s', $filePath));
            }
        } catch (\Exception $exception) {
            throw new \RuntimeException('Failed to update file: ' . $exception->getMessage());
        }
        
        // Update file record in database
        $file->setSize(strlen($content));
        $file->updateUpdatedAt();
        
        $userDb = $this->getUserDb();
        $userDb->executeStatement(
            'UPDATE project_file SET size = ?, updated_at = ? WHERE id = ?',
            [
                $file->getSize(),
                $file->getUpdatedAt()->format('Y-m-d H:i:s'),
                $file->getId()
            ]
        );
        
        // Create new version
        $this->createFileVersion($file->getId(), $file->getSize(), hash('sha256', $content));
        
        return $file;
    }
    
    /**
     * Create a new file version
     */
    private function createFileVersion(string $fileId, ?int $size, ?string $hash): ProjectFileVersion
    {
        // Get latest version number
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT MAX(version) as max_version FROM project_file_version WHERE file_id = ?',
            [$fileId]
        )->fetchAssociative();
        
        $version = 1;
        if ($result && isset($result['max_version'])) {
            $version = (int) $result['max_version'] + 1;
        }
        
        // Create new version
        $fileVersion = new ProjectFileVersion(
            $fileId,
            $version,
            $size,
            $hash
        );
        
        $userDb->executeStatement(
            'INSERT INTO project_file_version (id, file_id, version, size, hash, created_at) 
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $fileVersion->getId(),
                $fileVersion->getFileId(),
                $fileVersion->getVersion(),
                $fileVersion->getSize(),
                $fileVersion->getHash(),
                $fileVersion->getCreatedAt()->format('Y-m-d H:i:s')
            ]
        );
        
        return $fileVersion;
    }
    
    /**
     * Find a file by ID
     */
    public function findById(string $id): ?ProjectFile
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT * FROM project_file WHERE id = ?',
            [$id]
        )->fetchAssociative();
        
        if (!$result) {
            return null;
        }
        
        return ProjectFile::fromArray($result);
    }
    
    /**
     * Find a file by project ID, path and name
     */
    public function findByPathAndName(string $projectId, string $path, string $name): ?ProjectFile
    {
        $path = $this->normalizePath($path);
        
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT * FROM project_file WHERE project_id = ? AND path = ? AND name = ?',
            [$projectId, $path, $name]
        )->fetchAssociative();
        
        if (!$result) {
            return null;
        }
        
        return ProjectFile::fromArray($result);
    }
    
    /**
     * List files in a directory
     */
    public function listFiles(string $projectId, string $path = '/'): array
    {
        $path = $this->normalizePath($path);
        
        $userDb = $this->getUserDb();
        $results = $userDb->executeQuery(
            'SELECT * FROM project_file WHERE project_id = ? AND path = ? ORDER BY is_directory DESC, name ASC',
            [$projectId, $path]
        )->fetchAllAssociative();
        
        return array_map(fn($data) => ProjectFile::fromArray($data), $results);
    }
    
    /**
     * Get file content
     */
    public function getFileContent(string $fileId): string
    {
        $file = $this->findById($fileId);
        if (!$file) {
            throw new \InvalidArgumentException('File not found');
        }
        
        if ($file->isDirectory()) {
            throw new \InvalidArgumentException('Cannot get content of a directory');
        }
        
        $filePath = $this->getAbsoluteFilePath($file->getProjectId(), $file->getPath(), $file->getName());
        if (!file_exists($filePath)) {
            throw new \RuntimeException('File not found in filesystem');
        }

        // return content in correct encoding for different formats
        // txt, json, csv, xml, html, php, js, css, md
        if ($file->getMimeType() === 'text/plain' || 
            strpos($file->getMimeType(), 'text/') === 0 || 
            $file->getType() === 'txt' || 
            $file->getType() === 'json' || 
            $file->getType() === 'csv' || 
            $file->getType() === 'xml' || 
            $file->getType() === 'html' || 
            $file->getType() === 'php' || 
            $file->getType() === 'js' || 
            $file->getType() === 'css' || 
            $file->getType() === 'md') {
                return file_get_contents($filePath);
        }

        // image, video, audio
        if (strpos($file->getMimeType(), 'image/') === 0 || 
            strpos($file->getMimeType(), 'video/') === 0 || 
            strpos($file->getMimeType(), 'audio/') === 0) {
            // base64 encode image, so it can be easyly displayed in browser
            return 'data:' . $file->getMimeType() . ';base64,' . base64_encode(file_get_contents($filePath));
        }

        // binary data
        if (strpos($file->getMimeType(), 'application/') === 0) {
            return 'data:' . $file->getMimeType() . ';base64,' . base64_encode(file_get_contents($filePath));
        }

        return file_get_contents($filePath);
    }
    
    /**
     * Delete a file or directory
     */
    public function delete(string $fileId): bool
    {
        $file = $this->findById($fileId);
        if (!$file) {
            return false;
        }
        
        $filePath = $this->getAbsoluteFilePath($file->getProjectId(), $file->getPath(), $file->getName());
        
        // Delete from filesystem
        try {
            if ($file->isDirectory()) {
                // For directories, use recursive directory removal with database cleanup
                $this->removeDirectoryRecursive($filePath, $file->getProjectId(), $file->getPath() . '/' . $file->getName());
            } else {
                // For files, use unlink
                if (file_exists($filePath) && !unlink($filePath)) {
                    throw new \RuntimeException(sprintf('Failed to delete file: %s', $filePath));
                }
            }
        } catch (\Exception $exception) {
            throw new \RuntimeException('Failed to delete file: ' . $exception->getMessage());
        }
        
        // Delete from database
        $userDb = $this->getUserDb();
        
        // Delete all versions if it's a file
        if (!$file->isDirectory()) {
            $userDb->executeStatement(
                'DELETE FROM project_file_version WHERE file_id = ?',
                [$file->getId()]
            );
        }
        
        // Delete the file/directory record
        $result = $userDb->executeStatement(
            'DELETE FROM project_file WHERE id = ?',
            [$file->getId()]
        );
        
        return $result > 0;
    }
    
    /**
     * Get file versions
     */
    public function getFileVersions(string $fileId): array
    {
        $userDb = $this->getUserDb();
        $results = $userDb->executeQuery(
            'SELECT * FROM project_file_version WHERE file_id = ? ORDER BY version DESC',
            [$fileId]
        )->fetchAllAssociative();
        
        return array_map(fn($data) => ProjectFileVersion::fromArray($data), $results);
    }
    
    /**
     * Recursively remove a directory and its contents based on database records
     * Only deletes files that are tracked in the database
     * 
     * @param string $dir Physical directory path
     * @param string $projectId Project ID for database lookup
     * @param string $relativePath Relative path within project for database lookup
     * @return bool Success status
     */
    private function removeDirectoryRecursive(string $dir, string $projectId, string $relativePath): bool
    {
        if (!is_dir($dir) || !$projectId) {
            return false;
        }
        
        $userDb = $this->getUserDb();
        
        // First, find all files in this directory from the database
        $files = $userDb->executeQuery(
            'SELECT * FROM project_file WHERE project_id = ? AND path = ? AND is_directory = 0',
            [$projectId, $relativePath]
        )->fetchAllAssociative();
        
        // Delete all files in this directory
        foreach ($files as $fileData) {
            $filePath = $this->getAbsoluteFilePath($projectId, $fileData['path'], $fileData['name']);
            
            // Delete file from filesystem if it exists
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            
            // Delete file versions
            $userDb->executeStatement(
                'DELETE FROM project_file_version WHERE file_id = ?',
                [$fileData['id']]
            );
            
            // Delete file record
            $userDb->executeStatement(
                'DELETE FROM project_file WHERE id = ?',
                [$fileData['id']]
            );
        }
        
        // Then, find all subdirectories in this directory from the database
        $directories = $userDb->executeQuery(
            'SELECT * FROM project_file WHERE project_id = ? AND path = ? AND is_directory = 1',
            [$projectId, $relativePath]
        )->fetchAllAssociative();
        
        // Recursively delete all subdirectories
        foreach ($directories as $dirData) {
            $subDirPath = $this->getAbsoluteFilePath($projectId, $dirData['path'], $dirData['name']);
            $subDirRelativePath = $relativePath . '/' . $dirData['name'];
            
            // Recursively delete subdirectory contents
            $this->removeDirectoryRecursive($subDirPath, $projectId, $subDirRelativePath);
            
            // Delete directory record
            $userDb->executeStatement(
                'DELETE FROM project_file WHERE id = ?',
                [$dirData['id']]
            );
            
            // Remove empty directory from filesystem
            if (is_dir($subDirPath)) {
                rmdir($subDirPath);
            }
        }
        
        // Finally, remove the current directory if it's empty
        return is_dir($dir) ? rmdir($dir) : true;
    }
    
    /**
     * Ensure project directory structure exists
     */
    public function ensureProjectDirectoryStructure(string $projectId): void
    {
        // Get project directory path
        //$projectDir = $this->params->get('kernel.project_dir');
        $projectDir = $this->params->get('kernel.project_dir') . '/var/user_data/' . $this->security->getUser()->getId() . '/p';
        
        // Define all required directories
        $requiredDirs = [
            // Projects base directory
            $projectDir,
            // Project-specific directory
            $projectDir . '/' . $projectId,
            // Project subdirectories
            $projectDir . '/' . $projectId . '/data',
            $projectDir . '/' . $projectId . '/www'
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
                    throw new \RuntimeException(sprintf('Directory %s is not writable', $dir));
                }
            }
        } finally {
            // Restore original umask
            umask($oldUmask);
        }
    }
}
