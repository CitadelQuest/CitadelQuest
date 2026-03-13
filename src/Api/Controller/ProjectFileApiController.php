<?php

namespace App\Api\Controller;

use App\Entity\ProjectFile;
use App\Service\ProjectFileService;
use App\Service\AIToolMemoryService;
use App\Service\AnnoService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Psr\Log\LoggerInterface;

#[Route('/api/project-file')]
#[IsGranted('ROLE_USER')]
class ProjectFileApiController extends AbstractController
{
    public function __construct(
        private readonly ProjectFileService $projectFileService,
        private readonly AIToolMemoryService $aiToolMemoryService,
        private readonly AnnoService $annoService,
        private readonly Security $security,
        private readonly LoggerInterface $logger
    ) {
    }
    
    /**
     * Get project statistics (total size, file count, etc.)
     */
    #[Route('/stats/{projectId}', name: 'app_api_project_file_stats', methods: ['GET'])]
    public function stats(string $projectId): JsonResponse
    {
        try {
            $totalSize = $this->projectFileService->getTotalProjectSize($projectId);
            $formattedSize = $this->projectFileService->formatBytes($totalSize);
            
            return $this->json([
                'success' => true,
                'totalSize' => $totalSize,
                'formattedSize' => $formattedSize
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }
    
    /**
     * List files in a directory
     */
    #[Route('/list/{projectId}', name: 'app_api_project_file_list', methods: ['GET'])]
    public function list(string $projectId, Request $request): JsonResponse
    {
        $path = $request->query->get('path', '/');
        
        try {
            $files = $this->projectFileService->listFiles($projectId, $path);

            // Enrich with remote file info
            $enrichedFiles = array_map(function (ProjectFile $file) {
                $data = $file->jsonSerialize();
                $remote = $this->projectFileService->getRemoteFileRecord($file->getId());
                $data['isRemote'] = $remote !== null;
                if ($remote) {
                    $data['sourceUrl'] = $remote['source_url'] ?? null;
                    $data['syncedAt'] = $remote['synced_at'] ?? null;
                }
                $data['isShared'] = $this->projectFileService->isSharedFile($file->getId());
                return $data;
            }, $files);
            
            return $this->json([
                'success' => true,
                'files' => $enrichedFiles
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }
    
    /**
     * Get images in a directory (for gallery view)
     */
    #[Route('/images/{projectId}', name: 'app_api_project_file_images', methods: ['GET'])]
    public function getImages(string $projectId, Request $request): JsonResponse
    {
        $path = $request->query->get('path', '/');
        
        try {
            $images = $this->projectFileService->getImagesInDirectory($projectId, $path);
            
            return $this->json([
                'success' => true,
                'images' => $images,
                'count' => count($images)
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }
    
    /**
     * Get file details
     */
    #[Route('/{fileId}', name: 'app_api_project_file_get', methods: ['GET'])]
    public function get(string $fileId): JsonResponse
    {
        try {
            $file = $this->projectFileService->findById($fileId);
            
            if (!$file) {
                throw new NotFoundHttpException('File not found');
            }

            // Enrich with remote file info
            $data = $file->jsonSerialize();
            $remote = $this->projectFileService->getRemoteFileRecord($file->getId());
            $data['isRemote'] = $remote !== null;
            if ($remote) {
                $data['sourceUrl'] = $remote['source_url'] ?? null;
                $data['syncedAt'] = $remote['synced_at'] ?? null;
            }
            $data['isShared'] = $this->projectFileService->isSharedFile($file->getId());
            
            return $this->json([
                'success' => true,
                'file' => $data
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], $e instanceof NotFoundHttpException ? 404 : 400);
        }
    }
    
    /**
     * Get file content
     * Supports ?thumb=1 parameter for image thumbnails
     */
    #[Route('/{fileId}/content', name: 'app_api_project_file_content', methods: ['GET'])]
    public function getContent(string $fileId, Request $request): JsonResponse
    {
        try {
            $file = $this->projectFileService->findById($fileId);
            
            if (!$file) {
                throw new NotFoundHttpException('File not found');
            }
            
            if ($file->isDirectory()) {
                throw new BadRequestHttpException('Cannot get content of a directory');
            }
            
            // Check if thumbnail is requested
            $wantsThumbnail = $request->query->getBoolean('thumb', false);
            
            if ($wantsThumbnail && $this->projectFileService->isImageFile($file->getName())) {
                $content = $this->projectFileService->getFileContentWithThumbnail($fileId);
                if ($content) {
                    return $this->json([
                        'success' => true,
                        'content' => $content,
                        'file' => $file,
                        'thumbnail' => true
                    ]);
                }
            }
            
            // Return full content
            $content = $this->projectFileService->getFileContent($fileId);
            
            return $this->json([
                'success' => true,
                'content' => $content,
                'file' => $file
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], $e instanceof NotFoundHttpException ? 404 : 400);
        }
    }
    
    /**
     * Download file
     */
    #[Route('/{fileId}/download', name: 'app_api_project_file_download', methods: ['GET'])]
    public function download(string $fileId, ParameterBagInterface $params, Request $request): BinaryFileResponse
    {
        try {
            $file = $this->projectFileService->findById($fileId);
            
            if (!$file) {
                throw new NotFoundHttpException('File not found');
            }
            
            if ($file->isDirectory()) {
                throw new BadRequestHttpException('Cannot download a directory');
            }
            
            $filePath = $params->get('kernel.project_dir') . '/var/user_data/' . $this->security->getUser()->getId() . '/p/' . $file->getProjectId();
            if ($file->getPath() !== '/') {
                $filePath .= $file->getPath();
            }
            $filePath .= '/' . $file->getName();
            
            $response = new BinaryFileResponse($filePath);
            
            // Use inline disposition for embeddable content (PDF, images, audio, video)
            // unless explicitly requested as attachment via ?download=1
            $forceDownload = $request->query->getBoolean('download', false);
            $mimeType = $file->getMimeType();
            $isEmbeddable = str_starts_with($mimeType, 'image/') 
                || str_starts_with($mimeType, 'audio/') 
                || str_starts_with($mimeType, 'video/') 
                || $mimeType === 'application/pdf';
            
            $disposition = ($forceDownload || !$isEmbeddable) 
                ? ResponseHeaderBag::DISPOSITION_ATTACHMENT 
                : ResponseHeaderBag::DISPOSITION_INLINE;
            
            $response->setContentDisposition($disposition, $file->getName());
            
            return $response;
        } catch (\Exception $e) {
            throw $e;
        }
    }
    
    /**
     * Create directory
     */
    #[Route('/{projectId}/directory', name: 'app_api_project_file_create_directory', methods: ['POST'])]
    public function createDirectory(string $projectId, Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!isset($data['path']) || !isset($data['name'])) {
                throw new BadRequestHttpException('Path and name are required');
            }
            
            $directory = $this->projectFileService->createDirectory(
                $projectId,
                $data['path'],
                $data['name']
            );
            
            return $this->json([
                'success' => true,
                'directory' => $directory
            ], 201);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }
    
    /**
     * Create file with content
     */
    #[Route('/{projectId}/file', name: 'app_api_project_file_create_file', methods: ['POST'])]
    public function createFile(string $projectId, Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!isset($data['path']) || !isset($data['name']) || !isset($data['content'])) {
                throw new BadRequestHttpException('Path, name and content are required');
            }
            
            $file = $this->projectFileService->createFile(
                $projectId,
                $data['path'],
                $data['name'],
                $data['content'],
                $data['mimeType'] ?? null
            );
            
            return $this->json([
                'success' => true,
                'file' => $file
            ], 201);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }
    
    /**
     * Upload file
     */
    #[Route('/{projectId}/upload', name: 'app_api_project_file_upload', methods: ['POST'])]
    public function uploadFile(string $projectId, Request $request): JsonResponse
    {
        try {
            $path = $request->request->get('path', '/');
            $uploadedFile = $request->files->get('file');
            
            if (!$uploadedFile) {
                throw new BadRequestHttpException('No file uploaded');
            }
            
            $file = $this->projectFileService->uploadFile($projectId, $path, $uploadedFile);
            
            return $this->json([
                'success' => true,
                'file' => $file
            ], 201);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Generate PDF annotations (.anno) for a file
     * Called proactively after File Browser PDF upload to pre-generate parsed text.
     * Uses a cheap AI model — the provider parses PDFs at the API level.
     */
    #[Route('/{fileId}/generate-annotations', name: 'app_api_project_file_generate_annotations', methods: ['POST'])]
    public function generateAnnotations(string $fileId, Request $request): JsonResponse
    {
        try {
            $file = $this->projectFileService->findById($fileId);
            if (!$file) {
                return $this->json(['success' => false, 'error' => 'File not found'], 404);
            }

            $filename = $file->getName();
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            if ($extension !== 'pdf') {
                return $this->json(['success' => false, 'error' => 'Only PDF files supported'], 400);
            }

            $projectId = $file->getProjectId();

            // Check if .anno already exists
            if ($this->annoService->hasAnnotation(AnnoService::TYPE_PDF, $filename, $projectId)) {
                return $this->json(['success' => true, 'message' => 'Annotations already exist', 'alreadyExists' => true]);
            }

            // Release session lock early — AI call can take 3-15s
            $request->getSession()->save();

            // Generate .anno via cheap AI call
            $pdfContent = $this->projectFileService->getFileContent($fileId);
            $parsedText = $this->aiToolMemoryService->generatePdfAnnotation($filename, $pdfContent, $projectId);

            if ($parsedText) {
                return $this->json([
                    'success' => true,
                    'message' => 'PDF annotations generated successfully',
                    'textLength' => strlen($parsedText)
                ]);
            }

            return $this->json([
                'success' => false,
                'error' => 'Failed to generate PDF annotations — AI response did not include annotations'
            ], 500);

        } catch (\Exception $e) {
            $this->logger->error('generateAnnotations failed', [
                'fileId' => $fileId,
                'error' => $e->getMessage()
            ]);
            return $this->json([
                'success' => false,
                'error' => 'Failed to generate annotations: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update file content
     */
    #[Route('/{fileId}/content', name: 'app_api_project_file_update_content', methods: ['PUT'])]
    public function updateContent(string $fileId, Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!isset($data['content'])) {
                throw new BadRequestHttpException('Content is required');
            }
            
            $file = $this->projectFileService->updateFile($fileId, $data['content']);
            
            return $this->json([
                'success' => true,
                'file' => $file
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], $e instanceof NotFoundHttpException ? 404 : 400);
        }
    }
    
    /**
     * Delete file or directory
     */
    #[Route('/{fileId}', name: 'app_api_project_file_delete', methods: ['DELETE'])]
    public function delete(string $fileId): JsonResponse
    {
        try {
            $result = $this->projectFileService->delete($fileId);
            
            if (!$result) {
                throw new NotFoundHttpException('File not found');
            }
            
            return $this->json([
                'success' => true
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], $e instanceof NotFoundHttpException ? 404 : 400);
        }
    }
    
    /**
     * Get file versions
     */
    #[Route('/{fileId}/versions', name: 'app_api_project_file_versions', methods: ['GET'])]
    public function getVersions(string $fileId): JsonResponse
    {
        try {
            $file = $this->projectFileService->findById($fileId);
            
            if (!$file) {
                throw new NotFoundHttpException('File not found');
            }
            
            if ($file->isDirectory()) {
                throw new BadRequestHttpException('Directories do not have versions');
            }
            
            $versions = $this->projectFileService->getFileVersions($fileId);
            
            return $this->json([
                'success' => true,
                'versions' => $versions
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], $e instanceof NotFoundHttpException ? 404 : 400);
        }
    }
    
    /**
     * Ensure project directory structure exists
     */
    #[Route('/{projectId}/ensure-structure', name: 'app_api_project_file_ensure_structure', methods: ['POST'])]
    public function ensureProjectStructure(string $projectId): JsonResponse
    {
        try {
            $this->projectFileService->ensureProjectDirectoryStructure($projectId);
            
            return $this->json([
                'success' => true
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }
    
    /**
     * Get the complete project tree structure
     */
    #[Route('/{projectId}/tree', name: 'app_api_project_file_tree', methods: ['GET'])]
    public function getProjectTree(string $projectId): JsonResponse
    {
        try {
            $tree = $this->projectFileService->showProjectTree($projectId);

            return $this->json([
                'success' => true,
                'tree' => $tree
            ]);
        } catch (\Exception $e) {
            $this->logger->error('getProjectTree: Error retrieving project tree', [
                'projectId' => $projectId,
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Manage files: copy, move/rename, delete
     * 
     * Request body:
     * {
     *   "operation": "copy" | "rename_move" | "delete",
     *   "source": { "path": "/folder", "name": "file.txt" },
     *   "destination": { "path": "/other", "name": "newname.txt" }  // not needed for delete
     * }
     */
    #[Route('/{projectId}/manage', name: 'app_api_project_file_manage', methods: ['POST'])]
    public function manageFile(string $projectId, Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!isset($data['operation'])) {
                return $this->json([
                    'success' => false,
                    'error' => 'Missing operation parameter'
                ], 400);
            }

            $operation = $data['operation'];
            $validOperations = ['copy', 'rename_move', 'delete'];
            
            if (!in_array($operation, $validOperations)) {
                return $this->json([
                    'success' => false,
                    'error' => 'Invalid operation. Supported: ' . implode(', ', $validOperations)
                ], 400);
            }

            // Build params for ProjectFileService::manageFile
            $params = [];

            // Source required for copy, rename_move, delete
            if (in_array($operation, ['copy', 'rename_move', 'delete'])) {
                if (!isset($data['source']['path']) || !isset($data['source']['name'])) {
                    return $this->json([
                        'success' => false,
                        'error' => 'Source path and name are required'
                    ], 400);
                }
                $params['source'] = $data['source'];
            }

            // Destination required for copy, rename_move
            if (in_array($operation, ['copy', 'rename_move'])) {
                if (!isset($data['destination']['path']) || !isset($data['destination']['name'])) {
                    return $this->json([
                        'success' => false,
                        'error' => 'Destination path and name are required'
                    ], 400);
                }
                $params['destination'] = $data['destination'];
            }

            $result = $this->projectFileService->manageFile($projectId, $operation, $params);

            return $this->json([
                'success' => true,
                'operation' => $operation,
                'result' => $result
            ]);
        } catch (\Exception $e) {
            $this->logger->error('manageFile: Error managing file', [
                'projectId' => $projectId,
                'error' => $e->getMessage()
            ]);

            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }
    
    /**
     * Create ZIP archive from directory and save as project file
     */
    #[Route('/{fileId}/create-zip', name: 'app_api_project_file_create_zip', methods: ['POST'])]
    public function createZip(string $fileId, ParameterBagInterface $params): JsonResponse
    {
        try {
            $file = $this->projectFileService->findById($fileId);
            
            if (!$file) {
                throw new NotFoundHttpException('Directory not found');
            }
            
            if (!$file->isDirectory()) {
                throw new BadRequestHttpException('This endpoint is only for directories.');
            }
            
            // Build directory path
            $userId = $this->security->getUser()->getId();
            $basePath = $params->get('kernel.project_dir') . '/var/user_data/' . $userId . '/p/' . $file->getProjectId();
            $dirPath = $basePath;
            if ($file->getPath() !== '/') {
                $dirPath .= $file->getPath();
            }
            $dirPath .= '/' . $file->getName();
            
            if (!is_dir($dirPath)) {
                throw new NotFoundHttpException('Directory not found on filesystem');
            }
            
            // Create ZIP file name with timestamp
            $zipFileName = $file->getName() . '_' . date('Y-m-d_His') . '.zip';
            
            // Determine the parent path where the ZIP will be saved (same level as the directory)
            $zipSavePath = $file->getPath();
            
            // Build the absolute path for the ZIP file
            $zipAbsolutePath = $basePath;
            if ($zipSavePath !== '/') {
                $zipAbsolutePath .= $zipSavePath;
            }
            $zipAbsolutePath .= '/' . $zipFileName;
            
            $zip = new \ZipArchive();
            if ($zip->open($zipAbsolutePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Failed to create ZIP archive');
            }
            
            // Add directory contents recursively
            $this->addDirectoryToZip($zip, $dirPath, $file->getName());
            $zip->close();
            
            // Register the ZIP file in the database
            $zipFile = $this->projectFileService->registerExistingFile(
                $file->getProjectId(),
                $zipSavePath,
                $zipFileName,
                'application/zip'
            );
            
            return $this->json([
                'success' => true,
                'file' => [
                    'id' => $zipFile->getId(),
                    'name' => $zipFile->getName(),
                    'path' => $zipFile->getPath(),
                    'size' => $zipFile->getSize(),
                ]
            ]);
        } catch (\Exception $e) {
            $this->logger->error('createZip: Error creating ZIP', [
                'fileId' => $fileId,
                'error' => $e->getMessage()
            ]);
            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }
    
    /**
     * Extract ZIP file into the same directory
     */
    #[Route('/{fileId}/extract-zip', name: 'app_api_project_file_extract_zip', methods: ['POST'])]
    public function extractZip(string $fileId, ParameterBagInterface $params): JsonResponse
    {
        try {
            $file = $this->projectFileService->findById($fileId);
            
            if (!$file) {
                throw new NotFoundHttpException('File not found');
            }
            
            if ($file->isDirectory()) {
                throw new BadRequestHttpException('This endpoint is only for ZIP files.');
            }
            
            // Verify it's a ZIP file
            $extension = pathinfo($file->getName(), PATHINFO_EXTENSION);
            if (strtolower($extension) !== 'zip') {
                throw new BadRequestHttpException('This endpoint is only for ZIP files.');
            }
            
            // Build absolute path to the ZIP file
            $userId = $this->security->getUser()->getId();
            $basePath = $params->get('kernel.project_dir') . '/var/user_data/' . $userId . '/p/' . $file->getProjectId();
            $zipAbsolutePath = $basePath;
            if ($file->getPath() !== '/') {
                $zipAbsolutePath .= $file->getPath();
            }
            $zipAbsolutePath .= '/' . $file->getName();
            
            if (!file_exists($zipAbsolutePath)) {
                throw new NotFoundHttpException('ZIP file not found on filesystem');
            }
            
            // Extract to a directory named after the ZIP file (without extension)
            $extractDirName = pathinfo($file->getName(), PATHINFO_FILENAME);
            $extractPath = $file->getPath();
            $extractAbsolutePath = $basePath;
            if ($extractPath !== '/') {
                $extractAbsolutePath .= $extractPath;
            }
            $extractAbsolutePath .= '/' . $extractDirName;
            
            // Create extraction directory if it doesn't exist
            if (!is_dir($extractAbsolutePath)) {
                mkdir($extractAbsolutePath, 0755, true);
            }
            
            // Open and extract ZIP
            $zip = new \ZipArchive();
            if ($zip->open($zipAbsolutePath) !== true) {
                throw new \RuntimeException('Failed to open ZIP archive');
            }
            
            // Extract all files
            $zip->extractTo($extractAbsolutePath);
            $zip->close();
            
            // Register all extracted files and directories in the database
            $extractedCount = $this->registerExtractedFiles(
                $file->getProjectId(),
                $extractAbsolutePath,
                $extractPath,
                $extractDirName
            );
            
            return $this->json([
                'success' => true,
                'extractedTo' => $extractDirName,
                'extractedCount' => $extractedCount,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('extractZip: Error extracting ZIP', [
                'fileId' => $fileId,
                'error' => $e->getMessage()
            ]);
            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }
    
    /**
     * Register extracted files and directories in the database recursively
     */
    private function registerExtractedFiles(string $projectId, string $extractAbsolutePath, string $parentDbPath, string $dirName): int
    {
        $count = 0;
        
        // First, ensure the extraction directory itself is registered
        $existing = $this->projectFileService->findByPathAndName($projectId, $parentDbPath, $dirName);
        if (!$existing) {
            try {
                $this->projectFileService->createDirectory($projectId, $parentDbPath, $dirName);
            } catch (\Exception $e) {
                // Directory might already exist on filesystem but not in DB - that's ok, createDirectory handles it
                $this->logger->warning('extractZip: createDirectory warning', ['error' => $e->getMessage()]);
            }
        }
        $count++;
        
        // Build the database path for children of this directory
        $currentDbPath = $parentDbPath === '/' ? '/' . $dirName : $parentDbPath . '/' . $dirName;
        
        // Iterate through the extracted contents
        $iterator = new \DirectoryIterator($extractAbsolutePath);
        foreach ($iterator as $item) {
            if ($item->isDot()) continue;
            
            if ($item->isDir()) {
                // Recursively register subdirectories
                $count += $this->registerExtractedFiles(
                    $projectId,
                    $item->getPathname(),
                    $currentDbPath,
                    $item->getFilename()
                );
            } else {
                // Skip thumbnail cache files
                if (str_ends_with($item->getFilename(), '.thumb')) {
                    continue;
                }
                
                // Register file
                try {
                    $this->projectFileService->registerExistingFile(
                        $projectId,
                        $currentDbPath,
                        $item->getFilename()
                    );
                    $count++;
                } catch (\Exception $e) {
                    $this->logger->warning('extractZip: Failed to register file', [
                        'file' => $item->getFilename(),
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
        
        return $count;
    }
    
    /**
     * Add directory recursively to ZIP archive
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
                
                // Skip thumbnail cache files
                if (str_ends_with($filePath, '.thumb')) {
                    continue;
                }
                
                $relativePath = $zipPath . '/' . substr($filePath, strlen($dir) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }
    }
}
