<?php

namespace App\Service;

use Symfony\Component\Process\Process;
use Psr\Log\LoggerInterface;

/**
 * Low-level service for running arbitrary shell commands.
 *
 * Uses Symfony Process with shell mode (Process::fromShellCommandline)
 * so commands support pipes, redirects, && chaining, etc.
 *
 * Safety/scoping responsibilities (cwd validation, project-bounding) are
 * handled by the caller (AIToolCommandService). This service simply runs
 * what it's given and returns structured results including exit code,
 * stdout and stderr separately. It never throws on non-zero exit codes —
 * callers inspect `success` / `exitCode`.
 */
class CommandService
{
    public const DEFAULT_TIMEOUT = 60;
    public const MAX_TIMEOUT = 300;
    public const MAX_OUTPUT_BYTES = 50000; // ~50KB

    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Run a shell command string in a given working directory.
     *
     * @param string        $command  Full shell command (may contain pipes, &&, etc.)
     * @param string        $cwd      Absolute working directory
     * @param int|null      $timeout  Seconds (clamped to [1, MAX_TIMEOUT], default DEFAULT_TIMEOUT)
     * @param array|null    $env      Additional env vars (merged with current environment)
     *
     * @return array{
     *     success: bool,
     *     exitCode: int,
     *     stdout: string,
     *     stderr: string,
     *     stdoutTruncated: bool,
     *     stderrTruncated: bool,
     *     durationMs: int,
     *     command: string,
     *     cwd: string,
     *     timedOut: bool,
     *     error?: string
     * }
     */
    public function runShell(
        string $command,
        string $cwd,
        ?int $timeout = null,
        ?array $env = null
    ): array {
        $timeout = $this->normalizeTimeout($timeout);

        $process = Process::fromShellCommandline($command, $cwd, $env);
        $process->setTimeout($timeout);

        $this->logger->info('Running shell command', [
            'command' => $command,
            'cwd' => $cwd,
            'timeout' => $timeout,
        ]);

        $start = microtime(true);
        $timedOut = false;
        $error = null;

        try {
            $process->run();
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
            $timedOut = true;
            $error = 'Command timed out after ' . $timeout . 's';
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $durationMs = (int) round((microtime(true) - $start) * 1000);

        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();

        $stdoutTruncated = false;
        $stderrTruncated = false;
        if (strlen($stdout) > self::MAX_OUTPUT_BYTES) {
            $stdout = substr($stdout, 0, self::MAX_OUTPUT_BYTES) . "\n\n[... output truncated, " . strlen($process->getOutput()) . " bytes total ...]";
            $stdoutTruncated = true;
        }
        if (strlen($stderr) > self::MAX_OUTPUT_BYTES) {
            $stderr = substr($stderr, 0, self::MAX_OUTPUT_BYTES) . "\n\n[... output truncated, " . strlen($process->getErrorOutput()) . " bytes total ...]";
            $stderrTruncated = true;
        }

        $exitCode = $process->getExitCode() ?? -1;
        $success = !$timedOut && $exitCode === 0 && $error === null;

        if (!$success) {
            $this->logger->warning('Shell command finished with issues', [
                'command' => $command,
                'cwd' => $cwd,
                'exitCode' => $exitCode,
                'timedOut' => $timedOut,
                'error' => $error,
            ]);
        }

        $result = [
            'success' => $success,
            'exitCode' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'stdoutTruncated' => $stdoutTruncated,
            'stderrTruncated' => $stderrTruncated,
            'durationMs' => $durationMs,
            'command' => $command,
            'cwd' => $cwd,
            'timedOut' => $timedOut,
        ];

        if ($error !== null) {
            $result['error'] = $error;
        }

        return $result;
    }

    private function normalizeTimeout(?int $timeout): int
    {
        if ($timeout === null) {
            return self::DEFAULT_TIMEOUT;
        }
        if ($timeout < 1) {
            return 1;
        }
        if ($timeout > self::MAX_TIMEOUT) {
            return self::MAX_TIMEOUT;
        }
        return $timeout;
    }
}
