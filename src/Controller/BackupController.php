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
                return new JsonResponse(['error' => 'No file uploaded'], 400);
            }

            $result = $this->backupManager->uploadBackup($uploadedFile);
            
            return new JsonResponse([
                'success' => true,
                'message' => 'Backup uploaded successfully!',
                'backup' => $result
            ]);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Upload failed: ' . $e->getMessage()], 500);
        }
    }
}
