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
        private readonly Security $security,
        private readonly AnnoService $annoService
    ) {
    }

    /**
     * Update file content efficiently using find/replace operations
     * Token-efficient alternative to updateFile - only send changed parts
     * 
     * Supported update types:
     * - replace: Find and replace text
     * - lineRange: Replace specific line range
     * - append: Add content to end of file
     * - prepend: Add content to beginning of file
     * - insertAtLine: Insert content at specific line
     * 
     * Simplified format (one operation per call) for better LLM compatibility.
     * For multiple operations, call this tool multiple times.
     */
    public function fileUpdate(array $arguments): array
    {
        $this->validateArguments($arguments, ['projectId', 'path', 'name', 'operation']);
        $this->validateSpiritAccess($arguments);
        
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
            
            // Build single update operation from flat parameters
            $update = [
                'operation' => $arguments['operation']
            ];
            
            // Add operation-specific parameters
            if (isset($arguments['find'])) {
                $update['find'] = $arguments['find'];
            }
            if (isset($arguments['replaceWith'])) {
                $update['replace'] = $arguments['replaceWith'];
            }
            if (isset($arguments['startLine'])) {
                $update['startLine'] = (int) $arguments['startLine'];
            }
            if (isset($arguments['endLine'])) {
                $update['endLine'] = (int) $arguments['endLine'];
            }
            if (isset($arguments['line'])) {
                $update['line'] = (int) $arguments['line'];
            }
            if (isset($arguments['content'])) {
                $update['content'] = $arguments['content'];
            }
            
            // Legacy support: if 'updates' array is provided, use it directly
            $updates = isset($arguments['updates']) && is_array($arguments['updates']) 
                ? $arguments['updates'] 
                : [$update];
            
            $updatedFile = $this->projectFileService->updateFileEfficient(
                $file->getId(),
                $updates
            );
            
            return [
                'success' => true,
                'file' => $updatedFile->jsonSerialize(),
                'operations_applied' => count($updates)
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Search files by query string matching against path and name
     */
    public function fileSearch(array $arguments): array
    {
        $this->validateArguments($arguments, ['projectId', 'query']);
        
        try {
            $files = $this->projectFileService->searchFiles(
                $arguments['projectId'],
                $arguments['query']
            );
            
            if (empty($files)) {
                return [
                    'success' => true,
                    'files' => [],
                    'count' => 0,
                    'message' => 'No files found matching "' . $arguments['query'] . '"'
                ];
            }
            
            return [
                'success' => true,
                'files' => array_map(fn($f) => $f->jsonSerialize(), $files),
                'count' => count($files)
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Unified file management operations for AI tools
     * Supports: 
     * - File operations: create, read, copy, rename_move, delete
     * - Directory operations: createDirectory, list, tree
     * 
     * Simplified flat parameters for better LLM compatibility:
     * - sourcePath, sourceName: for copy, read, rename_move, delete, list, tree
     * - destPath, destName: for create, copy, rename_move, createDirectory
     * - content: for create operation
     * - withLineNumbers: for read operation
     */
    public function fileManage(array $arguments): array
    {
        try {
            // Validate required parameters
            if (!isset($arguments['projectId']) || !isset($arguments['operation'])) {
                throw new \InvalidArgumentException('fileManage requires projectId and operation parameters');
            }
            
            $projectId = $arguments['projectId'];
            $operation = $arguments['operation'];
            
            // Get path/name from various parameter combinations
            $path = $arguments['destPath'] ?? $arguments['sourcePath'] ?? '/';
            $name = $arguments['destName'] ?? $arguments['sourceName'] ?? '';

            // Handle consolidated directory and read operations
            switch ($operation) {
                case 'createDirectory':
                    return $this->handleCreateDirectory($projectId, $path, $name, $arguments);
                    
                case 'list':
                    return $this->handleListFiles($projectId, $path, $arguments);
                    
                case 'tree':
                    return $this->handleGetProjectTree($projectId, $arguments);
                    
                case 'read':
                    return $this->handleReadFile($projectId, $path, $name, $arguments);
                    
                case 'create':
                case 'copy':
                case 'rename_move':
                case 'delete':
                    return $this->handleFileOperations($projectId, $operation, $arguments);
                    
                default:
                    throw new \InvalidArgumentException('Invalid operation: ' . $operation . '. Supported: create, copy, rename_move, delete, createDirectory, list, tree, read');
            }

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'operation' => $arguments['operation'] ?? 'unknown'
            ];
        }
    }
    
    /**
     * Handle createDirectory operation
     */
    private function handleCreateDirectory(string $projectId, string $path, string $name, array $arguments): array
    {
        // Validate Spirit access
        $this->validateSpiritAccess(['path' => $path, '_spiritSlug' => $arguments['_spiritSlug'] ?? null]);
        
        try {
            $directory = $this->projectFileService->createDirectory($projectId, $path, $name);
            return [
                'success' => true,
                'operation' => 'createDirectory',
                'directory' => $directory->jsonSerialize()
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Handle list operation (list files in directory)
     */
    private function handleListFiles(string $projectId, string $path, array $arguments): array
    {
        // Validate Spirit access
        $this->validateSpiritAccess(['path' => $path, '_spiritSlug' => $arguments['_spiritSlug'] ?? null]);
        
        try {
            $files = $this->projectFileService->listFiles($projectId, $path);
            return [
                'success' => true,
                'operation' => 'list',
                'path' => $path,
                'files' => array_map(fn($file) => $file->jsonSerialize(), $files)
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Handle tree operation (get project tree)
     */
    private function handleGetProjectTree(string $projectId, array $arguments): array
    {
        // Note: getProjectTree shows full structure, access control is enforced on individual file operations
        
        try {
            $tree = $this->projectFileService->showProjectTree($projectId, true);
            return [
                'success' => true,
                'operation' => 'tree',
                'tree' => $tree
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Handle read operation (get file content)
     */
    private function handleReadFile(string $projectId, string $path, string $name, array $arguments): array
    {
        // Validate Spirit access
        $this->validateSpiritAccess(['path' => $path, '_spiritSlug' => $arguments['_spiritSlug'] ?? null]);
        
        try {
            $file = $this->projectFileService->findByPathAndName($projectId, $path, $name);
            
            if (!$file && isset($arguments['fileId'])) {
                $file = $this->projectFileService->findById($arguments['fileId']);
            }
            
            if (!$file) {
                return ['success' => false, 'error' => 'File not found'];
            }
            
            if ($file->isDirectory()) {
                return ['success' => false, 'error' => 'Cannot get content of a directory'];
            }
            
            $withLineNumbers = $arguments['withLineNumbers'] ?? false;
            $filename = $file->getName();
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            // For PDF files, try to use cached annotations
            $usedAnnotations = false;
            $content = '';
            if ($extension === 'pdf') {
                $annoData = $this->annoService->readAnnotation(AnnoService::TYPE_PDF, $filename, $projectId, false);
                if ($annoData && $this->annoService->verifyPdfAnnotation($annoData, $filename)) {
                    $content = $this->annoService->getTextContent($annoData);
                    $usedAnnotations = true;
                }
            }
            
            // If no annotations used, get raw content
            if (!$usedAnnotations) {
                $content = $this->projectFileService->getFileContent($file->getId(), $withLineNumbers);
            }
            
            // Build frontend display HTML (same logic as original getFileContent)
            $contentFrontendData = $this->buildContentFrontendData($file, $content, $usedAnnotations, $projectId);
            
            // Handle binary data display
            if (!$usedAnnotations && strpos($content, 'data:') === 0) {
                $content = "binary data, not displayed";
                if (strpos($file->getMimeType(), 'image/') === 0 || 
                    strpos($file->getMimeType(), 'video/') === 0 || 
                    strpos($file->getMimeType(), 'audio/') === 0) {
                    $content = 'binary data, displayed directly in frontend';
                }
            }
            
            return [
                'success' => true,
                'operation' => 'read',
                'content' => $content,
                'file' => $file->jsonSerialize(),
                'with_line_numbers' => $withLineNumbers,
                '_frontendData' => $contentFrontendData
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Build frontend display data for file content
     */
    private function buildContentFrontendData($file, string $content, bool $usedAnnotations, string $projectId): string
    {
        $contentFrontendData = '<pre>' . htmlspecialchars($content) . '</pre>';
        
        // Image data
        if (strpos($file->getMimeType(), 'image/') === 0) {
            $contentFrontendData = '<img src="/api/project-file/' . $file->getId() . '/download" alt="' . $file->getName() . '" style="max-width: 100%; height: auto; max-height: 75vh;" class="rounded shadow"/>';
        }
        // Video data
        elseif (strpos($file->getMimeType(), 'video/') === 0) {
            $contentFrontendData = '<video src="/api/project-file/' . $file->getId() . '/download" controls style="max-width: 100%;" class="rounded shadow"></video>';
        }
        // Audio data
        elseif (strpos($file->getMimeType(), 'audio/') === 0) {
            $contentFrontendData = '<audio src="/api/project-file/' . $file->getId() . '/download" controls style="width: 100%;" class="rounded shadow"></audio>';
        }
        // PDF with annotations
        elseif ($usedAnnotations) {
            $contentFrontendData = '<div class="chat-file-preview rounded text-cyber bg-dark bg-opacity-25 cursor-pointer mb-2"
                            onclick="this.querySelector(\'.embed-container\').classList.toggle(\'d-none\');">
                        <div class="d-flex align-items-center px-1">
                            <i class="mdi mdi-file-pdf-box me-1" style="font-size: 1.6rem; padding: 0 0.3rem !important;"></i>
                            <span class="text-cyber">' . htmlspecialchars($file->getName()) . '</span>
                        </div>
                        <div class="p-2 pt-0 d-none embed-container">
                            <embed src="/api/project-file/' . $file->getId() . '/download" loading="lazy"
                                width="100%" height="420"
                                class="rounded"
                                type="application/pdf"
                                title="' . htmlspecialchars($file->getName()) . '" />
                        </div>
                    </div>';
        }
        
        return $contentFrontendData;
    }
    
    /**
     * Handle file operations (create, copy, rename_move, delete)
     */
    private function handleFileOperations(string $projectId, string $operation, array $arguments): array
    {
        // Validate Spirit access for source path
        if (isset($arguments['sourcePath'])) {
            $this->validateSpiritAccess(['path' => $arguments['sourcePath'], '_spiritSlug' => $arguments['_spiritSlug'] ?? null]);
        }
        // Validate Spirit access for destination path
        if (isset($arguments['destPath'])) {
            $this->validateSpiritAccess(['path' => $arguments['destPath'], '_spiritSlug' => $arguments['_spiritSlug'] ?? null]);
        }

        // Prepare parameters based on operation
        $params = [];

        // Source parameters (required for copy, rename_move, delete)
            // Support both flat (sourcePath, sourceName) and nested (source.path, source.name) formats
        if (in_array($operation, ['copy', 'rename_move', 'delete'])) {
            if (isset($arguments['sourcePath']) && isset($arguments['sourceName'])) {
                    // New flat format
                $params['source'] = [
                    'path' => $arguments['sourcePath'],
                    'name' => $arguments['sourceName']
                ];
            } elseif (isset($arguments['source']) && isset($arguments['source']['path']) && isset($arguments['source']['name'])) {
                    // Legacy nested format
                $params['source'] = $arguments['source'];
            } else {
                throw new \InvalidArgumentException($operation . ' operation requires sourcePath and sourceName');
            }
        }

        // Destination parameters (required for create, copy, rename_move)
            // Support both flat (destPath, destName) and nested (destination.path, destination.name) formats
        if (in_array($operation, ['create', 'copy', 'rename_move'])) {
            if (isset($arguments['destPath']) && isset($arguments['destName'])) {
                    // New flat format
                $params['destination'] = [
                    'path' => $arguments['destPath'],
                    'name' => $arguments['destName']
                ];
            } elseif (isset($arguments['destination']) && isset($arguments['destination']['path']) && isset($arguments['destination']['name'])) {
                    // Legacy nested format
                $params['destination'] = $arguments['destination'];
            } else {
                throw new \InvalidArgumentException($operation . ' operation requires destPath and destName');
            }
        }

        // Content parameter (required for create)
        if ($operation === 'create') {
            if (!isset($arguments['content'])) {
                throw new \InvalidArgumentException('Create operation requires content parameter');
            }
            $params['content'] = $arguments['content'];
        }

        // Execute the operation
        $result = $this->projectFileService->manageFile($projectId, $operation, $params);

        return [
            'success' => true,
            'operation' => $operation,
            'result' => $result
        ];
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
    
    /**
     * Validate Spirit access to path
     * Spirits can access all files EXCEPT other Spirits' folders in /spirit/
     * 
     * @param array $arguments Tool arguments containing path and optional _spiritSlug
     * @throws \RuntimeException if access is denied
     */
    private function validateSpiritAccess(array $arguments): void
    {
        $spiritSlug = $arguments['_spiritSlug'] ?? null;
        if (!$spiritSlug) {
            return; // No Spirit context, allow all (for non-Spirit usage like user's file browser)
        }
        
        $path = $arguments['path'] ?? '/';
        
        // Normalize path
        $path = '/' . ltrim($path, '/');
        
        // Check if path is within /spirit/ directory
        if (!str_starts_with($path, '/spirit/')) {
            return; // Not in /spirit/ directory, allow access
        }
        
        // Extract the spirit folder name from path (e.g., /spirit/SpiritName/... -> SpiritName)
        $pathParts = explode('/', trim($path, '/'));
        if (count($pathParts) < 2) {
            return; // Just /spirit/ itself, allow access
        }
        
        $targetSpiritSlug = $pathParts[1]; // The folder name after /spirit/
        
        // Allow access only if it's the Spirit's own folder
        if ($targetSpiritSlug !== $spiritSlug) {
            throw new \RuntimeException("Access denied: Spirit can only access its own folder /spirit/{$spiritSlug}/, not /spirit/{$targetSpiritSlug}/");
        }
    }
}
