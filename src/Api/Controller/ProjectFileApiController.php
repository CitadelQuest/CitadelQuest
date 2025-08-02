<?php

namespace App\Api\Controller;

use App\Service\ProjectFileService;
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

#[Route('/api/project-file')]
#[IsGranted('ROLE_USER')]
class ProjectFileApiController extends AbstractController
{
    public function __construct(
        private readonly ProjectFileService $projectFileService,
        private readonly Security $security
    ) {
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
            
            return $this->json([
                'success' => true,
                'files' => $files
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
     * Get file content
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
    public function download(string $fileId, ParameterBagInterface $params): BinaryFileResponse
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
            $response->setContentDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $file->getName()
            );
            
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
}
