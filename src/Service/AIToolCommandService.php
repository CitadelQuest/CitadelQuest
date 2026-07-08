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

    private const READ_ONLY_COMMANDS = [
        'ls', 'cat', 'grep', 'egrep', 'fgrep', 'find', 'head', 'tail',
        'wc', 'file', 'stat', 'du', 'df', 'echo', 'printf',
        'which', 'whereis', 'type', 'pwd', 'whoami', 'id',
        'uname', 'hostname', 'date', 'env', 'printenv',
    ];

    public function runCommand(array $arguments): array
    {
        $projectId = $arguments['projectId'] ?? 'general';
        $command   = $arguments['command']   ?? null;
        $timeout   = isset($arguments['timeout']) ? (int) $arguments['timeout'] : null;

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

        // Security: scan command for absolute paths that escape the project root
        /*$securityError = $this->validateCommandPaths($command, $projectDir);
        if ($securityError !== null) {
            $this->logger->warning('runCommand blocked: absolute path escape attempt', [
                'projectId' => $projectId,
                'command' => $command,
                'reason' => $securityError,
            ]);
            return [
                'success' => false,
                'error' => 'Security blocked: ' . $securityError
            ];
        }*/

        // Always run in project root — no cwd parameter
        $cwdResolved = $projectDir;

        // Minimal env — strip Symfony/app secrets, keep only what shell tools need
        $minimalEnv = $this->buildMinimalEnv($projectDir);

        $result = $this->commandService->runShell($command, $cwdResolved, $timeout, $minimalEnv);

        // Auto-detect read-only commands to skip file sync
        $syncFiles = $this->isMutatingCommand($command) || $this->isAstrologOutputCommand($command);
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
            'cwd'             => '/',
            'projectId'       => $projectId,
            'filesRegistered' => $syncStats['registered'],
            'filesUpdated'    => $syncStats['updated'],
            'error'           => $result['error'] ?? null,
            '_frontendData'   => $this->buildFrontendData($command, '/', $result),
        ];
    }

    /**
     * Scan command for absolute paths and ".." traversal that escape project root.
     * Returns error message if a violation is found, null if safe.
     */
    private function validateCommandPaths(string $command, string $projectDir): ?string
    {
        $projectReal = realpath($projectDir) ?: $projectDir;

        // 1) Block ".." path segments — since cwd is project root, any ".." escapes.
        //    Matches: "cd ..", "cd ../..", "cat ../file", "ls ../../foo"
        //    Does NOT match: "..." (ellipsis), "file..txt", "--option" (no dots)
        if (preg_match('#(?:^|[\s=:\'"`(])\.\.(?:[/\s\'"`;|&)]|$)#', $command)) {
            return 'Path traversal ".." is not allowed (escapes project root)';
        }

        // 2) Block tilde expansion (~ resolves to user home, outside project)
        if (preg_match('#(?:^|[\s=:\'"`(])~(?:[/\s\'"`;|&)]|$)#', $command)) {
            return 'Tilde "~" expansion is not allowed (escapes project root)';
        }

        // 3) Match absolute paths: /foo/bar, /repo/file.txt, /var/www/...
        //    Excludes: flags like --path=/foo, env vars like $HOME/path
        //    Special-case: /dev/null, /dev/stdin, /dev/stdout, /dev/stderr (safe redirects)
        if (preg_match_all('#(?<![-\w/.])(/[^\s;|&<>`$(){}[\]\'"]+)#', $command, $matches)) {
            foreach ($matches[1] as $absPath) {
                // Allow common safe device paths used in shell redirects
                if (preg_match('#^/dev/(null|stdin|stdout|stderr|tty|zero|random|urandom)$#', $absPath)) {
                    continue;
                }

                // Resolve the path if it exists
                $real = file_exists($absPath) ? realpath($absPath) : null;
                $check = $real ?? $absPath;

                // Must start with project root
                if (!str_starts_with($check . '/', rtrim($projectReal, '/') . '/')) {
                    return 'Absolute path "' . $absPath . '" escapes the project directory';
                }
            }
        }

        return null;
    }

    /**
     * Determine whether a command is likely to mutate the filesystem.
     * Returns true for mutating commands (sync needed), false for read-only.
     */
    private function isMutatingCommand(string $command): bool
    {
        // Extract the base command name from the first segment
        // Handles: "ls -la", "cd repo && npm install", "git status"
        $segments = preg_split('/\s*\|\||\s*&&\s*|\s*;\s*|\s*\|\s*/', $command);
        $firstSegment = trim($segments[0]);

        // Get the first word (command name), stripping path prefixes like /usr/bin/ls
        $parts = explode(' ', trim($firstSegment));
        $cmdName = basename($parts[0]);

        return !in_array($cmdName, self::READ_ONLY_COMMANDS, true);
    }

    /**
     * Detect astrolog commands that write output files (images, text, etc.).
     * These are read-only according to the command name, but they create files
     * in the project directory that must be synced with ProjectFileService.
     */
    private function isAstrologOutputCommand(string $command): bool
    {
        if (stripos($command, 'astrolog') === false) {
            return false;
        }

        return preg_match('/\.(?:png|jpg|jpeg|gif|svg|bmp|as|dat|txt|md)\b/i', $command) === 1;
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

            // Skip image .icon/.thumbnail files
            if (preg_match('#\.(icon|thumbnail)$#i', $relativePath)) {
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

    /**
     * Build a minimal, isolated environment for the child process.
     *
     * Strips Symfony/app secrets (APP_SECRET, DATABASE_URL, OPENROUTER_API_KEY,
     * STRIPE_*, CQ_AI_GATEWAY_API_KEY, …) by allowlisting only the env vars
     * shell tools genuinely need. Symfony Process treats `false` values as
     * "unset this variable", so anything not in the allowlist is removed.
     *
     * HOME is pinned to the project directory so tool caches (composer, npm)
     * stay scoped to the project and visible in the File Browser.
     */
    private function buildMinimalEnv(string $projectDir): array
    {
        $allowed = [
            'PATH', 'LANG', 'LANGUAGE', 'LC_ALL', 'LC_CTYPE', 'LC_MESSAGES',
            'TZ', 'TERM', 'SHELL', 'USER', 'LOGNAME',
        ];

        $env = [];
        foreach (($_ENV + $_SERVER) as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }
            if (in_array($key, $allowed, true)) {
                $env[$key] = (string) $value;
            } else {
                // false → Symfony Process removes the variable from child env
                $env[$key] = false;
            }
        }

        // Pin HOME to project dir — keeps composer/npm caches project-scoped
        $env['HOME'] = $projectDir;

        // Ensure PATH always set (some shells choke without it)
        if (!isset($env['PATH']) || $env['PATH'] === false) {
            $env['PATH'] = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
        }

        return $env;
    }

    private function buildFrontendData(string $command, string $cwd, array $result): string
    {
        $displayCmd = htmlspecialchars(mb_strimwidth($command, 0, 200, '…'));
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

        $stdoutBlock = $this->buildOutputBlock(
            'stdout',
            $result['stdout'] ?? '',
            $result['stdoutTruncated'] ?? false,
            'mdi-text-box-outline',
            'text-info'
        );
        $stderrBlock = $this->buildOutputBlock(
            'stderr',
            $result['stderr'] ?? '',
            $result['stderrTruncated'] ?? false,
            'mdi-alert-outline',
            'text-warning'
        );

        return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <div class="d-flex align-items-center flex-wrap">
        <i class="mdi mdi-console-line text-cyber me-2"></i>
        <strong>runCommand</strong>
        <span class="ms-2 $color"><i class="mdi $icon me-1"></i>$status</span>
        <span class="ms-auto small text-muted">{$duration}ms</span>
    </div>
    <div class="small text-muted mt-1">
        <div><i class="mdi mdi-chevron-right me-1"></i><code>$displayCmd</code></div>
    </div>
    $stdoutBlock
    $stderrBlock
</div>
HTML;
    }

    /**
     * Build a compact preview block for stdout/stderr (first ~10 lines, max ~500 chars).
     * Returns empty string if there's nothing to show.
     */
    private function buildOutputBlock(string $label, string $content, bool $truncated, string $icon, string $colorClass): string
    {
        if ($content === '') {
            return '';
        }

        $maxChars = 5000;
        $maxLines = 100;
        $preview = $content;

        // Trim to max lines first
        $lines = preg_split("/\r\n|\n|\r/", $preview);
        $lineCutInfo = '';
        if (count($lines) > $maxLines) {
            $remaining = count($lines) - $maxLines;
            $preview = implode("\n", array_slice($lines, 0, $maxLines));
            $lineCutInfo = " (+{$remaining} more lines)";
        }

        // Then trim to max chars
        $charCutInfo = '';
        if (mb_strlen($preview) > $maxChars) {
            $preview = mb_substr($preview, 0, $maxChars);
            $charCutInfo = ' …';
        }

        $escaped = htmlspecialchars($preview);
        $truncatedNote = $truncated ? ' <span class="badge bg-secondary">truncated</span>' : '';
        $cutNote = ($lineCutInfo !== '' || $charCutInfo !== '')
            ? '<div class="small text-muted fst-italic">' . htmlspecialchars($lineCutInfo . $charCutInfo) . '</div>'
            : '';

        return <<<HTML
<div class="mt-2">
    <div class="small $colorClass mb-1"><i class="mdi $icon me-1"></i>$label$truncatedNote</div>
    <pre class="bg-black bg-opacity-50 rounded p-2 mb-0 small text-cyber" style="max-height:200px;overflow:auto;white-space:pre-wrap;word-break:break-word;">$escaped</pre>
    $cutNote
</div>
HTML;
    }
}
