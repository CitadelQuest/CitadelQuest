<?php

namespace App\Controller;

use App\Service\BackupManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class BackupController extends AbstractController
{
    public function __construct(
        private BackupManager $backupManager
    ) {}

    #[Route('/backup', name: 'app_backup_index')]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        $backups = $this->backupManager->getUserBackups();
        return $this->render('backup/index.html.twig', [
            'backups' => $backups
        ]);
    }

    #[Route('/backup/create', name: 'app_backup_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function create(Request $request): JsonResponse
    {
        $session = $request->getSession();
        $lockKey = 'backup_in_progress_' . $this->getUser()->getId();

        // Check if backup is already in progress
        if ($session->has($lockKey)) {
            return $this->json([
                'error' => 'A backup is already in progress'
            ], Response::HTTP_CONFLICT);
        }

        try {
            // Set backup lock
            $session->set($lockKey, true);
            
            $backupPath = $this->backupManager->createBackup();
            
            // Get backup file info
            $filename = basename($backupPath);
            $size = filesize($backupPath);
            
            // Remove backup lock
            $session->remove($lockKey);
            
            return new JsonResponse([
                'success' => true,
                'message' => 'Backup created successfully!',
                'backup' => [
                    'filename' => $filename,
                    'size' => $size,
                    'created_at' => date('Y-m-d H:i:s')
                ]
            ]);
        } catch (\Exception $e) {
            // Remove backup lock on error
            $session->remove($lockKey);
            
            return $this->json([
                'error' => 'Backup creation failed: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/backup/download/{filename}', name: 'app_backup_download')]
    #[IsGranted('ROLE_USER')]
    public function download(string $filename): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $backupPath = sprintf('%s/%s/%s',
            $this->getParameter('app.backup_dir'),
            $user->getId(),
            $filename
        );

        if (!file_exists($backupPath)) {
            throw $this->createNotFoundException('Backup file not found');
        }

        $response = new BinaryFileResponse($backupPath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename
        );

        return $response;
    }

    #[Route('/backup/delete/{filename}', name: 'app_backup_delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function delete(string $filename): JsonResponse
    {
        try {
            $this->backupManager->deleteBackup($filename);
            return new JsonResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/backup/restore/{filename}', name: 'app_backup_restore', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function restore(string $filename): JsonResponse
    {
        try {
            $this->backupManager->restoreBackup($filename);
            return new JsonResponse([
                'success' => true,
                'message' => 'Backup restored successfully! Your data has been restored to the selected backup point.'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/backup/upload', name: 'app_backup_upload', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function upload(Request $request): JsonResponse
    {
        try {
            $uploadedFile = $request->files->get('backup');
            
            if (!$uploadedFile) {
                // Log what we received
                error_log('[BackupUpload] No file in request. Files: ' . json_encode($request->files->keys()));
                error_log('[BackupUpload] POST max size: ' . ini_get('post_max_size') . ', Upload max: ' . ini_get('upload_max_filesize'));
                return new JsonResponse(['error' => 'No file uploaded. Check PHP upload limits.'], 400);
            }

            // Log file info
            error_log('[BackupUpload] Received file: ' . $uploadedFile->getClientOriginalName() . ', size: ' . $uploadedFile->getSize() . ', error: ' . $uploadedFile->getError());

            $result = $this->backupManager->uploadBackup($uploadedFile);
            
            return new JsonResponse([
                'success' => true,
                'message' => 'Backup uploaded successfully!',
                'backup' => $result
            ]);
        } catch (\InvalidArgumentException $e) {
            error_log('[BackupUpload] InvalidArgumentException: ' . $e->getMessage());
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            error_log('[BackupUpload] Exception: ' . $e->getMessage());
            return new JsonResponse(['error' => 'Upload failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Initialize a chunked upload session
     * Returns an upload ID to be used for subsequent chunk uploads
     */
    #[Route('/backup/upload/init', name: 'app_backup_upload_init', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function initChunkedUpload(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $filename = $data['filename'] ?? null;
            $totalSize = $data['totalSize'] ?? null;
            $totalChunks = $data['totalChunks'] ?? null;

            if (!$filename || !$totalSize || !$totalChunks) {
                return new JsonResponse(['error' => 'Missing required fields: filename, totalSize, totalChunks'], 400);
            }

            // Validate file extension
            if (!str_ends_with(strtolower($filename), '.citadel')) {
                return new JsonResponse(['error' => 'Invalid file format. Only .citadel files are accepted.'], 400);
            }

            // Validate file size (1000MB max)
            $maxSize = 1048576000; // 1000MB
            if ($totalSize > $maxSize) {
                return new JsonResponse(['error' => 'File is too large. Maximum size is 1000MB.'], 400);
            }

            $result = $this->backupManager->initChunkedUpload($filename, $totalSize, $totalChunks);

            return new JsonResponse([
                'success' => true,
                'uploadId' => $result['uploadId'],
                'chunkSize' => $result['chunkSize']
            ]);
        } catch (\Exception $e) {
            error_log('[BackupUpload] Init chunked upload failed: ' . $e->getMessage());
            return new JsonResponse(['error' => 'Failed to initialize upload: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Upload a single chunk
     */
    #[Route('/backup/upload/chunk', name: 'app_backup_upload_chunk', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function uploadChunk(Request $request): JsonResponse
    {
        try {
            $uploadId = $request->request->get('uploadId');
            $chunkIndex = (int) $request->request->get('chunkIndex');
            $chunk = $request->files->get('chunk');

            if (!$uploadId || $chunkIndex === null || !$chunk) {
                return new JsonResponse(['error' => 'Missing required fields: uploadId, chunkIndex, chunk'], 400);
            }

            $result = $this->backupManager->uploadChunk($uploadId, $chunkIndex, $chunk);

            return new JsonResponse([
                'success' => true,
                'chunkIndex' => $chunkIndex,
                'received' => $result['received']
            ]);
        } catch (\Exception $e) {
            error_log('[BackupUpload] Chunk upload failed: ' . $e->getMessage());
            return new JsonResponse(['error' => 'Chunk upload failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Finalize chunked upload - assemble chunks into final backup file
     */
    #[Route('/backup/upload/finalize', name: 'app_backup_upload_finalize', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function finalizeChunkedUpload(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $uploadId = $data['uploadId'] ?? null;

            if (!$uploadId) {
                return new JsonResponse(['error' => 'Missing uploadId'], 400);
            }

            $result = $this->backupManager->finalizeChunkedUpload($uploadId);

            return new JsonResponse([
                'success' => true,
                'message' => 'Backup uploaded successfully!',
                'backup' => $result
            ]);
        } catch (\Exception $e) {
            error_log('[BackupUpload] Finalize failed: ' . $e->getMessage());
            return new JsonResponse(['error' => 'Failed to finalize upload: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Cancel/cleanup a chunked upload
     */
    #[Route('/backup/upload/cancel', name: 'app_backup_upload_cancel', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function cancelChunkedUpload(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $uploadId = $data['uploadId'] ?? null;

            if (!$uploadId) {
                return new JsonResponse(['error' => 'Missing uploadId'], 400);
            }

            $this->backupManager->cancelChunkedUpload($uploadId);

            return new JsonResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}
