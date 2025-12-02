<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Psr\Log\LoggerInterface;

#[Route('/administration/logs')]
#[IsGranted('ROLE_ADMIN')]
class AdminLogsController extends AbstractController
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    #[Route('', name: 'app_admin_logs')]
    public function index(): Response
    {
        $logDir = $this->getParameter('kernel.project_dir') . '/var/log';
        $logFiles = [];
        
        if (is_dir($logDir)) {
            $files = scandir($logDir);
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'log') {
                    $filePath = $logDir . '/' . $file;
                    $logFiles[] = [
                        'name' => $file,
                        'size' => filesize($filePath),
                        'modified' => filemtime($filePath),
                        'readable' => is_readable($filePath)
                    ];
                }
            }
            
            // Sort by modification time (newest first)
            usort($logFiles, function($a, $b) {
                return $b['modified'] - $a['modified'];
            });
        }

        return $this->render('admin/logs/index.html.twig', [
            'logFiles' => $logFiles
        ]);
    }

    #[Route('/download/{filename}', name: 'app_admin_logs_download')]
    public function download(string $filename): Response
    {
        // Security: Only allow .log files and prevent directory traversal
        if (!preg_match('/^[a-zA-Z0-9_.-]+\.log$/', $filename)) {
            throw $this->createNotFoundException('Invalid log file name');
        }

        $logDir = $this->getParameter('kernel.project_dir') . '/var/log';
        $filePath = $logDir . '/' . $filename;

        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw $this->createNotFoundException('Log file not found or not readable');
        }

        $this->logger->info('AdminLogsController::download - Log file downloaded', [
            'filename' => $filename,
            'admin_user' => $this->getUser()->getUsername(),
            'file_size' => filesize($filePath)
        ]);

        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename
        );

        return $response;
    }

    #[Route('/view/{filename}', name: 'app_admin_logs_view')]
    public function view(string $filename, Request $request): Response
    {
        // Security: Only allow .log files and prevent directory traversal
        if (!preg_match('/^[a-zA-Z0-9_.-]+\.log$/', $filename)) {
            throw $this->createNotFoundException('Invalid log file name');
        }

        $logDir = $this->getParameter('kernel.project_dir') . '/var/log';
        $filePath = $logDir . '/' . $filename;

        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw $this->createNotFoundException('Log file not found or not readable');
        }

        // Get pagination parameters
        $page = max(1, (int) $request->query->get('page', 1));
        $linesPerPage = 100;
        $search = $request->query->get('search', '');

        // Read file content
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $totalLines = count($lines);

        // Filter lines if search is provided
        if ($search) {
            $lines = array_filter($lines, function($line) use ($search) {
                return stripos($line, $search) !== false;
            });
            $lines = array_values($lines); // Re-index array
        }

        $filteredLines = count($lines);
        
        // Reverse array to show newest entries first
        $lines = array_reverse($lines);

        // Paginate
        $offset = ($page - 1) * $linesPerPage;
        $paginatedLines = array_slice($lines, $offset, $linesPerPage);

        $totalPages = ceil($filteredLines / $linesPerPage);

        // Parse JSON logs for better display
        $parsedLines = [];
        foreach ($paginatedLines as $index => $line) {
            $jsonData = json_decode($line, true);
            if ($jsonData) {
                $parsedLines[] = [
                    'raw' => $line,
                    'parsed' => $jsonData,
                    'line_number' => $filteredLines - $offset - $index
                ];
            } else {
                $parsedLines[] = [
                    'raw' => $line,
                    'parsed' => null,
                    'line_number' => $filteredLines - $offset - $index
                ];
            }
        }

        return $this->render('admin/logs/view.html.twig', [
            'filename' => $filename,
            'lines' => $parsedLines,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalLines' => $totalLines,
            'filteredLines' => $filteredLines,
            'search' => $search,
            'fileSize' => filesize($filePath),
            'lastModified' => filemtime($filePath)
        ]);
    }

    #[Route('/clear/{filename}', name: 'app_admin_logs_clear', methods: ['POST'])]
    public function clear(string $filename): Response
    {
        // Security: Only allow .log files and prevent directory traversal
        if (!preg_match('/^[a-zA-Z0-9_.-]+\.log$/', $filename)) {
            throw $this->createNotFoundException('Invalid log file name');
        }

        $logDir = $this->getParameter('kernel.project_dir') . '/var/log';
        $filePath = $logDir . '/' . $filename;

        if (!file_exists($filePath) || !is_writable($filePath)) {
            $this->addFlash('error', 'Log file not found or not writable');
            return $this->redirectToRoute('app_admin_logs');
        }

        // Create backup before clearing
        $backupPath = $filePath . '.backup.' . date('Y-m-d_H-i-s');
        copy($filePath, $backupPath);

        // Clear the log file
        file_put_contents($filePath, '');

        $this->logger->info('AdminLogsController::clear - Log file cleared', [
            'filename' => $filename,
            'admin_user' => $this->getUser()->getUsername(),
            'backup_created' => $backupPath
        ]);

        $this->addFlash('success', "Log file '{$filename}' has been cleared. Backup created.");
        return $this->redirectToRoute('app_admin_logs');
    }
}
