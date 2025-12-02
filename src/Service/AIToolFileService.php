<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Service for AI Tool file operations
 */
class AIToolFileService
{
    public function __construct(
        private readonly ProjectFileService $projectFileService,
        private readonly Security $security,
        private readonly SluggerInterface $slugger
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
     * For PDF files, returns cached annotations if available (saves AI processing costs)
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
            
            $withLineNumbers = $arguments['withLineNumbers'] ?? false;
            $projectId = $arguments['projectId'];
            $filename = $file->getName();
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            // For PDF files, try to use cached annotations instead of raw binary
            $usedAnnotations = false;
            if ($extension === 'pdf') {
                $annotationPath = '/annotations/pdf/' . $this->slugger->slug($filename);
                $annotationFilename = $filename . '.anno';
                
                try {
                    $annotationFile = $this->projectFileService->findByPathAndName(
                        $projectId,
                        $annotationPath,
                        $annotationFilename
                    );
                    
                    if ($annotationFile) {
                        $annotationContent = json_decode(
                            $this->projectFileService->getFileContent($annotationFile->getId()),
                            true
                        );
                        
                        // Verify annotation matches the file and extract text content
                        if (isset($annotationContent['file']['name']) && 
                            $annotationContent['file']['name'] === $filename &&
                            isset($annotationContent['file']['content'])) {
                            
                            // Build readable text from annotation content array
                            $textParts = [];
                            $annoContent = $annotationContent['file']['content'];
                            
                            if (is_array($annoContent)) {
                                foreach ($annoContent as $item) {
                                    if (isset($item['text'])) {
                                        $textParts[] = $item['text'];
                                    }
                                }
                            }
                            
                            $content = implode("\n", $textParts);
                            $usedAnnotations = true;
                        }
                    }
                } catch (\Exception $e) {
                    // Annotation not found or error - fall back to raw content
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
                $contentFrontendData = '<div class="alert alert-success mb-2 p-1 px-2 d-inline-block opacity-75"><i class="mdi mdi-file-document-check"></i> Using cached PDF annotations</div><pre>' . htmlspecialchars($content) . '</pre>';
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
     */
    public function updateFileEfficient(array $arguments): array
    {
        $this->validateArguments($arguments, ['projectId', 'path', 'name', 'updates']);
        
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
            
            if (!is_array($arguments['updates'])) {
                return [
                    'success' => false,
                    'error' => 'Updates must be an array of update operations'
                ];
            }
            
            $updatedFile = $this->projectFileService->updateFileEfficient(
                $file->getId(),
                $arguments['updates']
            );
            
            return [
                'success' => true,
                'file' => $updatedFile->jsonSerialize(),
                'operations_applied' => count($arguments['updates'])
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
     */
    public function getProjectTree(array $arguments): array
    {
        $this->validateArguments($arguments, ['projectId']);
        
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
     * Find a file by path and name
     */
    /* public function findFileByPath(array $arguments): array
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
    } */
    
    /**
     * Unified file management operations for AI tools
     * Supports: create, copy, rename_move, delete
     */
    public function manageFile(array $arguments): array
    {
        try {
            // Validate required parameters
            if (!isset($arguments['projectId']) || !isset($arguments['operation'])) {
                throw new \InvalidArgumentException('manageFile requires projectId and operation parameters');
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
            if (in_array($operation, ['copy', 'rename_move', 'delete'])) {
                if (!isset($arguments['source']) || !isset($arguments['source']['path']) || !isset($arguments['source']['name'])) {
                    throw new \InvalidArgumentException($operation . ' operation requires source.path and source.name');
                }
                $params['source'] = $arguments['source'];
            }

            // Destination parameters (required for create, copy, rename_move)
            if (in_array($operation, ['create', 'copy', 'rename_move'])) {
                if (!isset($arguments['destination']) || !isset($arguments['destination']['path']) || !isset($arguments['destination']['name'])) {
                    throw new \InvalidArgumentException($operation . ' operation requires destination.path and destination.name');
                }
                $params['destination'] = $arguments['destination'];
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
}
