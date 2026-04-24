<?php

namespace App\Service;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Psr\Log\LoggerInterface;

class GitService
{
    private const DEFAULT_TIMEOUT = 30;
    private const CLONE_TIMEOUT = 300;
    private const PULL_PUSH_TIMEOUT = 120;
    
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly SettingsService $settingsService
    ) {
    }

    public function clone(
        string $repoUrl,
        string $targetDir,
        ?string $projectId = null,
        ?string $branch = null,
        ?int $depth = null
    ): array {
        $this->validateUrl($repoUrl);
        
        $command = ['git', 'clone'];
        
        if ($branch) {
            $command[] = '--branch';
            $command[] = $branch;
        }
        
        if ($depth !== null && $depth > 0) {
            $command[] = '--depth';
            $command[] = (string)$depth;
        }
        
        $command[] = $repoUrl;
        $command[] = $targetDir;
        
        $env = $this->getCredentialEnv($projectId);
        
        try {
            $result = $this->runGitCommand($command, null, $env, self::CLONE_TIMEOUT);
            
            return [
                'success' => true,
                'output' => $result['output'],
                'targetDir' => $targetDir,
                'repoUrl' => $repoUrl,
                'branch' => $branch ?? 'default'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Clone failed: ' . $e->getMessage()
            ];
        }
    }

    public function pull(
        string $repoDir,
        ?string $projectId = null,
        ?string $remote = null,
        ?string $branch = null
    ): array {
        if (!$this->isGitRepo($repoDir)) {
            return [
                'success' => false,
                'error' => 'Not a git repository'
            ];
        }
        
        $command = ['git', 'pull', '--no-rebase', '--allow-unrelated-histories'];
        
        if ($remote) {
            $command[] = $remote;
        }
        
        if ($branch) {
            $command[] = $branch;
        }
        
        $env = $this->getCredentialEnv($projectId);
        
        try {
            $result = $this->runGitCommand($command, $repoDir, $env, self::PULL_PUSH_TIMEOUT);
            
            return [
                'success' => true,
                'output' => $result['output'],
                'changes' => $this->parseChangedFiles($repoDir)
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Pull failed: ' . $e->getMessage()
            ];
        }
    }

    public function status(string $repoDir): array
    {
        if (!$this->isGitRepo($repoDir)) {
            return [
                'success' => false,
                'error' => 'Not a git repository'
            ];
        }
        
        try {
            $result = $this->runGitCommand(['git', 'status', '--porcelain', '--branch'], $repoDir);
            $parsed = $this->parseStatus($result['output']);
            
            return [
                'success' => true,
                'branch' => $parsed['branch'],
                'modified' => $parsed['modified'],
                'staged' => $parsed['staged'],
                'untracked' => $parsed['untracked'],
                'deleted' => $parsed['deleted']
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Status failed: ' . $e->getMessage()
            ];
        }
    }

    public function diff(string $repoDir, ?string $file = null, bool $staged = false): array
    {
        if (!$this->isGitRepo($repoDir)) {
            return [
                'success' => false,
                'error' => 'Not a git repository'
            ];
        }
        
        $command = ['git', 'diff'];
        
        if ($staged) {
            $command[] = '--staged';
        }
        
        if ($file) {
            $command[] = $file;
        }
        
        try {
            $result = $this->runGitCommand($command, $repoDir);
            
            return [
                'success' => true,
                'diff' => $result['output']
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Diff failed: ' . $e->getMessage()
            ];
        }
    }

    public function log(string $repoDir, int $count = 10): array
    {
        if (!$this->isGitRepo($repoDir)) {
            return [
                'success' => false,
                'error' => 'Not a git repository'
            ];
        }
        
        $count = max(1, min(50, $count));
        
        $command = [
            'git', 'log',
            '-n', (string)$count,
            '--pretty=format:%H|%an|%ae|%ad|%s',
            '--date=iso'
        ];
        
        try {
            $result = $this->runGitCommand($command, $repoDir);
            $commits = $this->parseLog($result['output']);
            
            return [
                'success' => true,
                'commits' => $commits
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Log failed: ' . $e->getMessage()
            ];
        }
    }

    public function add(string $repoDir, array $files = ['.']): array
    {
        if (!$this->isGitRepo($repoDir)) {
            return [
                'success' => false,
                'error' => 'Not a git repository'
            ];
        }
        
        $command = array_merge(['git', 'add'], $files);
        
        try {
            $this->runGitCommand($command, $repoDir);
            
            return [
                'success' => true,
                'message' => 'Files staged successfully'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Add failed: ' . $e->getMessage()
            ];
        }
    }

    public function commit(string $repoDir, string $message, ?string $projectId = null): array
    {
        if (!$this->isGitRepo($repoDir)) {
            return [
                'success' => false,
                'error' => 'Not a git repository'
            ];
        }
        
        $this->ensureGitConfig($repoDir, $projectId);
        
        $command = ['git', 'commit', '-m', $message];
        
        try {
            $result = $this->runGitCommand($command, $repoDir);
            $hash = $this->getLastCommitHash($repoDir);
            
            return [
                'success' => true,
                'message' => 'Committed successfully',
                'hash' => $hash,
                'output' => $result['output']
            ];
        } catch (\Exception $e) {
            $error = $e->getMessage();
            if (str_contains($error, 'nothing to commit')) {
                return [
                    'success' => false,
                    'error' => 'Nothing to commit (working directory clean)'
                ];
            }
            
            return [
                'success' => false,
                'error' => 'Commit failed: ' . $error
            ];
        }
    }

    public function push(
        string $repoDir,
        ?string $projectId = null,
        ?string $remote = null,
        ?string $branch = null
    ): array {
        if (!$this->isGitRepo($repoDir)) {
            return [
                'success' => false,
                'error' => 'Not a git repository'
            ];
        }
        
        $command = ['git', 'push', '--set-upstream', 'origin', 'main'];
        
        if ($remote) {
            $command[] = $remote;
        }
        
        if ($branch) {
            $command[] = $branch;
        }
        
        $env = $this->getCredentialEnv($projectId);
        
        try {
            $result = $this->runGitCommand($command, $repoDir, $env, self::PULL_PUSH_TIMEOUT);
            
            return [
                'success' => true,
                'message' => 'Pushed successfully',
                'output' => $result['output']
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Push failed: ' . $e->getMessage()
            ];
        }
    }

    public function getRemoteUrl(string $repoDir): ?string
    {
        if (!$this->isGitRepo($repoDir)) {
            return null;
        }
        
        try {
            $result = $this->runGitCommand(['git', 'remote', 'get-url', 'origin'], $repoDir);
            return trim($result['output']);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function isGitRepo(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        
        try {
            $result = $this->runGitCommand(['git', 'rev-parse', '--git-dir'], $dir);
            return $result['success'];
        } catch (\Exception $e) {
            return false;
        }
    }

    private function runGitCommand(
        array $command,
        ?string $cwd = null,
        ?array $env = null,
        ?int $timeout = null
    ): array {
        $process = new Process($command, $cwd, $env);
        $process->setTimeout($timeout ?? self::DEFAULT_TIMEOUT);
        
        $this->logger->info('Running git command', [
            'command' => implode(' ', $command),
            'cwd' => $cwd
        ]);
        
        $process->run();
        
        if (!$process->isSuccessful()) {
            $error = $process->getErrorOutput() ?: $process->getOutput();
            $this->logger->error('Git command failed', [
                'command' => implode(' ', $command),
                'error' => $error
            ]);
            throw new \RuntimeException($error);
        }
        
        return [
            'success' => true,
            'output' => $process->getOutput()
        ];
    }

    private function getCredentialEnv(?string $projectId): array
    {
        if (!$projectId) {
            return [];
        }
        
        $authMethod = $this->settingsService->getSettingValue("git.credentials.{$projectId}.auth_method");
        
        if (!$authMethod) {
            return [];
        }
        
        $env = [];
        
        if ($authMethod === 'https') {
            $username = $this->settingsService->getSettingValue("git.credentials.{$projectId}.username");
            $token = $this->settingsService->getSettingValue("git.credentials.{$projectId}.token");
            
            if ($username && $token) {
                $askpassScript = $this->createAskpassScript($username, $token);
                $env['GIT_ASKPASS'] = $askpassScript;
                $env['GIT_TERMINAL_PROMPT'] = '0';
            }
        } elseif ($authMethod === 'ssh') {
            $sshKeyPath = $this->settingsService->getSettingValue("git.credentials.{$projectId}.ssh_key_path");
            
            if ($sshKeyPath && file_exists($sshKeyPath)) {
                $env['GIT_SSH_COMMAND'] = "ssh -i {$sshKeyPath} -o StrictHostKeyChecking=accept-new -o IdentitiesOnly=yes";
            }
        }
        
        return $env;
    }

    private function createAskpassScript(string $username, string $token): string
    {
        $script = sys_get_temp_dir() . '/git-askpass-' . uniqid() . '.sh';
        
        $content = <<<BASH
#!/bin/sh
case "\$1" in
    Username*) echo "{$username}" ;;
    Password*) echo "{$token}" ;;
esac
BASH;
        
        file_put_contents($script, $content);
        chmod($script, 0700);
        
        register_shutdown_function(function() use ($script) {
            if (file_exists($script)) {
                @unlink($script);
            }
        });
        
        return $script;
    }

    private function ensureGitConfig(string $repoDir, ?string $projectId): void
    {
        $userName = $this->settingsService->getSettingValue("git.credentials.{$projectId}.user_name")
            ?? $this->settingsService->getSettingValue('git.default.user_name')
            ?? 'CitadelQuest User';
            
        $userEmail = $this->settingsService->getSettingValue("git.credentials.{$projectId}.user_email")
            ?? $this->settingsService->getSettingValue('git.default.user_email')
            ?? 'user@citadelquest.local';
        
        $this->runGitCommand(['git', 'config', 'user.name', $userName], $repoDir);
        $this->runGitCommand(['git', 'config', 'user.email', $userEmail], $repoDir);
    }

    public function getLastCommitHash(string $repoDir): string
    {
        try {
            $result = $this->runGitCommand(['git', 'rev-parse', 'HEAD'], $repoDir);
            return trim($result['output']);
        } catch (\Exception $e) {
            return '';
        }
    }

    private function parseStatus(string $output): array
    {
        $lines = explode("\n", trim($output));
        
        $parsed = [
            'branch' => 'unknown',
            'modified' => [],
            'staged' => [],
            'untracked' => [],
            'deleted' => []
        ];
        
        foreach ($lines as $line) {
            if (str_starts_with($line, '##')) {
                preg_match('/## (.+?)(?:\.\.\.|\s|$)/', $line, $matches);
                $parsed['branch'] = $matches[1] ?? 'unknown';
                continue;
            }
            
            if (strlen($line) < 3) {
                continue;
            }
            
            $x = $line[0];
            $y = $line[1];
            $file = trim(substr($line, 3));
            
            if ($file === '') {
                continue;
            }
            
            if ($x === '?' && $y === '?') {
                $parsed['untracked'][] = $file;
            } elseif ($x === 'D' || $y === 'D') {
                $parsed['deleted'][] = $file;
            } elseif ($x !== ' ') {
                $parsed['staged'][] = $file;
            } elseif ($y !== ' ') {
                $parsed['modified'][] = $file;
            }
        }
        
        return $parsed;
    }

    private function parseLog(string $output): array
    {
        $lines = array_filter(explode("\n", trim($output)));
        $commits = [];
        
        foreach ($lines as $line) {
            $parts = explode('|', $line, 5);
            if (count($parts) === 5) {
                $commits[] = [
                    'hash' => $parts[0],
                    'author' => $parts[1],
                    'email' => $parts[2],
                    'date' => $parts[3],
                    'message' => $parts[4]
                ];
            }
        }
        
        return $commits;
    }

    private function parseChangedFiles(string $repoDir): array
    {
        try {
            $result = $this->runGitCommand(
                ['git', 'diff', '--name-status', 'HEAD@{1}', 'HEAD'],
                $repoDir
            );
            
            $lines = explode("\n", trim($result['output']));
            $changes = [
                'added' => [],
                'modified' => [],
                'deleted' => []
            ];
            
            foreach ($lines as $line) {
                if (empty($line)) {
                    continue;
                }
                
                $parts = preg_split('/\s+/', $line, 2);
                if (count($parts) !== 2) {
                    continue;
                }
                
                [$status, $file] = $parts;
                
                if ($status === 'A') {
                    $changes['added'][] = $file;
                } elseif ($status === 'M') {
                    $changes['modified'][] = $file;
                } elseif ($status === 'D') {
                    $changes['deleted'][] = $file;
                }
            }
            
            return $changes;
        } catch (\Exception $e) {
            return ['added' => [], 'modified' => [], 'deleted' => []];
        }
    }

    private function validateUrl(string $url): void
    {
        $parsed = parse_url($url);
        
        if (str_starts_with($url, 'git@')) {
            return;
        }
        
        if (!isset($parsed['scheme']) || !in_array($parsed['scheme'], ['https', 'http'])) {
            throw new \InvalidArgumentException('Invalid repository URL. Only HTTPS and SSH (git@) URLs are allowed.');
        }
        
        if (str_contains($url, '..') || str_contains($url, 'file://')) {
            throw new \InvalidArgumentException('Invalid repository URL. Path traversal or file:// URLs are not allowed.');
        }
    }
}
