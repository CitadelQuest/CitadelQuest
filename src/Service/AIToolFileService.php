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
     * List files in a project directory
     */
    public function listFiles(array $arguments): array
    {
        $this->validateArguments($arguments, ['projectId', 'path']);
        $this->validateSpiritAccess($arguments);
        
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
     * For PDF files, returns cached annotations if available (saves AI processing costs)
     */
    public function getFileContent(array $arguments): array
    {
        $this->validateArguments($arguments, ['projectId', 'path', 'name']);
        $this->validateSpiritAccess($arguments);
        
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
            
            $withLineNumbers = $arguments['withLineNumbers'] ?? false;
            $projectId = $arguments['projectId'];
            $filename = $file->getName();
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            // For PDF files, try to use cached annotations instead of raw binary (uses AnnoService)
            $usedAnnotations = false;
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
            
            // return HTML code for frontend display
            $contentFrontendData = "";
            // default: text
            $contentFrontendData = '<pre>' . htmlspecialchars($content) . '</pre>';
            // image data, based on mime type
            if (strpos($file->getMimeType(), 'image/') === 0) {
                $contentFrontendData = '<img src="/api/project-file/' . $file->getId() . '/download" alt="' . $file->getName() . '" style="max-width: 100%; height: auto; max-height: 75vh;" class="rounded shadow"/>';
            }
            // video data, based on mime type
            if (strpos($file->getMimeType(), 'video/') === 0) {
                $contentFrontendData = '<video src="/api/project-file/' . $file->getId() . '/download" controls style="max-width: 100%;" class="rounded shadow"></video>';                
            }
            // audio data, based on mime type
            if (strpos($file->getMimeType(), 'audio/') === 0) {
                $contentFrontendData = '<audio src="/api/project-file/' . $file->getId() . '/download" controls style="width: 100%;" class="rounded shadow"></audio>';                
            }
            // PDF with annotations - show annotation info
            if ($usedAnnotations) {
                //$contentFrontendData = '<div class="alert alert-success mb-2 p-1 px-2 d-inline-block opacity-75"><i class="mdi mdi-file-document-check"></i> Using cached PDF annotations</div><pre>' . htmlspecialchars($content) . '</pre>';
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

            // binary data, not displayed (only if not using annotations)
            if (!$usedAnnotations && strpos($content, 'data:') === 0) {
                $content = "binary data, not displayed";
                // image/video/audio data, displayed
                if (strpos($file->getMimeType(), 'image/') === 0 || 
                    strpos($file->getMimeType(), 'video/') === 0 || 
                    strpos($file->getMimeType(), 'audio/') === 0) {
                    $content = 'binary data, displayed directly in frontend';
                }
            }
            
            return [
                'success' => true,
                'content' => $content,
                'file' => $file->jsonSerialize(),
                'with_line_numbers' => $withLineNumbers,
                //'used_annotations' => $usedAnnotations,
                '_frontendData' => $contentFrontendData
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
        $this->validateSpiritAccess($arguments);
        
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
    public function updateFileEfficient(array $arguments): array
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
    /* public function ensureProjectStructure(array $arguments): array
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
    } */
    
    /**
     * Get the complete project tree structure
     * Note: Spirit access validation is done per-path, tree shows all but access is restricted
     */
    public function getProjectTree(array $arguments): array
    {
        $this->validateArguments($arguments, ['projectId']);
        // Note: getProjectTree shows full structure, access control is enforced on individual file operations
        
        try {
            $tree = $this->projectFileService->showProjectTree($arguments['projectId'], true);
            
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
     * Search files by query string matching against path and name
     */
    public function searchFile(array $arguments): array
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
     * Supports: create, copy, rename_move, delete
     * 
     * Simplified flat parameters for better LLM compatibility:
     * - sourcePath, sourceName: for copy, rename_move, delete
     * - destPath, destName: for create, copy, rename_move
     * - content: for create operation
     */
    public function manageFile(array $arguments): array
    {
        try {
            // Validate required parameters
            if (!isset($arguments['projectId']) || !isset($arguments['operation'])) {
                throw new \InvalidArgumentException('manageFile requires projectId and operation parameters');
            }
            
            // Validate Spirit access for source path
            if (isset($arguments['sourcePath'])) {
                $this->validateSpiritAccess(['path' => $arguments['sourcePath'], '_spiritSlug' => $arguments['_spiritSlug'] ?? null]);
            }
            // Validate Spirit access for destination path
            if (isset($arguments['destPath'])) {
                $this->validateSpiritAccess(['path' => $arguments['destPath'], '_spiritSlug' => $arguments['_spiritSlug'] ?? null]);
            }

            $projectId = $arguments['projectId'];
            $operation = $arguments['operation'];

            // Validate operation type
            $validOperations = ['create', 'copy', 'rename_move', 'delete'];
            if (!in_array($operation, $validOperations)) {
                throw new \InvalidArgumentException('Invalid operation. Supported operations: ' . implode(', ', $validOperations));
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

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'operation' => $arguments['operation'] ?? 'unknown'
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
