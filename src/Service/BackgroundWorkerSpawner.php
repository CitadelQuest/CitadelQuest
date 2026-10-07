<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Spawns detached CLI workers (nohup ... &) that outlive the HTTP request which
 * started them, so long-running work never hits Cloudflare's 100s proxy read
 * timeout (HTTP 524). The browser polls the job status separately.
 *
 * Centralises the spawn + PHP-CLI-binary resolution logic that was previously
 * copy-pasted into each controller that starts a background worker.
 */
class BackgroundWorkerSpawner
{
    public function __construct(
        private readonly ParameterBagInterface $params
    ) {
    }

    /**
     * Spawn `bin/console <commandName> <args...>` detached, logging to var/log/<logName>.
     *
     * @param string[] $args
     */
    public function spawn(string $commandName, array $args, string $logName): void
    {
        // Ensure exec() is available (some hardened PHP setups disable it)
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (!function_exists('exec') || in_array('exec', $disabled, true)) {
            throw new \RuntimeException('PHP exec() is disabled; cannot spawn background worker.');
        }

        $projectDir = $this->params->get('kernel.project_dir');
        $env = $this->params->get('kernel.environment');
        $logFile = $projectDir . '/var/log/' . $logName;

        $php = $this->resolvePhpBinary();
        if ($php === null) {
            throw new \RuntimeException('Could not locate a PHP CLI binary to run the worker (php-fpm is not usable).');
        }

        $escapedArgs = implode(' ', array_map('escapeshellarg', $args));
        $cmd = sprintf(
            'nohup %s %s/bin/console %s %s --env=%s >> %s 2>&1 &',
            escapeshellarg($php),
            $projectDir,
            escapeshellarg($commandName),
            $escapedArgs,
            escapeshellarg($env),
            escapeshellarg($logFile)
        );

        // Debug trace so we can see exactly what was launched (and from which SAPI)
        @file_put_contents(
            $logFile,
            sprintf(
                "[%s] spawn %s args=[%s] sapi=%s php=%s\n  cmd: %s\n",
                date('c'),
                $commandName,
                implode(',', $args),
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
