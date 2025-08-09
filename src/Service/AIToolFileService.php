<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Service for AI Tool file operations
 */
class AIToolFileService
{
    public function __construct(
        private readonly ProjectFileService $projectFileService,
        private readonly Security $security
    ) {
    }
    
    /**
     * List files in a project directory
     */
    public function listFiles(array $arguments): array
    {
        $this->validateArguments($arguments, ['projectId', 'path']);
        
        try {
            $files = $this->projectFileService->listFiles(
                $arguments['projectId'],
                $arguments['path'] ?? '/'
            );
            
            return [
                'success' => true,
                'files' => array_map(fn($file) => $file->jsonSerialize(), $files)
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get file content
     */
    public function getFileContent(array $arguments): array
    {
        $this->validateArguments($arguments, ['projectId', 'path', 'name']);
        
        try {
            $file = $this->projectFileService->findByPathAndName(
                $arguments['projectId'],
                $arguments['path'],
                $arguments['name']
            );

            if (!$file && isset($arguments['fileId'])) {
                $file = $this->projectFileService->findById($arguments['fileId']);
            }
            
            if (!$file) {
                return [
                    'success' => false,
                    'error' => 'File not found'
                ];
            }
            
            if ($file->isDirectory()) {
                return [
                    'success' => false,
                    'error' => 'Cannot get content of a directory'
                ];
            }
            
            $content = $this->projectFileService->getFileContent($file->getId());

            if  (strpos($content, 'data:') === 0) {
                $content = "binary data, not displayed";
            }
            
            return [
                'success' => true,
                'content' => $content,
                'file' => $file->jsonSerialize()
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Create a directory
     */
    public function createDirectory(array $arguments): array
    {
        $this->validateArguments($arguments, ['projectId', 'path', 'name']);
        
        try {
            $directory = $this->projectFileService->createDirectory(
                $arguments['projectId'],
                $arguments['path'],
                $arguments['name']
            );
            
            return [
                'success' => true,
                'directory' => $directory->jsonSerialize()
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Create a file with content
     */
    public function createFile(array $arguments): array
    {
        $this->validateArguments($arguments, ['projectId', 'path', 'name', 'content']);
        
        try {
            $file = $this->projectFileService->createFile(
                $arguments['projectId'],
                $arguments['path'],
                $arguments['name'],
                $arguments['content'],
                $arguments['mimeType'] ?? null
            );
            
            return [
                'success' => true,
                'file' => $file->jsonSerialize()
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Update file content
     */
    public function updateFile(array $arguments): array
    {
        $this->validateArguments($arguments, ['projectId', 'path', 'name', 'content']);
        
        try {
            $file = $this->projectFileService->findByPathAndName(
                $arguments['projectId'],
                $arguments['path'],
                $arguments['name']
            );
            
            if (!$file) {
                return [
                    'success' => false,
                    'error' => 'File not found'
                ];
            }
            
            $file = $this->projectFileService->updateFile(
                $file->getId(),
                $arguments['content']
            );

            if (!$file) {
                return [
                    'success' => false,
                    'error' => 'Failed to update file'
                ];
            }
            
            return [
                'success' => true,
                'file' => $file->jsonSerialize()
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Delete a file or directory
     */
    public function deleteFile(array $arguments): array
    {
        $this->validateArguments($arguments, ['projectId', 'path', 'name']);
        
        try {
            $file = $this->projectFileService->findByPathAndName(
                $arguments['projectId'],
                $arguments['path'],
                $arguments['name']
            );

            if (!$file && isset($arguments['fileId'])) {
                $file = $this->projectFileService->findById($arguments['fileId']);
            }
            
            if (!$file) {
                return [
                    'success' => false,
                    'error' => 'File not found'
                ];
            }
            
            $result = $this->projectFileService->delete($file->getId());

            if (!$result) {
                return [
                    'success' => false,
                    'error' => 'Failed to delete file'
                ];
            }
            
            return [
                'success' => true
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get file versions
     */
    public function getFileVersions(array $arguments): array
    {
        $this->validateArguments($arguments, ['fileId']);
        
        try {
            $file = $this->projectFileService->findById($arguments['fileId']);
            
            if (!$file) {
                return [
                    'success' => false,
                    'error' => 'File not found'
                ];
            }
            
            if ($file->isDirectory()) {
                return [
                    'success' => false,
                    'error' => 'Directories do not have versions'
                ];
            }
            
            $versions = $this->projectFileService->getFileVersions($arguments['fileId']);
            
            return [
                'success' => true,
                'versions' => array_map(fn($version) => $version->jsonSerialize(), $versions)
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Ensure project directory structure exists
     */
    public function ensureProjectStructure(array $arguments): array
    {
        $this->validateArguments($arguments, ['projectId']);
        
        try {
            $this->projectFileService->ensureProjectDirectoryStructure($arguments['projectId']);
            
            return [
                'success' => true
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get the complete project tree structure
     */
    public function getProjectTree(array $arguments): array
    {
        $this->validateArguments($arguments, ['projectId']);
        
        try {
            $tree = $this->projectFileService->showProjectTree($arguments['projectId']);
            
            return [
                'success' => true,
                'tree' => $tree
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Find a file by path and name
     */
    public function findFileByPath(array $arguments): array
    {
        $this->validateArguments($arguments, ['projectId', 'path', 'name']);
        
        try {
            $file = $this->projectFileService->findByPathAndName(
                $arguments['projectId'],
                $arguments['path'],
                $arguments['name']
            );
            
            if (!$file) {
                return [
                    'success' => false,
                    'error' => 'File not found'
                ];
            }
            
            return [
                'success' => true,
                'file' => $file->jsonSerialize()
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Validate required arguments
     */
    private function validateArguments(array $arguments, array $required): void
    {
        foreach ($required as $arg) {
            if (!isset($arguments[$arg])) {
                throw new \InvalidArgumentException("Missing required argument: $arg");
            }
        }
    }
}
