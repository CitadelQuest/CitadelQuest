<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Psr\Log\LoggerInterface;

/**
 * AI Tool: runCommand
 *
 * Lets Spirit AI run arbitrary shell commands inside a project directory.
 * Always project-scoped — cwd is validated to stay within the project root.
 *
 * Companion to AIToolGitService / AIToolFileService — completes the
 * CQ SW IDE toolchain (clone, edit, build, test, commit).
 */
class AIToolCommandService
{
    public function __construct(
        private readonly CommandService $commandService,
        private readonly ProjectFileService $projectFileService,
        private readonly Security $security,
        private readonly ParameterBagInterface $params,
        private readonly LoggerInterface $logger
    ) {
    }

    public function runCommand(array $arguments): array
    {
        $projectId = $arguments['projectId'] ?? 'general';
        $command   = $arguments['command']   ?? null;
        $cwdArg    = $arguments['cwd']       ?? '/';
        $timeout   = isset($arguments['timeout']) ? (int) $arguments['timeout'] : null;
        $syncFiles = array_key_exists('syncFiles', $arguments) ? (bool) $arguments['syncFiles'] : true;

        if (!$command || !is_string($command) || trim($command) === '') {
            return [
                'success' => false,
                'error' => 'Missing required parameter: command (non-empty string)'
            ];
        }

        try {
            $projectDir = $this->getProjectDir($projectId);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => 'Failed to resolve project directory: ' . $e->getMessage()
            ];
        }

        if (!is_dir($projectDir)) {
            @mkdir($projectDir, 0755, true);
        }

        // Resolve cwd relative to project root and validate it stays inside.
        $cwdResolved = $this->resolveCwd($projectDir, $cwdArg);
        if ($cwdResolved === null) {
            return [
                'success' => false,
                'error' => 'Invalid cwd: must be a path within the project directory'
            ];
        }

        $result = $this->commandService->runShell($command, $cwdResolved, $timeout);

        // Sync newly created/modified files into ProjectFileService DB
        $syncStats = ['registered' => 0, 'updated' => 0];
        if ($syncFiles && !$result['timedOut']) {
            try {
                $syncStats = $this->syncProjectDir($projectId, $projectDir);
            } catch (\Throwable $e) {
                $this->logger->error('runCommand file sync failed', [
                    'projectId' => $projectId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Display-friendly cwd (relative to project)
        $cwdDisplay = '/' . ltrim(str_replace($projectDir, '', $cwdResolved), '/');
        if ($cwdDisplay === '/') {
            $cwdDisplay = '/';
        }

        return [
            'success'         => $result['success'],
            'exitCode'        => $result['exitCode'],
            'stdout'          => $result['stdout'],
            'stderr'          => $result['stderr'],
            'stdoutTruncated' => $result['stdoutTruncated'],
            'stderrTruncated' => $result['stderrTruncated'],
            'durationMs'      => $result['durationMs'],
            'timedOut'        => $result['timedOut'],
            'command'         => $command,
            'cwd'             => $cwdDisplay,
            'projectId'       => $projectId,
            'filesRegistered' => $syncStats['registered'],
            'filesUpdated'    => $syncStats['updated'],
            'error'           => $result['error'] ?? null,
            '_frontendData'   => $this->buildFrontendData($command, $cwdDisplay, $result),
        ];
    }

    /**
     * Resolve and safely bound the cwd inside the project directory.
     * Returns absolute path or null if outside project root.
     */
    private function resolveCwd(string $projectDir, string $cwdArg): ?string
    {
        $cwdArg = trim($cwdArg);
        if ($cwdArg === '' || $cwdArg === '/') {
            return $projectDir;
        }

        // Strip leading slash — cwd is always relative to project root
        $relative = ltrim($cwdArg, '/');
        $candidate = $projectDir . '/' . $relative;

        // Ensure directory exists so realpath works; if not, still validate textually
        $real = is_dir($candidate) ? realpath($candidate) : null;
        $projectReal = realpath($projectDir) ?: $projectDir;

        if ($real !== null) {
            // Must be inside project root
            if (!str_starts_with($real . '/', rtrim($projectReal, '/') . '/')) {
                return null;
            }
            return $real;
        }

        // Fallback textual check — normalize . and .. and ensure no escape
        $parts = [];
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (empty($parts)) {
                    return null; // escapes project root
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }
        $normalized = $projectReal . (empty($parts) ? '' : '/' . implode('/', $parts));

        // If it still doesn't exist, use project root as cwd (command may be `mkdir ...`)
        return is_dir($normalized) ? $normalized : $projectReal;
    }

    /**
     * Walk project dir and register any unseen files/dirs in ProjectFileService.
     * Updates size for known files. Does not delete missing entries (safer).
     */
    private function syncProjectDir(string $projectId, string $projectDir): array
    {
        $registered = 0;
        $updated = 0;

        if (!is_dir($projectDir)) {
            return ['registered' => 0, 'updated' => 0];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($projectDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $relativePath = str_replace($projectDir, '', $file->getPathname());

            // Skip VCS and common noise directories
            if (preg_match('#(^|/)(\.git|node_modules|vendor/\.|\.cache)(/|$)#', $relativePath)) {
                continue;
            }

            $pathParts = explode('/', trim($relativePath, '/'));
            $name = array_pop($pathParts);
            $path = '/' . implode('/', $pathParts);
            if ($path === '') {
                $path = '/';
            }

            $existing = $this->projectFileService->findByPathAndName($projectId, $path, $name);

            if ($file->isDir()) {
                if (!$existing) {
                    $this->projectFileService->createDirectory($projectId, $path, $name);
                    $registered++;
                }
            } else {
                if (!$existing) {
                    $mimeType = @mime_content_type($file->getPathname()) ?: 'application/octet-stream';
                    $this->projectFileService->registerExistingFile($projectId, $path, $name, $mimeType);
                    $registered++;
                } else {
                    // Refresh size if method available
                    if (method_exists($this->projectFileService, 'syncFileSize')) {
                        $this->projectFileService->syncFileSize($projectId, $path, $name);
                        $updated++;
                    }
                }
            }
        }

        return ['registered' => $registered, 'updated' => $updated];
    }

    private function getProjectDir(string $projectId): string
    {
        $user = $this->security->getUser();
        if (!$user) {
            throw new \RuntimeException('User not authenticated');
        }

        $userDataDir = $this->params->get('kernel.project_dir') . '/var/user_data';
        return $userDataDir . '/' . $user->getId() . '/p/' . $projectId;
    }

    private function buildFrontendData(string $command, string $cwd, array $result): string
    {
        $displayCmd = htmlspecialchars(mb_strimwidth($command, 0, 100, '…'));
        $displayCwd = htmlspecialchars($cwd);
        $exitCode = $result['exitCode'];
        $duration = $result['durationMs'];

        if ($result['timedOut']) {
            $icon = 'mdi-timer-sand-empty';
            $color = 'text-warning';
            $status = 'Timed out';
        } elseif ($result['success']) {
            $icon = 'mdi-check-circle';
            $color = 'text-success';
            $status = 'Success';
        } else {
            $icon = 'mdi-alert-circle';
            $color = 'text-danger';
            $status = "Failed (exit {$exitCode})";
        }

        return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <div class="d-flex align-items-center">
        <i class="mdi mdi-console-line text-cyber me-2"></i>
        <strong>runCommand</strong>
        <span class="ms-2 $color"><i class="mdi $icon me-1"></i>$status</span>
    </div>
    <div class="small text-muted mt-1">
        <div><i class="mdi mdi-chevron-right me-1"></i><code>$displayCmd</code></div>
        <div><i class="mdi mdi-folder-outline me-1"></i>$displayCwd <span class="ms-2">{$duration}ms</span></div>
    </div>
</div>
HTML;
    }
}
