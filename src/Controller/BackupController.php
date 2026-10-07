<?php

namespace App\Controller;

use App\Service\BackupJobService;
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
        private BackupManager $backupManager,
        private BackupJobService $backupJobService
    ) {}

    #[Route('/backup', name: 'app_backup_index')]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        $backups = $this->backupManager->getUserBackups();

        // If a background backup is still running (e.g. the page was reloaded mid-job),
        // hand its id to the template so the UI can resume polling.
        $activeJobId = null;
        try {
            $this->backupJobService->failStaleJobs();
            $activeJobId = $this->backupJobService->findActive()['id'] ?? null;
        } catch (\Throwable $e) {
            // Non-fatal: the page must still render even if the job table is unavailable.
        }

        return $this->render('backup/index.html.twig', [
            'backups' => $backups,
            'activeBackupJobId' => $activeJobId,
        ]);
    }

    /**
     * Start a background backup job.
     *
     * The .citadel archive is built by a detached CLI worker (app:backup-create) so the
     * request returns immediately and never hits Cloudflare's 100s proxy read timeout
     * (HTTP 524) on large backups. The browser polls /backup/status/{jobId} afterwards.
     */
    #[Route('/backup/create', name: 'app_backup_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function create(): JsonResponse
    {
        $userId = (string) $this->getUser()->getId();

        try {
            $this->backupJobService->failStaleJobs();

            // A backup is already running (e.g. started from another tab) — hand back its
            // id so the caller can simply resume polling instead of starting a second job.
            $active = $this->backupJobService->findActive();
            if ($active) {
                return $this->json([
                    'alreadyRunning' => true,
                    'jobId' => $active['id'],
                    'status' => $active['status'],
                ], Response::HTTP_CONFLICT);
            }

            $jobId = $this->backupJobService->create();

            try {
                $this->spawnBackupWorker($userId, $jobId);
            } catch (\Throwable $spawnError) {
                // Could not start the background worker — fail the job now so the UI
                // shows a clear error instead of polling a job that will never run.
                $this->backupJobService->markFailed($jobId, 'Could not start background worker: ' . $spawnError->getMessage());
                return $this->json([
                    'error' => 'Could not start background processing. ' . $spawnError->getMessage(),
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            // Return immediately — the browser polls /backup/status/{jobId}.
            return $this->json([
                'success' => true,
                'jobId' => $jobId,
                'status' => BackupJobService::STATUS_PENDING,
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Backup creation failed: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Poll the status of a background backup job.
     * Lightweight + fast — always returns well under Cloudflare's 100s window.
     */
    #[Route('/backup/status/{jobId}', name: 'app_backup_status', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function status(string $jobId): JsonResponse
    {
        try {
            $job = $this->backupJobService->find($jobId);
            if (!$job) {
                return $this->json(['error' => 'Backup job not found'], Response::HTTP_NOT_FOUND);
            }

            $done = in_array($job['status'], [
                BackupJobService::STATUS_COMPLETED,
                BackupJobService::STATUS_FAILED,
            ], true);

            return $this->json([
                'success' => true,
                'status' => $job['status'],
                'done' => $done,
                'error' => $job['error'] ?? null,
                'filename' => $job['filename'] ?? null,
                'size' => isset($job['size']) ? (int) $job['size'] : null,
            ]);

        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
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

    /**
     * Spawn the detached background worker that creates a backup.
     * The process is fully detached (nohup + background) so it outlives this HTTP request.
     */
    private function spawnBackupWorker(string $userId, string $jobId): void
    {
        // Ensure exec() is available (some hardened PHP setups disable it)
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (!function_exists('exec') || in_array('exec', $disabled, true)) {
            throw new \RuntimeException('PHP exec() is disabled; cannot spawn background worker.');
        }

        $projectDir = $this->getParameter('kernel.project_dir');
        $env = $this->getParameter('kernel.environment');
        $logFile = $projectDir . '/var/log/backup-worker.log';

        $php = $this->resolvePhpBinary();
        if ($php === null) {
            throw new \RuntimeException('Could not locate a PHP CLI binary to run the worker (php-fpm is not usable).');
        }

        $cmd = sprintf(
            'nohup %s %s/bin/console app:backup-create %s %s --env=%s >> %s 2>&1 &',
            escapeshellarg($php),
            $projectDir,
            escapeshellarg($userId),
            escapeshellarg($jobId),
            escapeshellarg($env),
            escapeshellarg($logFile)
        );

        // Debug trace so we can see exactly what was launched (and from which SAPI)
        @file_put_contents(
            $logFile,
            sprintf(
                "[%s] spawn backup=%s user=%s sapi=%s php=%s\n  cmd: %s\n",
                date('c'),
                $jobId,
                $userId,
                PHP_SAPI,
                $php,
                $cmd
            ),
            FILE_APPEND
        );

        // exec returns immediately because the command is backgrounded with `&`
        @exec($cmd);
    }

    /**
     * Resolve the PHP **CLI** binary path.
     *
     * This is intentionally careful: under PHP-FPM (and mod_php) PHP_BINARY points to
     * php-fpm / apache, NOT the CLI — running `php-fpm bin/console` just prints FPM usage.
     * We therefore build a candidate list and validate each one by running `-v` and
     * checking for the "(cli)" marker, so we never launch the FPM/CGI binary by mistake.
     *
     * Returns null if no working CLI binary can be found.
     */
    private function resolvePhpBinary(): ?string
    {
        $candidates = [];

        // 1. Explicit override (set CQ_PHP_BINARY=/usr/bin/php to force it)
        $envBinary = getenv('CQ_PHP_BINARY');
        if ($envBinary) {
            $candidates[] = $envBinary;
        }

        // 2. PATH-resolved CLI — matches what works in the user's shell (`php bin/console ...`)
        if (function_exists('exec')) {
            $out = [];
            $code = null;
            @exec('command -v php 2>/dev/null', $out, $code);
            if ($code === 0 && !empty($out[0])) {
                $candidates[] = trim($out[0]);
            }
        }

        // 3. PHP_BINARY only if it is a real CLI binary (not php-fpm / php-cgi)
        if (defined('PHP_BINARY') && PHP_BINARY) {
            $base = basename(PHP_BINARY);
            if (str_contains($base, 'php') && !str_contains($base, 'fpm') && !str_contains($base, 'cgi')) {
                $candidates[] = PHP_BINARY;
            }
        }

        // 4. Version-suffixed + common install locations
        if (defined('PHP_MAJOR_VERSION') && defined('PHP_MINOR_VERSION')) {
            $ver = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
            $candidates[] = '/usr/local/bin/php' . $ver;
            $candidates[] = '/usr/bin/php' . $ver;
        }
        if (defined('PHP_BINDIR') && PHP_BINDIR) {
            $candidates[] = PHP_BINDIR . '/php';
        }
        $candidates[] = '/usr/local/bin/php';
        $candidates[] = '/usr/bin/php';

        foreach ($candidates as $candidate) {
            if ($this->isCliPhpBinary($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Verify a binary is a usable PHP CLI by running `<bin> -v` and looking for "(cli)".
     */
    private function isCliPhpBinary(string $binary): bool
    {
        if ($binary === '' || !function_exists('exec')) {
            return false;
        }
        // Absolute/relative path must be executable; bare "php" relies on PATH
        if (str_contains($binary, '/') && !is_executable($binary)) {
            return false;
        }

        $out = [];
        $code = null;
        @exec(escapeshellarg($binary) . ' -v 2>&1', $out, $code);

        return $code === 0 && str_contains(implode(' ', $out), '(cli)');
    }
}
