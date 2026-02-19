<?php

namespace App\Service;

use App\Entity\ProjectFile;
use App\Entity\ProjectFileVersion;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Psr\Log\LoggerInterface;

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
        'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/svg+xml', 'image/webp', 'image/ico', 'image/bmp', 'image/avif', 'image/tiff', 'image/vnd.microsoft.icon', 'image/x-icon', 'image/*',
        // Archives
        'application/zip', 'application/x-rar-compressed', 'application/x-tar', 'application/gzip',
        // Data
        'application/json', 'application/xml',
        // Database (for CQ Memory Packs)
        'application/x-sqlite3', 'application/vnd.sqlite3',
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
        private readonly ParameterBagInterface $params,
        private readonly LoggerInterface $logger
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

        $absolutePath = $projectDir . '/' . $name;
        $absolutePath = $this->normalizePath($absolutePath);
        
        return $absolutePath;
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
        
        // Ensure all parent directories exist in both filesystem and database
        $this->ensureParentDirectoriesExist($projectId, $path);
        
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
     * Ensure all parent directories exist in both filesystem and database
     * 
     * @param string $projectId Project ID
     * @param string $path Path to ensure (e.g. /spirit/memory)
     * @return void
     */
    private function ensureParentDirectoriesExist(string $projectId, string $path): void
    {
        if ($path === '/' || empty($path)) {
            return; // Root directory always exists
        }

        // Normalize path
        $path = $this->normalizePath($path);
        
        // Split path into components
        $pathParts = explode('/', trim($path, '/'));
        
        // Start with root
        $currentPath = '';
        $userDb = $this->getUserDb();
        
        // Create each directory level if it doesn't exist
        foreach ($pathParts as $part) {
            // Build the current path level
            $parentPath = $currentPath;
            $currentPath = $currentPath ? $this->normalizePath($currentPath . '/' . $part) : '/' . $part;
            
            if ($parentPath == "") {
                $parentPath = "/";
            }
            
            // Check if directory exists in database
            $exists = $userDb->executeQuery(
                'SELECT COUNT(*) as count FROM project_file WHERE project_id = ? AND path = ? AND name = ? AND is_directory = 1',
                [$projectId, $parentPath, $part]
            )->fetchAssociative();
            
            if (!$exists || $exists['count'] == 0) {
                // Create directory in filesystem
                $dirPath = $this->getAbsoluteFilePath($projectId, $parentPath, $part);
                
                if (!is_dir($dirPath)) {
                    $oldUmask = umask(0);
                    try {
                        if (!mkdir($dirPath, 0755, true)) {
                            throw new \RuntimeException(sprintf('Failed to create directory: %s', $dirPath));
                        }
                    } finally {
                        umask($oldUmask);
                    }
                }
                
                // Create directory record in database
                $directory = new ProjectFile(
                    $projectId,
                    $parentPath,
                    $part,
                    'directory',
                    true
                );
                
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
            }
        }
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

        // Check if file already exists
        $exists = $this->findByPathAndName($projectId, $path, $name);
        if ($exists) {
            throw new \InvalidArgumentException('File already exists');
        }
        
        // Determine file type from name
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $type = $extension ?: 'txt';
        
        // "data:*/*;base64,...."
        if (str_starts_with($content, 'data:')) {
            // remove data: prefix
            $content = substr($content, strlen('data:'));
            // get mime type
            $mimeType = substr($content, 0, strpos($content, ';'));
            // remove mime type
            $content = substr($content, strpos($content, ';base64,') + strlen(';base64,'));
            // convert base64 string to binary 
            $content = base64_decode($content);
        }

        // Validate mime type if provided
        if ($mimeType && !in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
            if (!in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
                throw new \InvalidArgumentException('Unsupported file type: ' . $mimeType);
            }
        }
        
        // Validate file size
        if (strlen($content) > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('File size exceeds maximum allowed size ' . self::MAX_FILE_SIZE);
        }
        
        // Ensure all parent directories exist in both filesystem and database
        $this->ensureParentDirectoriesExist($projectId, $path);

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
     * Register an existing file in the database without writing to filesystem
     * Used when the file was created externally (e.g., SQLite databases)
     */
    public function registerExistingFile(string $projectId, string $path, string $name, ?string $mimeType = null): ProjectFile
    {
        // Normalize and validate path
        $path = $this->normalizePath($path);
        if (!$this->validatePath($path)) {
            throw new \InvalidArgumentException('Invalid path');
        }

        // Check if already registered
        $exists = $this->findByPathAndName($projectId, $path, $name);
        if ($exists) {
            return $exists; // Already registered, return existing record
        }
        
        // Get absolute path and verify file exists
        $absolutePath = $this->getAbsoluteFilePath($projectId, $path, $name);
        if (!file_exists($absolutePath)) {
            throw new \InvalidArgumentException('File does not exist on filesystem: ' . $absolutePath);
        }
        
        // Get file info
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $type = $extension ?: 'file';
        $size = filesize($absolutePath);
        
        // Determine mime type if not provided
        if (!$mimeType) {
            $mimeType = mime_content_type($absolutePath) ?: 'application/octet-stream';
        }
        
        // Ensure parent directories are registered
        $this->ensureParentDirectoriesExist($projectId, $path);
        
        // Create file record in database
        $file = new ProjectFile(
            $projectId,
            $path,
            $name,
            $type,
            false,
            $mimeType,
            $size
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
        
        // Create initial version (use size+mtime hash to avoid reading large binary files like .cqmpack)
        $hash = hash('sha256', $size . ':' . filemtime($absolutePath));
        $this->createFileVersion($file->getId(), $file->getSize(), $hash);
        
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
        
        // Ensure all parent directories exist in both filesystem and database
        $this->ensureParentDirectoriesExist($projectId, $path);
        
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
     * Search files by query string matching against path and name
     */
    public function searchFiles(string $projectId, string $query): array
    {
        $userDb = $this->getUserDb();
        $likeQuery = '%' . $query . '%';
        
        $results = $userDb->executeQuery(
            'SELECT * FROM project_file WHERE project_id = ? AND (path LIKE ? OR name LIKE ?) ORDER BY is_directory DESC, path ASC, name ASC',
            [$projectId, $likeQuery, $likeQuery]
        )->fetchAllAssociative();
        
        return array_map(fn($data) => ProjectFile::fromArray($data), $results);
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
     * Show the complete project tree structure
     * 
     * @param string $projectId The project ID
     * @return array Hierarchical tree structure of all files and directories
     */
    public function showProjectTree(string $projectId, bool $condensed = false): array
    {
        $userDb = $this->getUserDb();
        
        // Get all files and directories for this project
        $allItems = $userDb->executeQuery(
            'SELECT * FROM project_file WHERE project_id = ? ORDER BY path ASC, is_directory DESC, name ASC',
            [$projectId]
        )->fetchAllAssociative();
        
        // Create root node
        $tree = [
            'name' => $projectId,
            'path' => '',//'/',
            'type' => 'projectRootDirectory',
            'children' => []
        ];
        
        // If no items, return empty tree
        if (empty($allItems)) {
            return $tree;
        }
        
        // Build tree recursively using a clean approach
        $tree['children'] = $this->buildTreeRecursive($allItems, '/', $condensed);
        
        return $tree;
    }
    
    /**
     * Build tree structure recursively
     * 
     * @param array $allItems All project files and directories
     * @param string $parentPath The parent path to build children for
     * @return array Array of child nodes
     */
    private function buildTreeRecursive(array $allItems, string $parentPath, bool $condensed): array
    {
        $children = [];
        
        // Find all items that belong directly to this parent path
        foreach ($allItems as $item) {
            if ($item['path'] === $parentPath) {
                if ($item['is_directory']) {
                    // This is a directory - create directory node and recursively get its children
                    // Database stores paths without trailing slash (except root "/")
                    $dirPath = $parentPath === '/' ? '/' . $item['name'] : $parentPath . '/' . $item['name'];
                    
                    $dirChildren = $this->buildTreeRecursive($allItems, $dirPath, $condensed);
                    
                    $dirNode = [
                        'id' => $item['id'],
                        'name' => $item['name'],
                        'path' => $item['path'],
                        'type' => 'directory',
                        'created_at' => $item['created_at'],
                        'updated_at' => $item['updated_at'],
                        'children' => $dirChildren
                    ];

                    if ($condensed) {
                        unset($dirNode['id']);
                        if ($item['created_at'] === $item['updated_at']) {                            
                            unset($dirNode['updated_at']);
                        }
                    }
                    
                    $children[] = $dirNode;
                } else {
                    $fileNode = [
                        'id' => $item['id'],
                        'name' => $item['name'],
                        'path' => $item['path'],
                        'type' => $item['type'],
                        'mime_type' => $item['mime_type'],
                        'size' => $item['size'],
                        'created_at' => $item['created_at'],
                        'updated_at' => $item['updated_at']
                    ];

                    if ($condensed) {
                        unset($fileNode['id']);
                        if ($item['created_at'] === $item['updated_at']) {                            
                            unset($fileNode['updated_at']);
                        }
                    }
                    
                    $children[] = $fileNode;
                }
            }
        }
        
        // Sort children: directories first, then files, all alphabetically
        usort($children, function($a, $b) {
            // Directories come first
            if ($a['type'] === 'directory' && $b['type'] !== 'directory') {
                return -1;
            }
            if ($a['type'] !== 'directory' && $b['type'] === 'directory') {
                return 1;
            }
            
            // Within same type, sort alphabetically by name
            return strcasecmp($a['name'], $b['name']);
        });
        
        return $children;
    }
    
    /**
     * Get file content
     */
    public function getFileContent(string $fileId, bool $withLineNumbers = false): string
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
            $file->getMimeType() === 'application/json' ||
            $file->getMimeType() === 'application/xml' ||
            $file->getType() === 'txt' || 
            $file->getType() === 'json' || 
            $file->getType() === 'csv' || 
            $file->getType() === 'xml' || 
            $file->getType() === 'html' || 
            $file->getType() === 'php' || 
            $file->getType() === 'js' || 
            $file->getType() === 'css' || 
            $file->getType() === 'md') {
            $content = file_get_contents($filePath);
            
            // Add line numbers if requested
            if ($withLineNumbers) {
                $lines = explode("\n", $content);
                $numberedLines = [];
                foreach ($lines as $index => $line) {
                    $lineNumber = $index + 1;
                    $numberedLines[] = sprintf('%4d: %s', $lineNumber, $line);
                }
                return implode("\n", $numberedLines);
            }
            
            return $content;
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
     * Update file content efficiently using find/replace operations
     * Supports multiple update types: replace, lineRange, append, prepend
     */
    public function updateFileEfficient(string $fileId, array $updates): ProjectFile
    {
        $file = $this->findById($fileId);
        if (!$file) {
            throw new \InvalidArgumentException('File not found');
        }

        if ($file->isDirectory()) {
            throw new \InvalidArgumentException('Cannot update content of a directory');
        }

        $filePath = $this->getAbsoluteFilePath($file->getProjectId(), $file->getPath(), $file->getName());
        if (!file_exists($filePath)) {
            throw new \RuntimeException('File not found in filesystem');
        }

        // Get current content
        $originalContent = file_get_contents($filePath);
        $updatedContent = $originalContent;
        $lines = explode("\n", $originalContent);

        // Separate line-based operations from content-based operations
        $lineBasedOps = [];
        $contentBasedOps = [];
        
        foreach ($updates as $index => $update) {
            if (!isset($update['operation'])) {
                throw new \InvalidArgumentException('Update operation must specify an operation');
            }
            
            // Add original index to preserve order for error reporting
            $update['_originalIndex'] = $index;
            
            if (in_array($update['operation'], ['lineRange', 'insertAtLine'])) {
                $lineBasedOps[] = $update;
            } else {
                $contentBasedOps[] = $update;
            }
        }
        
        // Sort line-based operations in reverse order (bottom-to-top) to preserve line numbers
        usort($lineBasedOps, function($a, $b) {
            $lineA = $a['startLine'] ?? $a['line'] ?? 0;
            $lineB = $b['startLine'] ?? $b['line'] ?? 0;
            return $lineB - $lineA; // Reverse order (highest line first)
        });
        
        // Process content-based operations first (they don't affect line numbers)
        foreach ($contentBasedOps as $update) {
            switch ($update['operation']) {
                case 'replace':
                    if (!isset($update['find']) || !isset($update['replace'])) {
                        throw new \InvalidArgumentException('Replace operation requires "find" and "replace" fields');
                    }
                    $updatedContent = str_replace($update['find'], $update['replace'], $updatedContent);
                    break;

                case 'append':
                    if (!isset($update['content'])) {
                        throw new \InvalidArgumentException('Append operation requires "content" field');
                    }
                    $updatedContent .= "\n" . $update['content'];
                    break;

                case 'prepend':
                    if (!isset($update['content'])) {
                        throw new \InvalidArgumentException('Prepend operation requires "content" field');
                    }
                    $updatedContent = $update['content'] . "\n" . $updatedContent;
                    break;

                default:
                    throw new \InvalidArgumentException('Unsupported update operation: ' . $update['operation']);
            }
        }
        
        // Update lines array after content-based operations
        $lines = explode("\n", $updatedContent);
        
        // Process line-based operations in reverse order (bottom-to-top) to preserve line numbers
        foreach ($lineBasedOps as $update) {
            switch ($update['operation']) {
                case 'lineRange':
                    if (!isset($update['startLine']) || !isset($update['endLine']) || !isset($update['content'])) {
                        throw new \InvalidArgumentException('LineRange operation requires "startLine", "endLine", and "content" fields');
                    }
                    
                    $startLine = (int)$update['startLine'] - 1; // Convert to 0-based index
                    $endLine = (int)$update['endLine'] - 1;
                    
                    if ($startLine < 0 || $endLine >= count($lines) || $startLine > $endLine) {
                        throw new \InvalidArgumentException('Invalid line range specified');
                    }
                    
                    // Replace the specified line range
                    $newLines = explode("\n", $update['content']);
                    array_splice($lines, $startLine, $endLine - $startLine + 1, $newLines);
                    break;

                case 'insertAtLine':
                    if (!isset($update['line']) || !isset($update['content'])) {
                        throw new \InvalidArgumentException('InsertAtLine operation requires "line" and "content" fields');
                    }
                    
                    $insertLine = (int)$update['line'] - 1; // Convert to 0-based index
                    if ($insertLine < 0 || $insertLine > count($lines)) {
                        throw new \InvalidArgumentException('Invalid line number for insertion');
                    }
                    
                    $newLines = explode("\n", $update['content']);
                    array_splice($lines, $insertLine, 0, $newLines);
                    break;

                default:
                    throw new \InvalidArgumentException('Unsupported update operation: ' . $update['operation']);
            }
        }
        
        // Final content after all operations
        $updatedContent = implode("\n", $lines);

        // Write the updated content
        if (file_put_contents($filePath, $updatedContent) === false) {
            throw new \RuntimeException('Failed to write updated content to file');
        }

        // Update file metadata
        $file->setSize(strlen($updatedContent));
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
        $this->createFileVersion($file->getId(), $file->getSize(), hash('sha256', $updatedContent));

        return $file;
    }

    /**
     * Unified file management operations
     * Supports multiple operation types: create, copy, rename_move, delete
     */
    public function manageFile(string $projectId, string $operation, array $params): array
    {
        switch ($operation) {
            case 'create':
                return $this->handleCreateOperation($projectId, $params);
                
            case 'copy':
                return $this->handleCopyOperation($projectId, $params);
                
            case 'rename_move':
                return $this->handleRenameMoveOperation($projectId, $params);
                
            case 'delete':
                return $this->handleDeleteOperation($projectId, $params);
                
            default:
                throw new \InvalidArgumentException('Unsupported operation type: ' . $operation);
        }
    }

    /**
     * Handle create file operation
     */
    private function handleCreateOperation(string $projectId, array $params): array
    {
        if (!isset($params['destination']['path']) || !isset($params['destination']['name']) || !isset($params['content'])) {
            throw new \InvalidArgumentException('Create operation requires destination.path, destination.name, and content');
        }

        // Check if file already exists to prevent UNIQUE constraint violation
        $existingFile = $this->findByPathAndName(
            $projectId,
            $params['destination']['path'],
            $params['destination']['name']
        );

        if ($existingFile) {
            throw new \InvalidArgumentException(
                'File already exists at path: ' . $params['destination']['path'] . $params['destination']['name']
            );
        }

        // Use existing robust createFile method
        $file = $this->createFile(
            $projectId,
            $params['destination']['path'],
            $params['destination']['name'],
            $params['content']
        );

        return [
            'operation' => 'create',
            'success' => true,
            'file' => $file->jsonSerialize()
        ];
    }

    /**
     * Handle copy file/directory operation
     */
    private function handleCopyOperation(string $projectId, array $params): array
    {
        if (!isset($params['source']['path']) || !isset($params['source']['name']) || 
            !isset($params['destination']['path']) || !isset($params['destination']['name'])) {
            throw new \InvalidArgumentException('Copy operation requires source.path, source.name, destination.path, and destination.name');
        }

        // Find source file
        $sourceFile = $this->findByPathAndName(
            $projectId,
            $params['source']['path'],
            $params['source']['name']
        );

        if (!$sourceFile) {
            throw new \InvalidArgumentException('Source file not found');
        }

        if ($sourceFile->isDirectory()) {
            return $this->copyDirectory($projectId, $sourceFile, $params['destination']);
        } else {
            return $this->copyFile($projectId, $sourceFile, $params['destination']);
        }
    }

    /**
     * Handle rename/move file/directory operation
     */
    private function handleRenameMoveOperation(string $projectId, array $params): array
    {
        if (!isset($params['source']['path']) || !isset($params['source']['name']) || 
            !isset($params['destination']['path']) || !isset($params['destination']['name'])) {
            throw new \InvalidArgumentException('Rename/move operation requires source.path, source.name, destination.path, and destination.name');
        }

        // Find source file
        $sourceFile = $this->findByPathAndName(
            $projectId,
            $params['source']['path'],
            $params['source']['name']
        );

        if (!$sourceFile) {
            throw new \InvalidArgumentException('Source file not found');
        }

        if ($sourceFile->isDirectory()) {
            return $this->moveDirectory($projectId, $sourceFile, $params['destination']);
        } else {
            return $this->moveFile($projectId, $sourceFile, $params['destination']);
        }
    }

    /**
     * Handle delete file/directory operation
     */
    private function handleDeleteOperation(string $projectId, array $params): array
    {
        if (!isset($params['source']['path']) || !isset($params['source']['name'])) {
            throw new \InvalidArgumentException('Delete operation requires source.path and source.name');
        }

        // Find source file using existing method
        $sourceFile = $this->findByPathAndName(
            $projectId,
            $params['source']['path'],
            $params['source']['name']
        );

        if (!$sourceFile) {
            throw new \InvalidArgumentException('Source file not found');
        }

        // Use existing robust delete method
        $result = $this->delete($sourceFile->getId());

        return [
            'operation' => 'delete',
            'success' => $result,
            'file' => $sourceFile->jsonSerialize()
        ];
    }

    /**
     * Copy a file to destination using existing robust methods
     */
    public function copyFile(string $projectId, ProjectFile $sourceFile, array $destination): array
    {
        // Get source file content using existing method
        $sourceContent = $this->getFileContent($sourceFile->getId());
        
        // Use existing robust createFile method for destination
        $newFile = $this->createFile(
            $projectId,
            $destination['path'],
            $destination['name'],
            $sourceContent
        );

        return [
            'operation' => 'copy',
            'success' => true,
            'source' => $sourceFile->jsonSerialize(),
            'destination' => $newFile->jsonSerialize()
        ];
    }

    /**
     * Copy a directory to destination (recursive) using existing robust methods
     */
    public function copyDirectory(string $projectId, ProjectFile $sourceDir, array $destination): array
    {
        // Use existing robust createDirectory method
        $newDir = $this->createDirectory(
            $projectId,
            $destination['path'],
            $destination['name']
        );

        $copiedFiles = [];
        
        // Build source path with proper separator (handle root path case)
        $sourcePath = ($sourceDir->getPath() === '/' ? '' : $sourceDir->getPath()) . '/' . $sourceDir->getName();
        
        // Get all files in source directory using existing method
        $sourceFiles = $this->listFiles($projectId, $sourcePath);
        
        // Build new directory path with proper separator
        $newDirPath = ($newDir->getPath() === '/' ? '' : $newDir->getPath()) . '/' . $newDir->getName();
        
        foreach ($sourceFiles as $file) {
            if ($file->isDirectory()) {
                // Recursively copy subdirectory
                $subResult = $this->copyDirectory($projectId, $file, [
                    'path' => $newDirPath,
                    'name' => $file->getName()
                ]);
                $copiedFiles[] = $subResult;
            } else {
                // Copy file using existing method
                $fileResult = $this->copyFile($projectId, $file, [
                    'path' => $newDirPath,
                    'name' => $file->getName()
                ]);
                $copiedFiles[] = $fileResult;
            }
        }

        return [
            'operation' => 'copy',
            'success' => true,
            'source' => $sourceDir->jsonSerialize(),
            'destination' => $newDir->jsonSerialize(),
            'copiedFiles' => $copiedFiles
        ];
    }

    /**
     * Move/rename a file using existing robust database patterns
     */
    public function moveFile(string $projectId, ProjectFile $sourceFile, array $destination): array
    {
        $userDb = $this->getUserDb();
        
        try {
            $userDb->beginTransaction();

            // Normalize path
            $destination['path'] = $this->normalizePath($destination['path']);

            $this->ensureParentDirectoriesExist($projectId, $destination['path']);
            
            // Update file path and name in database using existing patterns
            $userDb->executeStatement(
                'UPDATE project_file 
                 SET path = ?, name = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?',
                [
                    $destination['path'],
                    $destination['name'],
                    $sourceFile->getId()
                ]
            );
            
            // Move physical file if it exists using existing path methods
            $oldPath = $this->getAbsoluteFilePath($projectId, $sourceFile->getPath(), $sourceFile->getName());
            $newPath = $this->getAbsoluteFilePath($projectId, $destination['path'], $destination['name']);
            
            if (file_exists($oldPath)) {
                // Ensure destination directory exists using existing patterns
                $destDir = dirname($newPath);
                if (!is_dir($destDir)) {
                    $oldUmask = umask(0);
                    try {
                        if (!mkdir($destDir, 0755, true)) {
                            throw new \RuntimeException('Failed to create destination directory');
                        }
                    } finally {
                        umask($oldUmask);
                    }
                }
                
                if (!rename($oldPath, $newPath)) {
                    throw new \RuntimeException('Failed to move physical file');
                }
            }
            
            $userDb->commit();
            
            // Get updated file info using existing method
            $updatedFile = $this->findById($sourceFile->getId());
            
            return [
                'operation' => 'rename_move',
                'success' => true,
                'source' => $sourceFile->jsonSerialize(),
                'destination' => $updatedFile->jsonSerialize()
            ];
            
        } catch (\Exception $e) {
            $userDb->rollBack();
            throw $e;
        }
    }

    /**
     * Move/rename a directory (recursive) using existing robust database patterns
     */
    public function moveDirectory(string $projectId, ProjectFile $sourceDir, array $destination): array
    {
        $userDb = $this->getUserDb();
        
        try {
            $userDb->beginTransaction();
            
            // Normalize path
            $destination['path'] = $this->normalizePath($destination['path']);
            
            // Build old and new full paths for the directory
            // oldPath: the full path of the source directory (e.g., /mf-test/subfolder-1)
            $oldPath = ($sourceDir->getPath() === '/' ? '' : $sourceDir->getPath()) . '/' . $sourceDir->getName();
            // newPath: the full path of the destination directory (e.g., /subfolder-1)
            $newPath = ($destination['path'] === '/' ? '' : $destination['path']) . '/' . $destination['name'];

            // Ensure parent directories exist (destination['path'] is the parent, not the full new path)
            $this->ensureParentDirectoriesExist($projectId, $destination['path']);
            
            // Update directory itself using existing patterns
            $userDb->executeStatement(
                'UPDATE project_file 
                 SET path = ?, name = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?',
                [
                    $destination['path'],
                    $destination['name'],
                    $sourceDir->getId()
                ]
            );
            
            // Update all files within the directory (recursive path update) using existing patterns
            $userDb->executeStatement(
                'UPDATE project_file 
                 SET path = REPLACE(path, ?, ?), updated_at = CURRENT_TIMESTAMP
                 WHERE project_id = ? AND path LIKE ?',
                [
                    $oldPath,
                    $newPath,
                    $projectId,
                    $oldPath . '%'
                ]
            );
            
            // Move physical directory if it exists using existing path methods
            $oldPhysicalPath = $this->getAbsoluteFilePath($projectId, $sourceDir->getPath(), $sourceDir->getName());
            $newPhysicalPath = $this->getAbsoluteFilePath($projectId, $destination['path'], $destination['name']);
            
            if (is_dir($oldPhysicalPath)) {
                // Ensure parent directory exists using existing patterns
                $parentDir = dirname($newPhysicalPath);
                if (!is_dir($parentDir)) {
                    $oldUmask = umask(0);
                    try {
                        if (!mkdir($parentDir, 0755, true)) {
                            throw new \RuntimeException('Failed to create parent directory');
                        }
                    } finally {
                        umask($oldUmask);
                    }
                }
                
                if (!rename($oldPhysicalPath, $newPhysicalPath)) {
                    throw new \RuntimeException('Failed to move physical directory');
                }
            }
            
            $userDb->commit();
            
            // Get updated directory info using existing method
            $updatedDir = $this->findById($sourceDir->getId());
            
            return [
                'operation' => 'rename_move',
                'success' => true,
                'source' => $sourceDir->jsonSerialize(),
                'destination' => $updatedDir->jsonSerialize()
            ];
            
        } catch (\Exception $e) {
            $userDb->rollBack();
            throw $e;
        }
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
                $relativePath = ($file->getPath() == "/" ? '' : $file->getPath()) . '/' . $file->getName();
                $this->removeDirectoryRecursive($filePath, $file->getProjectId(), $relativePath);
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
            $subDirRelativePath = ($relativePath == "/" ? '' : $relativePath) . '/' . $dirData['name'];
            
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
    
    /**
     * Calculate total size of all files in a project
     * Returns size in bytes
     */
    public function getTotalProjectSize(string $projectId): int
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT SUM(size) as total_size FROM project_file WHERE project_id = ? AND is_directory = 0',
            [$projectId]
        )->fetchAssociative();
        
        return (int) ($result['total_size'] ?? 0);
    }
    
    /**
     * Format bytes to human-readable size
     */
    public function formatBytes(int $bytes, int $precision = 2): string
    {
        if ($bytes === 0) {
            return '0 B';
        }
        
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $base = 1024;
        $exponent = floor(log($bytes) / log($base));
        
        return round($bytes / pow($base, $exponent), $precision) . ' ' . $units[$exponent];
    }
    
    /**
     * Supported image extensions for thumbnail generation
     */
    private const THUMBNAIL_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    
    /**
     * Thumbnail settings
     */
    private const THUMBNAIL_MAX_SIZE = 250;
    private const THUMBNAIL_QUALITY = 84;
    private const THUMBNAIL_SUFFIX = '.thumb';
    
    /**
     * Check if file is an image that supports thumbnails
     */
    public function isImageFile(string $filename): bool
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($extension, self::THUMBNAIL_EXTENSIONS);
    }
    
    /**
     * Get thumbnail path for a file
     */
    public function getThumbnailPath(string $originalPath): string
    {
        return $originalPath . self::THUMBNAIL_SUFFIX;
    }
    
    /**
     * Check if GD library is available
     */
    public function isGdAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromjpeg');
    }
    
    /**
     * Generate thumbnail for an image file
     * Returns the thumbnail path if successful, null otherwise
     */
    public function generateThumbnail(string $fileId): ?string
    {
        $file = $this->findById($fileId);
        if (!$file || $file->isDirectory()) {
            return null;
        }
        
        if (!$this->isImageFile($file->getName())) {
            return null;
        }
        
        $originalPath = $this->getAbsoluteFilePath($file->getProjectId(), $file->getPath(), $file->getName());
        if (!file_exists($originalPath)) {
            return null;
        }
        
        // If GD is not available, return original path (no thumbnail generation)
        if (!$this->isGdAvailable()) {
            $this->logger->warning('GD library not available for thumbnail generation');
            return $originalPath;
        }
        
        $thumbnailPath = $this->getThumbnailPath($originalPath);
        
        // Return existing thumbnail if it's newer than the original
        if (file_exists($thumbnailPath) && filemtime($thumbnailPath) >= filemtime($originalPath)) {
            return $thumbnailPath;
        }
        
        // Generate thumbnail using GD
        try {
            $imageInfo = getimagesize($originalPath);
            if ($imageInfo === false) {
                return $originalPath;
            }
            
            $originalWidth = $imageInfo[0];
            $originalHeight = $imageInfo[1];
            $mimeType = $imageInfo['mime'];
            
            // Create image resource based on type
            $sourceImage = match ($mimeType) {
                'image/jpeg' => imagecreatefromjpeg($originalPath),
                'image/png' => imagecreatefrompng($originalPath),
                'image/gif' => imagecreatefromgif($originalPath),
                'image/webp' => imagecreatefromwebp($originalPath),
                'image/bmp' => imagecreatefrombmp($originalPath),
                default => null
            };
            
            if (!$sourceImage) {
                return $originalPath;
            }
            
            // Calculate new dimensions maintaining aspect ratio
            $ratio = min(self::THUMBNAIL_MAX_SIZE / $originalWidth, self::THUMBNAIL_MAX_SIZE / $originalHeight);
            
            // Only resize if image is larger than thumbnail size
            if ($ratio >= 1) {
                // Image is smaller than thumbnail size, just copy it
                imagedestroy($sourceImage);
                copy($originalPath, $thumbnailPath);
                return $thumbnailPath;
            }
            
            $newWidth = (int) round($originalWidth * $ratio);
            $newHeight = (int) round($originalHeight * $ratio);
            
            // Create thumbnail image
            $thumbnailImage = imagecreatetruecolor($newWidth, $newHeight);
            
            // Preserve transparency for PNG and GIF
            if ($mimeType === 'image/png' || $mimeType === 'image/gif') {
                imagealphablending($thumbnailImage, false);
                imagesavealpha($thumbnailImage, true);
                $transparent = imagecolorallocatealpha($thumbnailImage, 0, 0, 0, 127);
                imagefill($thumbnailImage, 0, 0, $transparent);
            }
            
            // Resize
            imagecopyresampled(
                $thumbnailImage, $sourceImage,
                0, 0, 0, 0,
                $newWidth, $newHeight,
                $originalWidth, $originalHeight
            );
            
            // Save as JPEG for consistent output (smaller file size)
            imagejpeg($thumbnailImage, $thumbnailPath, self::THUMBNAIL_QUALITY);
            
            // Clean up
            imagedestroy($sourceImage);
            imagedestroy($thumbnailImage);
            
            return $thumbnailPath;
            
        } catch (\Exception $e) {
            $this->logger->error('Thumbnail generation failed: ' . $e->getMessage());
            return $originalPath;
        }
    }
    
    /**
     * Get file content with optional thumbnail support
     */
    public function getFileContentWithThumbnail(string $fileId): ?string
    {
        $file = $this->findById($fileId);
        if (!$file || $file->isDirectory()) {
            return null;
        }
        
        if (!$this->isImageFile($file->getName())) {
            return null;
        }
        
        $thumbnailPath = $this->generateThumbnail($fileId);
        if (!$thumbnailPath || !file_exists($thumbnailPath)) {
            return null;
        }
        
        // Return base64 encoded thumbnail
        return 'data:image/jpeg;base64,' . base64_encode(file_get_contents($thumbnailPath));
    }
    
    /**
     * Get images in a directory (for gallery)
     * Returns array of image files with their IDs
     */
    public function getImagesInDirectory(string $projectId, string $path): array
    {
        $files = $this->listFiles($projectId, $path);
        $images = [];
        
        foreach ($files as $file) {
            if (!$file->isDirectory() && $this->isImageFile($file->getName())) {
                $images[] = $file->jsonSerialize();
            }
        }
        
        return $images;
    }
}
