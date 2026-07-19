<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Psr\Log\LoggerInterface;

class AIToolGitService
{
    public function __construct(
        private readonly GitService $gitService,
        private readonly ProjectFileService $projectFileService,
        private readonly SettingsService $settingsService,
        private readonly Security $security,
        private readonly ParameterBagInterface $params,
        private readonly LoggerInterface $logger
    ) {
    }

    public function gitOperation(array $arguments): array
    {
        $projectId = $arguments['projectId'] ?? 'general';
        $operation = $arguments['operation'] ?? null;
        $repoPathOverride = isset($arguments['localRepoPath'])
            ? $this->sanitizeRelativePath($arguments['localRepoPath'])
            : null;
        
        if (!$operation) {
            return [
                'success' => false,
                'error' => 'Missing required parameter: operation'
            ];
        }
        
        return match($operation) {
            'clone' => $this->handleClone($projectId, $arguments, $repoPathOverride),
            'pull' => $this->handlePull($projectId, $arguments, $repoPathOverride),
            'commitAndPush' => $this->handleCommitAndPush($projectId, $arguments, $repoPathOverride),
            'status' => $this->handleStatus($projectId, $arguments, $repoPathOverride),
            'diff' => $this->handleDiff($projectId, $arguments, $repoPathOverride),
            'log' => $this->handleLog($projectId, $arguments, $repoPathOverride),
            default => [
                'success' => false,
                'error' => "Unknown git operation: {$operation}"
            ]
        };
    }

    public function gitSetCredentials(array $arguments): array
    {
        $projectId = $arguments['projectId'] ?? 'general';
        $authMethod = $arguments['authMethod'] ?? null;
        
        if (!$authMethod || !in_array($authMethod, ['https', 'ssh'])) {
            return [
                'success' => false,
                'error' => 'Invalid or missing authMethod. Must be "https" or "ssh".'
            ];
        }
        
        $this->settingsService->setSetting("git.credentials.{$projectId}.auth_method", $authMethod);
        
        if ($authMethod === 'https') {
            $username = $arguments['username'] ?? null;
            $token = $arguments['token'] ?? null;
            
            if (!$username || !$token) {
                return [
                    'success' => false,
                    'error' => 'HTTPS authentication requires username and token'
                ];
            }
            
            $this->settingsService->setSetting("git.credentials.{$projectId}.username", $username);
            $this->settingsService->setSetting("git.credentials.{$projectId}.token", $token);
            
            $storedMethod = 'HTTPS (username + token)';
        } elseif ($authMethod === 'ssh') {
            $sshPrivateKey = $arguments['sshPrivateKey'] ?? null;
            
            if (!$sshPrivateKey) {
                return [
                    'success' => false,
                    'error' => 'SSH authentication requires sshPrivateKey'
                ];
            }
            
            $keyPath = $this->saveSshKey($sshPrivateKey, $projectId);
            $this->settingsService->setSetting("git.credentials.{$projectId}.ssh_key_path", $keyPath);
            
            $storedMethod = 'SSH (private key)';
        }
        
        if (isset($arguments['userName'])) {
            $this->settingsService->setSetting("git.credentials.{$projectId}.user_name", $arguments['userName']);
        }
        
        if (isset($arguments['userEmail'])) {
            $this->settingsService->setSetting("git.credentials.{$projectId}.user_email", $arguments['userEmail']);
        }
        
        if (isset($arguments['localRepoPath'])) {
            $repoPath = $this->sanitizeRelativePath($arguments['localRepoPath']);
            $this->settingsService->setSetting("git.credentials.{$projectId}.repo_path", $repoPath);
        }
        
        return [
            'success' => true,
            'message' => 'Git credentials stored successfully',
            'projectId' => $projectId,
            'authMethod' => $storedMethod,
            '_frontendData' => $this->buildCredentialsFrontendData($projectId, $authMethod)
        ];
    }

    private function handleClone(string $projectId, array $arguments, ?string $repoPathOverride = null): array
    {
        $repoUrl = $arguments['cloneRepoUrl'] ?? null;
        $branch = $arguments['branch'] ?? null;
        $depth = $arguments['cloneDepth'] ?? null;
        
        if (!$repoUrl) {
            return [
                'success' => false,
                'error' => 'Missing required parameter: cloneRepoUrl'
            ];
        }
        
        $projectDir = $this->getProjectDir($projectId, $repoPathOverride);
        
        if (!is_dir($projectDir)) {
            mkdir($projectDir, 0755, true);
        }
        
        if ($this->gitService->isGitRepo($projectDir)) {
            return [
                'success' => false,
                'error' => 'Project directory is already a git repository. Use gitOperation pull to update.'
            ];
        }
        
        $result = $this->gitService->clone($repoUrl, $projectDir, $projectId, $branch, $depth);
        
        if (!$result['success']) {
            return $result;
        }
        
        $syncResult = $this->syncRepositoryToDatabase($projectId, $projectDir, $repoPathOverride);
        
        $remoteUrl = $this->gitService->getRemoteUrl($projectDir);
        
        return [
            'success' => true,
            'message' => 'Repository cloned successfully',
            'projectId' => $projectId,
            'repoUrl' => $remoteUrl ?? $repoUrl,
            'branch' => $result['branch'],
            'filesRegistered' => $syncResult['filesRegistered'] ?? 0,
            'output' => $result['output'],
            '_frontendData' => $this->buildCloneFrontendData($projectId, $repoUrl, $syncResult)
        ];
    }

    private function handlePull(string $projectId, array $arguments, ?string $repoPathOverride = null): array
    {
        $remote = $arguments['pullRemote'] ?? null;
        $branch = $arguments['branch'] ?? null;
        
        $projectDir = $this->getProjectDir($projectId, $repoPathOverride);
        
        if (!$this->gitService->isGitRepo($projectDir)) {
            return [
                'success' => false,
                'error' => 'Project is not a git repository. Use gitOperation clone first.'
            ];
        }
        
        $result = $this->gitService->pull($projectDir, $projectId, $remote, $branch);
        
        if (!$result['success']) {
            return $result;
        }
        
        $changes = $result['changes'] ?? ['added' => [], 'modified' => [], 'deleted' => []];
        $this->syncChangesToDatabase($projectId, $projectDir, $changes, $repoPathOverride);
        
        $totalChanges = count($changes['added']) + count($changes['modified']) + count($changes['deleted']);
        
        return [
            'success' => true,
            'message' => 'Repository pulled successfully',
            'changes' => $changes,
            'totalChanges' => $totalChanges,
            'output' => $result['output'],
            '_frontendData' => $this->buildPullFrontendData($changes, $totalChanges)
        ];
    }

    private function handleCommitAndPush(string $projectId, array $arguments, ?string $repoPathOverride = null): array
    {
        $message = $arguments['commitMessage'] ?? null;
        $files = $arguments['commitFiles'] ?? 'all';
        $push = $arguments['commitAndPush'] ?? true;
        
        if (!$message) {
            return [
                'success' => false,
                'error' => 'Missing required parameter: commitMessage'
            ];
        }
        
        $projectDir = $this->getProjectDir($projectId, $repoPathOverride);
        
        if (!$this->gitService->isGitRepo($projectDir)) {
            return [
                'success' => false,
                'error' => 'Project is not a git repository. Use gitOperation clone first.'
            ];
        }
        
        $filesToAdd = $files === 'all' ? ['.'] : array_map('trim', explode(',', $files));
        
        $addResult = $this->gitService->add($projectDir, $filesToAdd);
        /* if (!$addResult['success']) {
            return $addResult;
        } */
        
        $commitResult = $this->gitService->commit($projectDir, $message, $projectId);
        if (!$commitResult['success']) {
            //return $commitResult;
            $commitResult['hash'] = $this->gitService->getLastCommitHash($projectDir);
        }
        
        $pushResult = null;
        if ($push) {
            $pushResult = $this->gitService->push($projectDir, $projectId);
            if (!$pushResult['success']) {
                return [
                    'success' => false,
                    'error' => 'Commit succeeded but push failed: ' . $pushResult['error'],
                    'commitHash' => $commitResult['hash']
                ];
            }
        }
        
        return [
            'success' => true,
            'message' => $push ? 'Committed and pushed successfully' : 'Committed successfully',
            'commitHash' => $commitResult['hash'],
            'pushed' => $push,
            '_frontendData' => $this->buildCommitFrontendData($commitResult['hash'], $message, $push)
        ];
    }

    private function handleStatus(string $projectId, array $arguments, ?string $repoPathOverride = null): array
    {
        $projectDir = $this->getProjectDir($projectId, $repoPathOverride);
        
        if (!$this->gitService->isGitRepo($projectDir)) {
            return [
                'success' => false,
                'error' => 'Project is not a git repository. Use gitOperation clone first.'
            ];
        }
        
        $result = $this->gitService->status($projectDir);
        
        if (!$result['success']) {
            return $result;
        }
        
        $totalChanges = count($result['modified']) + count($result['staged']) + 
                       count($result['untracked']) + count($result['deleted']);
        
        return [
            'success' => true,
            'branch' => $result['branch'],
            'modified' => $result['modified'],
            'staged' => $result['staged'],
            'untracked' => $result['untracked'],
            'deleted' => $result['deleted'],
            'totalChanges' => $totalChanges,
            '_frontendData' => $this->buildStatusFrontendData($result, $totalChanges)
        ];
    }

    private function handleDiff(string $projectId, array $arguments, ?string $repoPathOverride = null): array
    {
        $file = $arguments['diffFile'] ?? null;
        $staged = $arguments['diffStaged'] ?? false;
        
        $projectDir = $this->getProjectDir($projectId, $repoPathOverride);
        
        if (!$this->gitService->isGitRepo($projectDir)) {
            return [
                'success' => false,
                'error' => 'Project is not a git repository. Use gitOperation clone first.'
            ];
        }
        
        $result = $this->gitService->diff($projectDir, $file, $staged);
        
        if (!$result['success']) {
            return $result;
        }
        
        $diffLines = count(explode("\n", $result['diff']));
        
        return [
            'success' => true,
            'diff' => $result['diff'],
            'file' => $file ?? 'all changes',
            'staged' => $staged,
            'diffLines' => $diffLines,
            '_frontendData' => $this->buildDiffFrontendData($file, $staged, $diffLines, $result['diff'])
        ];
    }

    private function handleLog(string $projectId, array $arguments, ?string $repoPathOverride = null): array
    {
        $count = $arguments['logCount'] ?? 10;
        
        $projectDir = $this->getProjectDir($projectId, $repoPathOverride);
        
        if (!$this->gitService->isGitRepo($projectDir)) {
            return [
                'success' => false,
                'error' => 'Project is not a git repository. Use gitOperation clone first.'
            ];
        }
        
        $result = $this->gitService->log($projectDir, $count);
        
        if (!$result['success']) {
            return $result;
        }
        
        return [
            'success' => true,
            'commits' => $result['commits'],
            'count' => count($result['commits']),
            '_frontendData' => $this->buildLogFrontendData($result['commits'])
        ];
    }

    private function syncRepositoryToDatabase(string $projectId, string $repoDir, ?string $repoPathOverride = null): array
    {
        $filesRegistered = 0;
        $dbBase = '/' . $this->getRepoPath($projectId, $repoPathOverride);
        
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($repoDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            
            foreach ($iterator as $file) {
                $relativePath = str_replace($repoDir, $dbBase, $file->getPathname());
                
                if (str_contains($relativePath, '/.git/') || str_starts_with($relativePath, $dbBase . '/.git')) {
                    continue;
                }
                
                $pathParts = explode('/', trim($relativePath, '/'));
                $name = array_pop($pathParts);
                $path = '/' . implode('/', $pathParts);
                
                if ($file->isDir()) {
                    if (!$this->projectFileService->findByPathAndName($projectId, $path, $name)) {
                        $this->projectFileService->createDirectory($projectId, $path, $name);
                        $filesRegistered++;
                    }
                } else {
                    if (!$this->projectFileService->findByPathAndName($projectId, $path, $name)) {
                        $mimeType = mime_content_type($file->getPathname()) ?: 'application/octet-stream';
                        $this->projectFileService->registerExistingFile($projectId, $path, $name, $mimeType);
                        $filesRegistered++;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Git repository sync failed', [
                'projectId' => $projectId,
                'error' => $e->getMessage()
            ]);
        }
        
        return ['filesRegistered' => $filesRegistered];
    }

    private function syncChangesToDatabase(string $projectId, string $repoDir, array $changes, ?string $repoPathOverride = null): void
    {
        $dbBase = '/' . $this->getRepoPath($projectId, $repoPathOverride);
        
        foreach ($changes['added'] as $file) {
            $fullPath = $repoDir . '/' . $file;
            if (!file_exists($fullPath)) {
                continue;
            }
            
            $pathParts = explode('/', trim($file, '/'));
            $name = array_pop($pathParts);
            $path = rtrim($dbBase . '/' . implode('/', $pathParts), '/');
            
            $existing = $this->projectFileService->findByPathAndName($projectId, $path, $name);
            if (!$existing) {
                if (is_dir($fullPath)) {
                    $this->projectFileService->createDirectory($projectId, $path, $name);
                } else {
                    $mimeType = mime_content_type($fullPath) ?: 'application/octet-stream';
                    $this->projectFileService->registerExistingFile($projectId, $path, $name, $mimeType);
                }
            }
        }
        
        foreach ($changes['modified'] as $file) {
            $fullPath = $repoDir . '/' . $file;
            if (!file_exists($fullPath) || is_dir($fullPath)) {
                continue;
            }
            
            $pathParts = explode('/', trim($file, '/'));
            $name = array_pop($pathParts);
            $path = rtrim($dbBase . '/' . implode('/', $pathParts), '/');
            
            $existing = $this->projectFileService->findByPathAndName($projectId, $path, $name);
            if ($existing) {
                $this->projectFileService->syncFileSize($projectId, $path, $name);
            }
        }
        
        foreach ($changes['deleted'] as $file) {
            $pathParts = explode('/', trim($file, '/'));
            $name = array_pop($pathParts);
            $path = rtrim($dbBase . '/' . implode('/', $pathParts), '/');
            
            $existing = $this->projectFileService->findByPathAndName($projectId, $path, $name);
            if ($existing) {
                $this->projectFileService->delete($existing->getId());
            }
        }
    }

    private function saveSshKey(string $keyContent, string $projectId = "general"): string
    {
        $user = $this->security->getUser();
        if (!$user) {
            throw new \RuntimeException('User not authenticated');
        }
        
        $userDataDir = $this->params->get('kernel.project_dir') . '/var/user_data';
        $sshDir = $userDataDir . '/' . $user->getId() . '/p-' . $projectId . '-ssh';
        
        if (!is_dir($sshDir)) {
            mkdir($sshDir, 0700, true);
        }
        
        $keyPath = $sshDir . '/id_rsa';
        file_put_contents($keyPath, $keyContent);
        chmod($keyPath, 0600);
        
        return $keyPath;
    }

    private function getProjectDir(string $projectId, ?string $repoPathOverride = null): string
    {
        $user = $this->security->getUser();
        if (!$user) {
            throw new \RuntimeException('User not authenticated');
        }

        $repoPath = $this->getRepoPath($projectId, $repoPathOverride);
        $this->ensureDbDirectory($projectId, $repoPath);
        
        $userDataDir = $this->params->get('kernel.project_dir') . '/var/user_data';
        return $userDataDir . '/' . $user->getId() . '/p/' . $projectId . '/' . $repoPath;
    }

    /**
     * Resolve the configured local repo path for a project (relative to the project dir).
     * Defaults to "repo" to preserve backward compatibility.
     */
    private function getRepoPath(string $projectId, ?string $override = null): string
    {
        if ($override !== null) {
            return $override;
        }
        $repoPath = $this->settingsService->getSettingValue("git.credentials.{$projectId}.repo_path") ?? 'repo';
        return $this->sanitizeRelativePath($repoPath);
    }

    /**
     * Sanitize a user-supplied relative path: normalize slashes, strip empty,
     * "." and ".." segments (path-traversal safe). Falls back to "repo".
     */
    private function sanitizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $parts = array_filter(
            explode('/', $path),
            fn($p) => $p !== '' && $p !== '.' && $p !== '..'
        );
        $clean = implode('/', $parts);
        return $clean === '' ? 'repo' : $clean;
    }

    /**
     * Ensure every segment of a relative directory path is registered in the
     * project_file DB (source of truth for File Browser / AI file tools).
     */
    private function ensureDbDirectory(string $projectId, string $relativePath): void
    {
        $pathParts = explode('/', trim($relativePath, '/'));
        $currentPath = '/';
        foreach ($pathParts as $part) {
            if (!$this->projectFileService->findByPathAndName($projectId, $currentPath, $part)) {
                $this->projectFileService->createDirectory($projectId, $currentPath, $part);
            }
            $currentPath = rtrim($currentPath, '/') . '/' . $part;
        }
    }

    private function buildCloneFrontendData(string $projectId, string $repoUrl, array $syncResult): string
    {
        $filesCount = $syncResult['filesRegistered'] ?? 0;
        $displayUrl = htmlspecialchars($repoUrl);
        
        return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <div class="d-flex align-items-center">
        <i class="mdi mdi-source-repository text-cyber me-2"></i>
        <strong>Repository cloned</strong>
    </div>
    <div class="small text-muted mt-1">
        <div><i class="mdi mdi-link-variant me-1"></i>$displayUrl</div>
        <div><i class="mdi mdi-file-multiple me-1"></i>$filesCount files registered</div>
    </div>
</div>
HTML;
    }

    private function buildPullFrontendData(array $changes, int $totalChanges): string
    {
        $added = count($changes['added']);
        $modified = count($changes['modified']);
        $deleted = count($changes['deleted']);
        
        if ($totalChanges === 0) {
            return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <i class="mdi mdi-check-circle text-success me-1"></i>Already up to date
</div>
HTML;
        }
        
        return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <div class="d-flex align-items-center">
        <i class="mdi mdi-source-pull text-cyber me-2"></i>
        <strong>Repository updated</strong>
    </div>
    <div class="small text-muted mt-1">
        <span class="text-success"><i class="mdi mdi-plus-circle me-1"></i>$added added</span>
        <span class="text-warning ms-2"><i class="mdi mdi-pencil me-1"></i>$modified modified</span>
        <span class="text-danger ms-2"><i class="mdi mdi-delete me-1"></i>$deleted deleted</span>
    </div>
</div>
HTML;
    }

    private function buildCommitFrontendData(string $hash, string $message, bool $pushed): string
    {
        $shortHash = substr($hash, 0, 7);
        $displayMessage = htmlspecialchars($message);
        $pushStatus = $pushed ? '<i class="mdi mdi-cloud-upload text-success ms-2 me-1"></i>Pushed' : '';
        
        return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <div class="d-flex align-items-center">
        <i class="mdi mdi-source-commit text-cyber me-2"></i>
        <strong>$shortHash</strong>
        $pushStatus
    </div>
    <div class="small text-muted mt-1">$displayMessage</div>
</div>
HTML;
    }

    private function buildStatusFrontendData(array $status, int $totalChanges): string
    {
        $branch = htmlspecialchars($status['branch']);
        
        if ($totalChanges === 0) {
            return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <i class="mdi mdi-source-branch text-cyber me-1"></i><strong>$branch</strong>
    <span class="text-success ms-2"><i class="mdi mdi-check-circle me-1"></i>Working tree clean</span>
</div>
HTML;
        }
        
        $modified = count($status['modified']);
        $staged = count($status['staged']);
        $untracked = count($status['untracked']);
        $deleted = count($status['deleted']);
        
        $parts = [];
        if ($staged > 0) $parts[] = "<span class='text-success'><i class='mdi mdi-check me-1'></i>$staged staged</span>";
        if ($modified > 0) $parts[] = "<span class='text-warning'><i class='mdi mdi-pencil me-1'></i>$modified modified</span>";
        if ($untracked > 0) $parts[] = "<span class='text-info'><i class='mdi mdi-file-question me-1'></i>$untracked untracked</span>";
        if ($deleted > 0) $parts[] = "<span class='text-danger'><i class='mdi mdi-delete me-1'></i>$deleted deleted</span>";
        
        $statusDisplay = implode(' ', $parts);
        
        $sections = '';
        $sections .= $this->renderStatusSection('staged', $status['staged'], 'text-success', 'mdi-check');
        $sections .= $this->renderStatusSection('modified', $status['modified'], 'text-warning', 'mdi-pencil');
        $sections .= $this->renderStatusSection('untracked', $status['untracked'], 'text-info', 'mdi-file-question');
        $sections .= $this->renderStatusSection('deleted', $status['deleted'], 'text-danger', 'mdi-delete');
        
        return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <div><i class="mdi mdi-source-branch text-cyber me-1"></i><strong>$branch</strong></div>
    <div class="small mt-1">$statusDisplay</div>
    <div class="mt-2">$sections</div>
</div>
HTML;
    }

    /**
     * Render a status category as a collapsible list of filenames.
     * Returns empty string when the category has no files.
     */
    private function renderStatusSection(string $label, array $files, string $colorClass, string $icon): string
    {
        if (empty($files)) {
            return '';
        }
        
        $count = count($files);
        $items = '';
        foreach ($files as $f) {
            $name = htmlspecialchars((string) $f);
            $items .= "<div class='small text-muted'><i class='mdi mdi-file-outline me-1'></i><code>$name</code></div>";
        }
        
        $summary = "<span class='$colorClass'><i class='mdi $icon me-1'></i><strong>$label</strong> ($count)</span>";
        return $this->renderCollapsible($summary, $items, true);
    }

    private function buildDiffFrontendData(?string $file, bool $staged, int $diffLines, string $diff = ''): string
    {
        $fileDisplay = $file ? htmlspecialchars($file) : 'all changes';
        $stagedText = $staged ? ' (staged)' : '';
        
        $diffBody = $this->renderColorizedDiff($diff);
        $summary = "<i class='mdi mdi-file-compare me-1'></i><strong>diff</strong> <span class='text-muted'>($diffLines lines)</span>";
        $collapsible = $diff !== '' ? $this->renderCollapsible($summary, $diffBody, true) : '';
        
        return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <div class="d-flex align-items-center">
        <i class="mdi mdi-file-compare text-cyber me-2"></i>
        <strong>Diff: $fileDisplay</strong>$stagedText
    </div>
    <div class="small text-muted mt-1">$diffLines lines</div>
    <div class="mt-2">$collapsible</div>
</div>
HTML;
    }

    /**
     * Render a unified git diff with per-line colorization:
     * added (green), removed (red), hunk headers (cyan), file headers (muted).
     * Truncates very long diffs to keep the chat feed responsive.
     */
    private function renderColorizedDiff(string $diff): string
    {
        $maxChars = 8000;
        $truncated = false;
        if (mb_strlen($diff) > $maxChars) {
            $diff = mb_substr($diff, 0, $maxChars);
            $truncated = true;
        }
        
        $lines = preg_split("/\r\n|\n|\r/", $diff);
        $rendered = [];
        $fileCount = 0;
        foreach ($lines as $line) {
            if ($line === '') {
                $rendered[] = '';
                continue;
            }
            $first = $line[0];

            // "diff --git a/path b/path" → show just the filename in yellow,
            // with one empty line separating consecutive files.
            if (str_starts_with($line, 'diff --git')) {
                if (preg_match('#^diff --git a/.* b/(.+)$#', $line, $m)) {
                    $filename = $m[1];
                } else {
                    $filename = trim(substr($line, strlen('diff --git')));
                }
                if ($fileCount > 0) {
                    $rendered[] = '';
                }
                $fileCount++;
                $nameEsc = htmlspecialchars($filename);
                $rendered[] = "<span class='text-warning'><i class='mdi mdi-file-outline me-1'></i>$nameEsc</span>";
                continue;
            }

            // Drop the noisy git metadata lines — the yellow filename replaces them.
            if (str_starts_with($line, 'index ') || str_starts_with($line, '--- ') || str_starts_with($line, '+++ ')
                || str_starts_with($line, 'new file') || str_starts_with($line, 'deleted file')
                || str_starts_with($line, 'rename ') || str_starts_with($line, 'similarity ')
                || str_starts_with($line, 'old mode') || str_starts_with($line, 'new mode')) {
                continue;
            }

            $escaped = htmlspecialchars($line);
            if ($first === '@') {
                $color = 'text-info';
            } elseif ($first === '+') {
                $color = 'text-success';
            } elseif ($first === '-') {
                $color = 'text-danger';
            } else {
                $color = 'text-light';
            }
            $rendered[] = "<span class='$color'>$escaped</span>";
        }
        
        $body = implode("\n", $rendered);
        $note = $truncated
            ? '<div class="small text-muted mt-1"><i class="mdi mdi-dots-horizontal me-1"></i>diff truncated</div>'
            : '';
        
        return '<pre class="bg-black bg-opacity-50 rounded p-2 small mb-0" style="white-space: pre-wrap; word-break: break-word; max-height: 420px; overflow: auto; line-height: 1.4;">'
            . $body . '</pre>' . $note;
    }

    /**
     * Render a collapsible row: clickable summary + body that toggles on click.
     * Mirrors the AIToolFileService collapsible used by fileManage read/fileUpdate.
     * Pass $expanded = true to render the body open by default.
     */
    private function renderCollapsible(string $summaryHtml, string $bodyHtml, bool $expanded = false): string
    {
        $chevClass = $expanded ? 'mdi-chevron-down' : 'mdi-chevron-right';
        $bodyHidden = $expanded ? '' : 'd-none';
        return <<<HTML
<div class="cq-collapsible mt-1">
    <div class="small text-muted cursor-pointer d-flex align-items-center"
         onclick="this.querySelector('.cq-chev').classList.toggle('mdi-chevron-down');this.querySelector('.cq-chev').classList.toggle('mdi-chevron-right');this.nextElementSibling.classList.toggle('d-none');">
        <i class="mdi $chevClass cq-chev me-1"></i>
        <span>$summaryHtml</span>
    </div>
    <div class="$bodyHidden mt-1 ps-3">$bodyHtml</div>
</div>
HTML;
    }

    private function buildLogFrontendData(array $commits): string
    {
        $count = count($commits);
        
        if ($count === 0) {
            return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <i class="mdi mdi-history text-muted me-1"></i>No commits
</div>
HTML;
        }
        
        return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <div class="d-flex align-items-center">
        <i class="mdi mdi-history text-cyber me-2"></i>
        <strong>$count commits</strong>
    </div>
</div>
HTML;
    }

    private function buildCredentialsFrontendData(string $projectId, string $authMethod): string
    {
        $methodDisplay = $authMethod === 'https' ? 'HTTPS (username + token)' : 'SSH (private key)';
        $repoPath = htmlspecialchars($this->getRepoPath($projectId));
        
        return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <div class="d-flex align-items-center">
        <i class="mdi mdi-key text-cyber me-2"></i>
        <strong>Git credentials stored</strong>
    </div>
    <div class="small text-muted mt-1">Method: $methodDisplay</div>
    <div class="small text-muted"><i class="mdi mdi-folder-outline me-1"></i>Local repo path: <code>$repoPath</code></div>
</div>
HTML;
    }
}
