<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Service for AI Tool file operations
 */
class AIToolFileService
{
    public function __construct(
        private readonly ProjectFileService $projectFileService,
        private readonly Security $security,
        private readonly AnnoService $annoService,
        private readonly CommandService $commandService
    ) {
    }

    /**
     * Update file content efficiently using find/replace operations
     * Token-efficient alternative to updateFile - only send changed parts
     * 
     * Supported update types:
     * - replace: Find and replace text
     * - lineRange: Replace specific line range
     * - append: Add content to end of file
     * - prepend: Add content to beginning of file
     * - insertAtLine: Insert content at specific line
     * 
     * Simplified format (one operation per call) for better LLM compatibility.
     * For multiple operations, call this tool multiple times.
     */
    public function fileUpdate(array $arguments): array
    {
        try {
            $arguments = $this->expandPathnames($arguments);
            $this->validateArguments($arguments, ['projectId', 'path', 'name', 'operation']);
            $this->validateSpiritAccess($arguments);

            $file = $this->projectFileService->findByPathAndName(
                $arguments['projectId'],
                $arguments['path'],
                $arguments['name']
            );
            
            if (!$file) {
                return [
                    'success' => false,
                    'error' => 'File not found',
                    '_frontendData' => $this->buildUpdateErrorFrontendData($arguments, 'File not found')
                ];
            }
            
            // Build single update operation from flat parameters
            $update = [
                'operation' => $arguments['operation']
            ];
            
            // Add operation-specific parameters
            if (isset($arguments['find'])) {
                $update['find'] = $arguments['find'];
            }
            if (isset($arguments['replaceWith'])) {
                $update['replace'] = $arguments['replaceWith'];
            } elseif (isset($arguments['content'])) {
                $update['replace'] = $arguments['content'];
            }
            if (isset($arguments['startLine'])) {
                $update['startLine'] = (int) $arguments['startLine'];
            }
            if (isset($arguments['endLine'])) {
                $update['endLine'] = (int) $arguments['endLine'];
            }
            if (isset($arguments['line'])) {
                $update['line'] = (int) $arguments['line'];
            }
            if (isset($arguments['content'])) {
                $update['content'] = $arguments['content'];
            }
            
            // Legacy support: if 'updates' array is provided, use it directly
            $updates = isset($arguments['updates']) && is_array($arguments['updates']) 
                ? $arguments['updates'] 
                : [$update];
            
            $updatedFile = $this->projectFileService->updateFileEfficient(
                $file->getId(),
                $updates
            );
            
            // Auto-run syntax checks for PHP/Twig/JS files so the Spirit immediately
            // knows if the edit broke the file — saves a runCommand round-trip.
            $syntaxChecks = $this->runFileSyntaxChecks($updatedFile);
            
            $result = [
                'success' => true,
                'file' => $updatedFile->jsonSerialize(),
                'operations_applied' => count($updates),
                '_frontendData' => $this->buildUpdateFrontendData($updatedFile, $updates, $syntaxChecks)
            ];
            
            if (!empty($syntaxChecks)) {
                $result['syntaxChecks'] = $syntaxChecks;
            }
            // Keep backward compatibility for Spirits/tools expecting the old key.
            if (isset($syntaxChecks['php'])) {
                $result['phpSyntaxCheck'] = $syntaxChecks['php'];
            }
            
            return $result;
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                '_frontendData' => $this->buildUpdateErrorFrontendData($arguments, $e->getMessage())
            ];
        }
    }
    
    /**
     * Run `php -l` (lint) on a freshly-updated file when it's a PHP source file.
     *
     * Returns null for non-PHP files (no check performed). For PHP files returns
     * a structured result so the AI Spirit knows whether the edit is syntactically
     * valid — eliminating the need for a separate runCommand `php -l` call.
     *
     * @return array{checked: bool, valid: bool, output: string}|null
     */
    private function runPhpSyntaxCheck($file): ?array
    {
        $extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
        if ($extension !== 'php') {
            return null;
        }

        $absolutePath = $this->projectFileService->getFileAbsolutePath($file->getId());
        if (!$absolutePath || !is_file($absolutePath)) {
            return null;
        }

        $command = 'php -l ' . escapeshellarg($absolutePath);
        $result = $this->commandService->runShell($command, dirname($absolutePath), 15);

        $output = trim(($result['stdout'] ?? '') . "\n" . ($result['stderr'] ?? ''));
        // Strip the absolute path from output to avoid leaking server filesystem layout.
        $output = str_replace($absolutePath, $file->getPath() . '/' . $file->getName(), $output);

        return [
            'checked' => true,
            'valid' => (bool) ($result['success'] ?? false),
            'output' => $output !== '' ? $output : 'No output',
        ];
    }

    /**
     * Run the appropriate syntax/validity checks for a freshly-updated file
     * based on its extension: PHP, Twig or JavaScript.
     *
     * @return array<string, array{checked: bool, valid: bool, output: string}>
     */
    private function runFileSyntaxChecks($file): array
    {
        $extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
        $checks = [];

        switch ($extension) {
            case 'php':
                $check = $this->runPhpSyntaxCheck($file);
                if ($check !== null) {
                    $checks['php'] = $check;
                }
                break;
            case 'twig':
                $check = $this->runTwigSyntaxCheck($file);
                if ($check !== null) {
                    $checks['twig'] = $check;
                }
                break;
        }

        return $checks;
    }

    /**
     * Run Symfony's lint:twig on a freshly-updated Twig template.
     *
     * @return array{checked: bool, valid: bool, output: string}|null
     */
    private function runTwigSyntaxCheck($file): ?array
    {
        $extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
        if ($extension !== 'twig') {
            return null;
        }

        $absolutePath = $this->projectFileService->getFileAbsolutePath($file->getId());
        if (!$absolutePath || !is_file($absolutePath)) {
            return null;
        }

        $projectDir = dirname(__DIR__, 2);
        $consolePath = $projectDir . '/bin/console';
        $command = 'php ' . escapeshellarg($consolePath) . ' lint:twig ' . escapeshellarg($absolutePath);
        $result = $this->commandService->runShell($command, $projectDir, 30);

        $output = trim(($result['stdout'] ?? '') . "\n" . ($result['stderr'] ?? ''));
        // Strip the absolute path from output to avoid leaking server filesystem layout.
        $output = str_replace($absolutePath, $file->getPath() . '/' . $file->getName(), $output);

        return [
            'checked' => true,
            'valid' => (bool) ($result['success'] ?? false),
            'output' => $output !== '' ? $output : 'No output',
        ];
    }

    /**
     * Search files by query string matching against path and name
     */
    public function fileSearch(array $arguments): array
    {
        $this->validateArguments($arguments, ['projectId', 'query']);
        
        try {
            $files = $this->projectFileService->searchFiles(
                $arguments['projectId'],
                $arguments['query']
            );
            
            if (empty($files)) {
                return [
                    'success' => true,
                    'files' => [],
                    'count' => 0,
                    'message' => 'No files found matching "' . $arguments['query'] . '"',
                    '_frontendData' => $this->buildSearchFrontendData($arguments['query'], [])
                ];
            }
            
            return [
                'success' => true,
                'files' => array_map(fn($f) => $f->jsonSerialize(), $files),
                'count' => count($files),
                '_frontendData' => $this->buildSearchFrontendData($arguments['query'], $files)
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Unified file management operations for AI tools
     * Supports: 
     * - File operations: create, read, copy, rename_move, delete
     * - Directory operations: createDirectory, list, tree
     * 
     * Simplified flat parameters for better LLM compatibility:
     * - sourcePath, sourceName: for copy, read, rename_move, delete, list, 
     * tree - disablet, too much content
     * - destPath, destName: for create, copy, rename_move, createDirectory
     * - content: for create operation
     * - withLineNumbers: for read operation
     */
    public function fileManage(array $arguments): array
    {
        try {
            $arguments = $this->expandPathnames($arguments);

            // Validate required parameters
            if (!isset($arguments['projectId']) || !isset($arguments['operation'])) {
                throw new \InvalidArgumentException('fileManage requires projectId and operation parameters');
            }
            
            $projectId = $arguments['projectId'];
            $operation = $arguments['operation'];
            
            // Get path/name from various parameter combinations
            $path = $arguments['path'] ?? $arguments['destPath'] ?? $arguments['sourcePath'] ?? '/';
            $name = $arguments['destName'] ?? $arguments['sourceName'] ?? '';

            // Handle consolidated directory and read operations
            switch ($operation) {
                case 'createDirectory':
                    return $this->handleCreateDirectory($projectId, $path, $name, $arguments);
                    
                case 'list':
                    return $this->handleListFiles($projectId, $path, $arguments);
                    
                case 'tree':
                   return $this->handleGetProjectTree($projectId, $arguments);
                    
                case 'read':
                    return $this->handleReadFile($projectId, $path, $name, $arguments);
                    
                case 'create':
                case 'copy':
                case 'rename_move':
                case 'delete':
                    return $this->handleFileOperations($projectId, $operation, $arguments);
                    
                default:
                    throw new \InvalidArgumentException('Invalid operation: ' . $operation . '. Supported: create, copy, rename_move, delete, createDirectory, list, read');
            }

        } catch (\Exception $e) {
            $opName = $arguments['operation'] ?? 'unknown';
            $sourceDisplay = isset($arguments['sourcePath']) && isset($arguments['sourceName'])
                ? rtrim($arguments['sourcePath'], '/') . '/' . $arguments['sourceName']
                : null;
            $destDisplay = isset($arguments['destPath']) && isset($arguments['destName'])
                ? rtrim($arguments['destPath'], '/') . '/' . $arguments['destName']
                : null;
            $target = $destDisplay ?? $sourceDisplay ?? '';
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'operation' => $opName,
                '_frontendData' => $this->buildManageErrorFrontendData($opName, $target, $e->getMessage())
            ];
        }
    }
    
    /**
     * Handle createDirectory operation
     */
    private function handleCreateDirectory(string $projectId, string $path, string $name, array $arguments): array
    {
        // Validate Spirit access
        $this->validateSpiritAccess(['path' => $path, '_spiritSlug' => $arguments['_spiritSlug'] ?? null]);
        
        try {
            $directory = $this->projectFileService->createDirectory($projectId, $path, $name);
            return [
                'success' => true,
                'operation' => 'createDirectory',
                'directory' => $directory->jsonSerialize(),
                '_frontendData' => $this->buildDirectoryFrontendData($path, $name)
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                '_frontendData' => $this->buildManageErrorFrontendData('createDirectory', rtrim($path, '/') . '/' . $name, $e->getMessage())
            ];
        }
    }
    
    /**
     * Handle list operation (list files in directory)
     */
    private function handleListFiles(string $projectId, string $path, array $arguments): array
    {
        // Validate Spirit access
        $this->validateSpiritAccess(['path' => $path, '_spiritSlug' => $arguments['_spiritSlug'] ?? null]);
        
        try {
            $files = $this->projectFileService->listFiles($projectId, $path);
            return [
                'success' => true,
                'operation' => 'list',
                'path' => $path,
                'files' => array_map(fn($file) => $file->jsonSerialize(), $files),
                '_frontendData' => $this->buildListFrontendData($path, $files)
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                '_frontendData' => $this->buildManageErrorFrontendData('list', $path, $e->getMessage())
            ];
        }
    }
    
    /**
     * Handle tree operation (get project tree)
     *
     * Returns a compact human-readable ASCII tree instead of raw JSON —
     * much smaller token footprint for the AI model to process.
     */
    private function handleGetProjectTree(string $projectId, array $arguments): array
    {
        try {
            $tree = $this->projectFileService->showProjectTree($projectId, true);

            // Respect the path parameter: filter to a subtree when path is provided
            $path = $arguments['path'] ?? $arguments['sourcePath'] ?? $arguments['destPath'] ?? '/';
            $path = '/' . ltrim($path, '/');
            $path = rtrim($path, '/');
            if ($path === '') {
                $path = '/';
            }

            if ($path !== '/') {
                $subtree = $this->findSubtreeNode($tree['children'] ?? [], $path);
                if ($subtree === null) {
                    return [
                        'success'       => false,
                        'error'         => "Path not found: {$path}",
                        '_frontendData' => $this->buildManageErrorFrontendData('tree', $path, "Path not found: {$path}"),
                    ];
                }
                // Treat the found directory as a new root for display
                $tree = [
                    'name'     => $path,
                    'path'     => '',
                    'type'     => 'projectRootDirectory',
                    'children' => $subtree['children'] ?? [],
                ];
            }

            $text = $this->formatAsciiTree($tree);
            $counts = $this->countTreeItems($tree);
            return [
                'success'       => true,
                'operation'     => 'tree',
                'path'          => $path,
                'tree'          => $text,
                'dirCount'      => $counts['dirs'],
                'fileCount'     => $counts['files'],
                '_frontendData' => $this->buildTreeFrontendData($projectId, $text, $counts),
            ];
        } catch (\Exception $e) {
            return [
                'success'       => false,
                'error'         => $e->getMessage(),
                '_frontendData' => $this->buildManageErrorFrontendData('tree', $projectId, $e->getMessage()),
            ];
        }
    }

    /**
     * Recursively find a directory node by its full path in the tree.
     */
    private function findSubtreeNode(array $children, string $targetPath): ?array
    {
        foreach ($children as $child) {
            $childPath = ($child['path'] ?? '') === '/'
                ? '/' . $child['name']
                : rtrim($child['path'] ?? '', '/') . '/' . $child['name'];
            $childPath = str_replace('//', '/', $childPath);

            if ($childPath === $targetPath && ($child['type'] ?? '') === 'directory') {
                return $child;
            }

            if (($child['type'] ?? '') === 'directory' && !empty($child['children'])) {
                $result = $this->findSubtreeNode($child['children'], $targetPath);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
    }

    /**
     * Recursively format a project tree node as a compact ASCII tree string.
     *
     * Directory-only children are grouped onto single lines (up to ~4 per line)
     * to keep the output short. Files show name + human-readable size.
     *
     * @param int $depth 0 = root, 1 = root's children, 2+ = compact eligible
     */
    private function formatAsciiTree(array $node, string $prefix = '', bool $isLast = true, bool $isRoot = true, int $depth = 0): string
    {
        $lines = [];

        if ($isRoot) {
            $lines[] = $node['name'];// . ' (project root)';
        } else {
            $connector = $isLast ? '└── ' : '├── ';
            $display   = $node['type'] === 'directory' ? $node['name'] . '/' : $node['name'];
            if (($node['size'] ?? 0) > 0) {
                $display .= ' (' . $this->formatSize($node['size']) . ')';
            }
            $lines[] = $prefix . $connector . $display;
        }

        $children = $node['children'] ?? [];
        if (empty($children)) {
            return implode("\n", $lines);
        }

        // Separate directories and files
        $dirs  = [];
        $files = [];
        foreach ($children as $child) {
            if (($child['type'] ?? '') === 'directory') {
                $dirs[] = $child;
            } else {
                $files[] = $child;
            }
        }

        $childPrefix = $isRoot ? '' : ($prefix . ($isLast ? '    ' : '│   '));
        $nextDepth   = $depth + 1;

        // Depth ≥ 2: compact directory groups. Top levels always expand for readability.
        if ($depth >= 2) {
            $groupSize = 4;
            $dirGroups = array_chunk($dirs, $groupSize);
            $totalGroups = count($dirGroups);
            $totalFileItems = count($files);

            foreach ($dirGroups as $gi => $group) {
                $isLastGroup = ($gi === $totalGroups - 1) && $totalFileItems === 0;

                if (count($group) === 1) {
                    $lines[] = $this->formatAsciiTree($group[0], $childPrefix, $isLastGroup, false, $nextDepth);
                } else {
                    $connector = $isLastGroup ? '└── ' : '├── ';
                    $names = array_map(fn($d) => $d['name'] . '/', $group);
                    $lines[] = $childPrefix . $connector . implode(', ', $names);
                }
            }

            foreach ($files as $fi => $file) {
                $isLastFile = ($fi === $totalFileItems - 1);
                $connector = $isLastFile ? '└── ' : '├── ';
                $display = $file['name'];
                if (($file['size'] ?? 0) > 0) {
                    $display .= ' (' . $this->formatSize($file['size']) . ')';
                }
                $lines[] = $childPrefix . $connector . $display;
            }
        } else {
            // Depth 0–1: expand everything individually
            $allChildren = array_merge($dirs, $files);
            $totalChildren = count($allChildren);

            foreach ($allChildren as $ci => $child) {
                $isLastChild = ($ci === $totalChildren - 1);
                $lines[] = $this->formatAsciiTree($child, $childPrefix, $isLastChild, false, $nextDepth);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Format a byte size into a human-readable string.
     */
    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1_000_000) {
            return round($bytes / 1_000_000, 1) . 'MB';
        }
        if ($bytes >= 1_000) {
            return round($bytes / 1_000, 1) . 'KB';
        }
        return $bytes . 'B';
    }

    /**
     * Recursively count directories and files in a tree node.
     *
     * @return array{dirs: int, files: int}
     */
    private function countTreeItems(array $node): array
    {
        $dirs  = 0;
        $files = 0;

        foreach ($node['children'] ?? [] as $child) {
            if (($child['type'] ?? '') === 'directory') {
                $dirs++;
                $sub = $this->countTreeItems($child);
                $dirs  += $sub['dirs'];
                $files += $sub['files'];
            } else {
                $files++;
            }
        }

        return ['dirs' => $dirs, 'files' => $files];
    }

    /**
     * Build frontend HTML card for the tree operation.
     */
    private function buildTreeFrontendData(string $projectId, string $text, array $counts): string
    {
        $projectEsc = htmlspecialchars($projectId);
        $dirCount   = $counts['dirs'];
        $fileCount  = $counts['files'];
        $treeEsc    = htmlspecialchars($text);

        return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <div class="d-flex align-items-center">
        <i class="mdi mdi-file-tree text-cyber me-2"></i>
        <strong>tree</strong>
        <span class="ms-2 text-muted">$dirCount dirs, $fileCount files</span>
    </div>
    <div class="small text-muted mt-1"><i class="mdi mdi-folder-outline me-1"></i><code>$projectEsc</code></div>
    <pre class="small text-cyber bg-dark bg-opacity-75 rounded p-2 mt-2 mb-0" style="max-height:300px; overflow-y:auto; line-height:1.4;">$treeEsc</pre>
</div>
HTML;
    }

    /**
     * Handle read operation (get file content)
     */
    private function handleReadFile(string $projectId, string $path, string $name, array $arguments): array
    {
        // Validate Spirit access
        $this->validateSpiritAccess(['path' => $path, '_spiritSlug' => $arguments['_spiritSlug'] ?? null]);
        
        try {
            $file = $this->projectFileService->findByPathAndName($projectId, $path, $name);
            
            if (!$file && isset($arguments['fileId'])) {
                $file = $this->projectFileService->findById($arguments['fileId']);
            }
            
            $fileDisplay = rtrim($path, '/') . '/' . $name;
            if (!$file) {
                return [
                    'success' => false,
                    'error' => 'File not found',
                    '_frontendData' => $this->buildManageErrorFrontendData('read', $fileDisplay, 'File not found')
                ];
            }
            
            if ($file->isDirectory()) {
                return [
                    'success' => false,
                    'error' => 'Cannot get content of a directory',
                    '_frontendData' => $this->buildManageErrorFrontendData('read', $fileDisplay, 'Cannot get content of a directory')
                ];
            }
            
            $withLineNumbers = $arguments['withLineNumbers'] ?? false;
            $filename = $file->getName();
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            // For PDF files, try to use cached annotations
            $usedAnnotations = false;
            $content = '';
            if ($extension === 'pdf') {
                $annoData = $this->annoService->readAnnotation(AnnoService::TYPE_PDF, $filename, $projectId, false);
                if ($annoData && $this->annoService->verifyPdfAnnotation($annoData, $filename)) {
                    $content = $this->annoService->getTextContent($annoData);
                    $usedAnnotations = true;
                }
            }
            
            // If no annotations used, get raw content
            if (!$usedAnnotations) {
                $content = $this->projectFileService->getFileContent($file->getId(), $withLineNumbers);
            }
            
            // Build frontend display HTML (same logic as original getFileContent)
            $contentFrontendData = $this->buildContentFrontendData($file, $content, $usedAnnotations, $projectId);
            
            // Handle binary data display
            if (!$usedAnnotations && strpos($content, 'data:') === 0) {
                $content = "binary data, not displayed";
                if (strpos($file->getMimeType() ?? '', 'image/') === 0 || 
                    strpos($file->getMimeType() ?? '', 'video/') === 0 || 
                    strpos($file->getMimeType() ?? '', 'audio/') === 0) {
                    $content = 'binary data, displayed directly in frontend';
                }
            }
            
            return [
                'success' => true,
                'operation' => 'read',
                'content' => $content,
                'file' => $file->jsonSerialize(),
                'with_line_numbers' => $withLineNumbers,
                '_frontendData' => $contentFrontendData
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                '_frontendData' => $this->buildManageErrorFrontendData('read', rtrim($path, '/') . '/' . $name, $e->getMessage())
            ];
        }
    }
    
    /**
     * Build frontend display data for file content
     */
    private function buildContentFrontendData($file, string $content, bool $usedAnnotations, string $projectId): string
    {
        $headerName = htmlspecialchars($file->getName());
        $headerPath = htmlspecialchars($file->getPath() == '/' ? '' : $file->getPath());
        $mimeType = htmlspecialchars($file->getMimeType() ?? 'application/octet-stream');
        $size = is_string($content) ? mb_strlen($content) : 0;
        $editLink = $this->buildFileEditLink($file);

        $header = <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2 mb-0">
    <div class="d-flex align-items-center">
        <i class="mdi mdi-file-document-outline text-cyber me-2"></i>
        <strong>File read</strong>
        <span class="ms-2 text-muted">$size chars</span>
    </div>
    <div class="small text-muted mt-1"><i class="mdi mdi-file-outline me-1"></i><code>$headerPath/$headerName</code>$editLink</div>
    <div class="d-none small text-muted"><i class="mdi mdi-tag-outline me-1"></i>$mimeType</div>
</div>
HTML;

        // For text files, do not embed the raw content in the chat UI.
        // The full file is now available in the much nicer CodeMirror edit modal.
        // Media (image/video/audio) and PDF-with-annotations keep their
        // existing bespoke display below.
        $contentFrontendData = $header;

        // Mermaid diagram source file
        if ($this->isMermaidContent($file, $content)) {
            $escapedContent = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
            $contentFrontendData .= <<<HTML
<div class="mermaid bg-dark bg-opacity-25 rounded p-2 my-2 overflow-auto" data-mermaid-source="1">$escapedContent</div>
HTML;
        }

        // Image data
        if (strpos($file->getMimeType() ?? '', 'image/') === 0) {
            $fileId = $file->getId();
            $fileName = htmlspecialchars($file->getName());
            $filePath = htmlspecialchars($file->getPath() == '/' ? '' : $file->getPath());
            $displayProjectId = htmlspecialchars($projectId);
            $downloadUrl = '/api/project-file/' . $fileId . '/download?download=1';
            $viewUrl = '/api/project-file/' . $fileId . '/download';

            // Embed SVG inline so it renders reliably (data URI in <img> is flaky for SVG).
            $isSvg = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION)) === 'svg';
            $previewBody = $isSvg
                ? '<div class="svg-preview rounded shadow" style="max-width: 100%; height: auto; max-height: 75vh;">' . $this->sanitizeSvgContent($this->projectFileService->getFileContent($file->getId(), false)) . '</div>'
                : '<img src="' . $viewUrl . '" alt="' . $fileName . '" style="max-width: 100%; height: auto; max-height: 75vh;" class="rounded shadow"/>';

            $contentFrontendData = <<<HTML
<div class="content-showcase position-relative d-inline-block" data-title="$fileName" data-type="image">
    $previewBody
    <div class="content-showcase-icon position-absolute top-0 end-0 p-1 badge bg-dark bg-opacity-75 text-cyber cursor-pointer">
        <i class="mdi mdi-fullscreen"></i>
    </div>
</div>
    <div class="d-flex align-items-center justify-content-between mt-1 w-100">
        <div class="small text-muted text-truncate me-2">
            <span class="badge bg-dark bg-opacity-50 me-1">$displayProjectId</span>
            <i class="mdi mdi-file-outline me-1"></i><code>$filePath/$fileName</code>
        </div>
        <a href="$downloadUrl" class="btn btn-link btn-sm p-0 text-cyber opacity-50 flex-shrink-0" title="Download" download>
            <i class="mdi mdi-download"></i>
        </a>
    </div>
HTML;
        }
        // Video data
        elseif (strpos($file->getMimeType() ?? '', 'video/') === 0) {
            $contentFrontendData = '<video src="/api/project-file/' . $file->getId() . '/download" controls style="max-width: 100%;" class="rounded shadow"></video>';
        }
        // Audio data
        elseif (strpos($file->getMimeType() ?? '', 'audio/') === 0) {
            $contentFrontendData = '<audio src="/api/project-file/' . $file->getId() . '/download" controls style="width: 100%;" class="rounded shadow"></audio>';
        }
        // PDF with annotations
        elseif ($usedAnnotations) {
            $contentFrontendData = '<div class="chat-file-preview rounded text-cyber bg-dark bg-opacity-25 cursor-pointer mb-0"
                            onclick="this.querySelector(\'.embed-container\').classList.toggle(\'d-none\');">
                        <div class="d-flex align-items-center px-1">
                            <i class="mdi mdi-file-pdf-box me-1 text-danger opacity-50" style="font-size: 1.6rem; padding: 0 0.3rem !important;"></i>
                            <span class="text-cyber">' . htmlspecialchars($file->getName()) . '</span>
                        </div>
                        <div class="p-2 pt-0 d-none embed-container">
                            <embed src="/api/project-file/' . $file->getId() . '/download" loading="lazy"
                                width="100%" height="420"
                                class="rounded"
                                type="application/pdf"
                                title="' . htmlspecialchars($file->getName()) . '" />
                        </div>
                    </div>';
        }
        
        return $contentFrontendData;
    }

    /**
     * Minimal SVG sanitization for inline display in chat.
     * Removes script tags and inline event handlers to avoid XSS.
     */
    private function sanitizeSvgContent(string $svg): string
    {
        $svg = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $svg);
        $svg = preg_replace('/on\w+\s*=\s*["\'][^"\']*["\']/i', '', $svg);
        return $svg;
    }
    
    /**
     * Detect whether a file's content is a Mermaid diagram.
     * Recognized by file extension (.mmd / .mermaid) or by common Mermaid keywords at the start.
     */
    private function isMermaidContent($file, string $content): bool
    {
        $name = strtolower($file->getName());
        if (str_ends_with($name, '.mmd') || str_ends_with($name, '.mermaid')) {
            return true;
        }

        $trimmed = ltrim($content);
        if ($trimmed === '') {
            return false;
        }

        $lower = strtolower(substr($trimmed, 0, 200));
        $keywords = [
            'graph ', "graph\n", "graph\r", "graph\t",
            'flowchart ', "flowchart\n", "flowchart\r", "flowchart\t",
            'sequenceDiagram', 'classDiagram', 'stateDiagram', 'erDiagram',
            'gantt', 'pie ', "pie\n", 'journey', 'gitGraph', 'mindmap', 'timeline',
            'requirementDiagram', 'sankey', 'xychart-beta', 'block-beta',
            'packet-beta', 'kanban',
        ];

        foreach ($keywords as $keyword) {
            if (str_starts_with($lower, strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build frontend data for fileSearch results
     */
    private function buildSearchFrontendData(string $query, array $files): string
    {
        $displayQuery = htmlspecialchars($query);
        $count = count($files);

        if ($count === 0) {
            return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <div class="d-flex align-items-center">
        <i class="mdi mdi-file-search text-cyber me-2"></i>
        <strong>fileSearch</strong>
        <span class="ms-2 text-muted"><i class="mdi mdi-information-outline me-1"></i>No matches</span>
    </div>
    <div class="small text-muted mt-1"><i class="mdi mdi-magnify me-1"></i><code>$displayQuery</code></div>
</div>
HTML;
        }

        $items = '';
        $shown = array_slice($files, 0, 8);
        foreach ($shown as $f) {
            $name = htmlspecialchars($f->getName());
            $path = htmlspecialchars($f->getPath());
            $icon = $f->isDirectory() ? 'mdi-folder-outline' : 'mdi-file-outline';
            $items .= "<div class='small text-muted'><i class='mdi $icon me-1'></i><code>$path/$name</code></div>";
        }
        $more = $count > 8 ? '<div class="small text-muted mt-1">… and ' . ($count - 8) . ' more</div>' : '';

        return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <div class="d-flex align-items-center">
        <i class="mdi mdi-file-search text-cyber me-2"></i>
        <strong>fileSearch</strong>
        <span class="ms-2 text-success"><i class="mdi mdi-check-circle me-1"></i>$count match(es)</span>
    </div>
    <div class="small text-muted mt-1"><i class="mdi mdi-magnify me-1"></i><code>$displayQuery</code></div>
    <div class="mt-2">$items$more</div>
</div>
HTML;
    }

    /**
     * Build frontend data for fileUpdate results
     */
    private function buildUpdateFrontendData($file, array $updates, array $syntaxChecks = []): string
    {
        $name = htmlspecialchars($file->getName());
        $path = htmlspecialchars($file->getPath());
        $count = count($updates);

        $details = [];
        foreach ($updates as $u) {
            $op = $u['operation'] ?? 'unknown';
            switch ($op) {
                case 'replace':
                    $findLen = isset($u['find']) ? mb_strlen((string) $u['find']) : 0;
                    $replLen = isset($u['replace']) ? mb_strlen((string) $u['replace']) : 0;
                    $summary = "<i class='mdi mdi-find-replace me-1'></i><strong>replace</strong> <span class='text-warning'>-{$findLen}</span> <span class='text-success'>+{$replLen}</span> chars";
                    $body = $this->renderDiffBlock(
                        $u['find'] ?? '',
                        $u['replace'] ?? ''
                    );
                    $details[] = $this->renderCollapsible($summary, $body);
                    break;
                case 'lineRange':
                    $sl = $u['startLine'] ?? '?';
                    $el = $u['endLine'] ?? '?';
                    $newLen = isset($u['content']) ? mb_strlen((string) $u['content']) : 0;
                    $summary = "<i class='mdi mdi-format-line-spacing me-1'></i><strong>lineRange</strong> lines <span class='text-cyber'>{$sl}–{$el}</span> <span class='text-success'>+{$newLen}</span> chars";
                    $body = $this->renderContentBlock($u['content'] ?? '', 'success', 'mdi-plus-box');
                    $details[] = $this->renderCollapsible($summary, $body);
                    break;
                case 'append':
                    $len = isset($u['content']) ? mb_strlen((string) $u['content']) : 0;
                    $summary = "<i class='mdi mdi-arrow-down-bold me-1'></i><strong>append</strong> <span class='text-success'>+{$len}</span> chars";
                    $body = $this->renderContentBlock($u['content'] ?? '', 'success', 'mdi-plus-box');
                    $details[] = $this->renderCollapsible($summary, $body);
                    break;
                case 'prepend':
                    $len = isset($u['content']) ? mb_strlen((string) $u['content']) : 0;
                    $summary = "<i class='mdi mdi-arrow-up-bold me-1'></i><strong>prepend</strong> <span class='text-success'>+{$len}</span> chars";
                    $body = $this->renderContentBlock($u['content'] ?? '', 'success', 'mdi-plus-box');
                    $details[] = $this->renderCollapsible($summary, $body);
                    break;
                case 'insertAtLine':
                    $line = $u['line'] ?? '?';
                    $len = isset($u['content']) ? mb_strlen((string) $u['content']) : 0;
                    $summary = "<i class='mdi mdi-text-box-plus-outline me-1'></i><strong>insertAtLine</strong> @ line <span class='text-cyber'>$line</span> <span class='text-success'>+{$len}</span> chars";
                    $body = $this->renderContentBlock($u['content'] ?? '', 'success', 'mdi-plus-box');
                    $details[] = $this->renderCollapsible($summary, $body);
                    break;
                default:
                    $opEsc = htmlspecialchars($op);
                    $details[] = "<div class='small text-muted'><i class='mdi mdi-pencil me-1'></i><strong>$opEsc</strong></div>";
            }
        }
        $detailsHtml = implode('', $details);
        $syntaxHtml = $this->buildSyntaxChecksHtml($syntaxChecks);
        $editLink = $this->buildFileEditLink($file);

        return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <div class="d-flex align-items-center">
        <i class="mdi mdi-file-edit-outline text-cyber me-2"></i>
        <strong>fileUpdate</strong>
        <span class="ms-2 text-success"><i class="mdi mdi-check-circle me-1"></i></span>
    </div>
    <div class="small text-muted mt-1"><i class="mdi mdi-file-outline me-1"></i><code>$path/$name</code>$editLink</div>
    <div class="mt-2">$detailsHtml</div>
    $syntaxHtml
</div>
HTML;
    }

    /**
     * Build compact syntax-check badges for the fileUpdate frontend card.
     * Supports PHP, Twig and JavaScript. Returns empty string when no checks
     * were performed.
     */
    private function buildSyntaxChecksHtml(array $syntaxChecks): string
    {
        if (empty($syntaxChecks)) {
            return '';
        }

        $html = '';
        foreach ($syntaxChecks as $type => $check) {
            $type = strtolower($type);
            $label = match ($type) {
                'php' => 'PHP',
                'twig' => 'Twig',
                'js' => 'JS',
                default => ucfirst($type),
            };
            $icon = match ($type) {
                'php' => 'mdi-language-php',
                'twig' => 'mdi-xml',
                'js' => 'mdi-nodejs',
                default => 'mdi-code-tags',
            };

            if ($check['valid']) {
                $html .= <<<HTML
<div class="small text-success mt-2"><i class="{$icon} me-1"></i><i class="mdi mdi-check-circle me-1"></i>{$label} syntax OK</div>
HTML;
            } else {
                $errorEsc = htmlspecialchars($check['output']);
                $html .= <<<HTML
<div class="mt-2">
    <div class="small text-danger"><i class="{$icon} me-1"></i><i class="mdi mdi-alert-circle me-1"></i>{$label} syntax error</div>
    <pre class="bg-black bg-opacity-50 rounded p-2 mb-0 small text-danger" style="max-height:160px;overflow:auto;white-space:pre-wrap;word-break:break-word;">$errorEsc</pre>
</div>
HTML;
            }
        }

        return $html;
    }

    /**
     * Build frontend data for fileUpdate failure
     */
    private function buildUpdateErrorFrontendData(array $arguments, string $error): string
    {
        $op = htmlspecialchars($arguments['operation'] ?? 'unknown');
        $path = htmlspecialchars($arguments['path'] ?? '');
        $name = htmlspecialchars($arguments['name'] ?? '');
        $fileDisplay = trim($path . '/' . $name, '/');
        $errorMsg = htmlspecialchars($error);

        return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <div class="d-flex align-items-center">
        <i class="mdi mdi-file-edit-outline text-cyber me-2"></i>
        <strong>fileUpdate</strong>
        <span class="ms-2 text-danger"><i class="mdi mdi-alert-circle me-1"></i>Failed</span>
        <span class="ms-2 text-muted">$op</span>
    </div>
    <div class="small text-muted mt-1"><i class="mdi mdi-file-outline me-1"></i><code>$fileDisplay</code></div>
    <div class="small text-danger mt-1"><i class="mdi mdi-alert-outline me-1"></i>$errorMsg</div>
</div>
HTML;
    }

    /**
     * Build frontend data for failed fileManage operations
     */
    private function buildManageErrorFrontendData(string $operation, string $target, string $error): string
    {
        $opEsc = htmlspecialchars($operation);
        $targetEsc = htmlspecialchars($target);
        $errorMsg = htmlspecialchars($error);

        $iconMap = [
            'read'            => 'mdi-file-document-outline',
            'list'            => 'mdi-folder-open-outline',
            'createDirectory' => 'mdi-folder-plus',
            'create'          => 'mdi-file-plus-outline',
            'copy'            => 'mdi-content-copy',
            'rename_move'     => 'mdi-file-move-outline',
            'delete'          => 'mdi-delete-outline',
        ];
        $icon = $iconMap[$operation] ?? 'mdi-file-cog-outline';

        $targetLine = $target !== ''
            ? "<div class='small text-muted mt-1'><i class='mdi mdi-file-outline me-1'></i><code>$targetEsc</code></div>"
            : '';

        return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <div class="d-flex align-items-center">
        <i class="mdi $icon text-cyber me-2"></i>
        <strong>fileManage</strong>
        <span class="ms-2 text-danger"><i class="mdi mdi-alert-circle me-1"></i>Failed</span>
        <span class="ms-2 text-muted">$opEsc</span>
    </div>
    $targetLine
    <div class="small text-danger mt-1"><i class="mdi mdi-alert-outline me-1"></i>$errorMsg</div>
</div>
HTML;
    }

    /**
     * Build a clickable "edit file" link for text/code files, wired to the shared
     * FileEditModal via the global `data-action="cq-edit-file"` delegated listener.
     * Returns empty string for non-text files or when the file has no id.
     */
    private function buildFileEditLink($file): string
    {
        if (!$file || !method_exists($file, 'getId') || !$file->getId()) {
            return '';
        }
        $name = $file->getName();
        $mime = method_exists($file, 'getMimeType') ? $file->getMimeType() : null;
        $type = method_exists($file, 'getType') ? $file->getType() : null;
        if (!$this->projectFileService->isTextFile($name, $mime, $type)) {
            return '';
        }
        $id = htmlspecialchars($file->getId());
        return " <a href='#' class='cq-file-edit-link text-warning ms-1' data-action='cq-edit-file' data-file-id='{$id}' title='Edit file'><i class='mdi mdi-file-edit'></i></a>";
    }

    /**
     * Render a collapsible row: clickable summary + hidden body that toggles on click.
     * Uses the same vanilla JS pattern as the PDF preview block already in this service.
     */
    private function renderCollapsible(string $summaryHtml, string $bodyHtml): string
    {
        return <<<HTML
<div class="cq-collapsible mt-1">
    <div class="small text-muted cursor-pointer d-flex align-items-center"
         onclick="this.querySelector('.cq-chev').classList.toggle('mdi-chevron-down');this.querySelector('.cq-chev').classList.toggle('mdi-chevron-right');this.nextElementSibling.classList.toggle('d-none');">
        <i class="mdi mdi-chevron-right cq-chev me-1"></i>
        <span>$summaryHtml</span>
    </div>
    <div class="d-none mt-1 ps-3">$bodyHtml</div>
</div>
HTML;
    }

    /**
     * Render a diff-style block: removed (red) + added (green) content side by side.
     */
    private function renderDiffBlock(string $removed, string $added): string
    {
        $removedBlock = $removed !== ''
            ? $this->renderContentBlock($removed, 'danger', 'mdi-minus-box')
            : '';
        $addedBlock = $added !== ''
            ? $this->renderContentBlock($added, 'success', 'mdi-plus-box')
            : '';
        return $removedBlock . $addedBlock;
    }

    /**
     * Render a single content block with label icon + colored bordered <pre>.
     * Truncates very long content to keep the UI responsive.
     */
    private function renderContentBlock(string $content, string $variant, string $icon): string
    {
        $maxChars = 4000;
        $original = $content;
        $truncated = false;
        if (mb_strlen($content) > $maxChars) {
            $content = mb_substr($content, 0, $maxChars);
            $truncated = true;
        }
        $escaped = htmlspecialchars($content);
        $note = $truncated
            ? '<div class="small text-muted mt-1"><i class="mdi mdi-dots-horizontal me-1"></i>truncated, ' . (mb_strlen($original) - $maxChars) . ' more chars</div>'
            : '';

        // variant: 'success' (green/added), 'danger' (red/removed)
        $borderClass = $variant === 'danger' ? 'border-danger' : 'border-success';
        $textClass = $variant === 'danger' ? 'text-danger' : 'text-success';

        return <<<HTML
<div class="mt-1">
    <div class="small $textClass mb-1"><i class="mdi $icon me-1"></i></div>
    <pre class="bg-dark bg-opacity-50 rounded p-2 border-start border-3 $borderClass small mb-1" style="white-space: pre-wrap; word-break: break-word; max-height: 360px; overflow: auto;">$escaped</pre>
    $note
</div>
HTML;
    }

    /**
     * Build frontend data for createDirectory
     */
    private function buildDirectoryFrontendData(string $path, string $name): string
    {
        $displayPath = htmlspecialchars(rtrim($path, '/') . '/' . $name);

        return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <div class="d-flex align-items-center">
        <i class="mdi mdi-folder-plus text-cyber me-2"></i>
        <strong>Directory created</strong>
    </div>
    <div class="small text-muted mt-1"><i class="mdi mdi-folder-outline me-1"></i><code>$displayPath</code></div>
</div>
HTML;
    }

    /**
     * Build frontend data for list operation
     */
    private function buildListFrontendData(string $path, array $files): string
    {
        $displayPath = htmlspecialchars($path);
        $count = count($files);

        $dirs = 0;
        $regular = 0;
        foreach ($files as $f) {
            if ($f->isDirectory()) {
                $dirs++;
            } else {
                $regular++;
            }
        }

        $items = '';
        $shown = array_slice($files, 0, 22);
        foreach ($shown as $f) {
            $n = htmlspecialchars($f->getName());
            $icon = $f->isDirectory() ? 'mdi-folder-outline' : 'mdi-file-outline';
            $items .= "<div class='small text-muted'><i class='mdi $icon me-1'></i><code>$n</code></div>";
        }
        $more = $count > 22 ? '<div class="small text-muted mt-1">… and ' . ($count - 22) . ' more</div>' : '';

        return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <div class="d-flex align-items-center">
        <i class="mdi mdi-folder-open-outline text-cyber me-2"></i>
        <strong>list</strong>
        <span class="ms-2 text-muted">$count item(s)</span>
    </div>
    <div class="small text-muted mt-1"><i class="mdi mdi-folder-outline me-1"></i><code>$displayPath</code></div>
    <div class="small mt-1">
        <span class="text-info"><i class="mdi mdi-folder me-1"></i>$dirs dirs</span>
        <span class="text-success ms-2"><i class="mdi mdi-file me-1"></i>$regular files</span>
    </div>
    <div class="mt-2">$items$more</div>
</div>
HTML;
    }

    /**
     * Build frontend data for create/copy/rename_move/delete operations
     */
    private function buildFileOperationFrontendData(string $operation, array $params, ?array $result = null, array $syntaxChecks = []): string
    {
        $source = $params['source'] ?? null;
        $dest = $params['destination'] ?? null;

        $sourceDisplay = $source ? htmlspecialchars(rtrim($source['path'], '/') . '/' . $source['name']) : '';
        $destDisplay = $dest ? htmlspecialchars(rtrim($dest['path'], '/') . '/' . $dest['name']) : '';
        $sourceDisplay = str_replace('//', '/', $sourceDisplay);
        $destDisplay = str_replace('//', '/', $destDisplay);

        switch ($operation) {
            case 'create':
                $contentStr = (string) ($params['content'] ?? '');
                $contentLen = mb_strlen($contentStr);
                
                $editLink = '';
                if ($result && isset($result['file'])) {
                    $name = $result['file']['name'] ?? '';
                    $mime = $result['file']['mimeType'] ?? null;
                    $type = $result['file']['type'] ?? null;
                    if ($this->projectFileService->isTextFile($name, $mime, $type)) {
                        $id = htmlspecialchars($result['file']['id']);
                        $editLink = " <a href='#' class='cq-file-edit-link text-warning ms-1' data-action='cq-edit-file' data-file-id='{$id}' title='Edit file'><i class='mdi mdi-file-edit'></i></a>";
                    }
                }

                $syntaxHtml = $this->buildSyntaxChecksHtml($syntaxChecks);

                $header = <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <div class="d-flex align-items-center">
        <i class="mdi mdi-file-plus-outline text-cyber me-2"></i>
        <strong>File created</strong>
        <span class="ms-2 text-success">+$contentLen chars</span>
    </div>
    <div class="small text-muted mt-1"><i class="mdi mdi-file-outline me-1"></i><code>$destDisplay</code>$editLink</div>
    $syntaxHtml
</div>
HTML;

                // Do not embed the full content preview in the chat UI.
                // The content can be reviewed in the CodeMirror edit modal.
                return $header;

            case 'copy':
                return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <div class="d-flex align-items-center">
        <i class="mdi mdi-content-copy text-cyber me-2"></i>
        <strong>File copied</strong>
    </div>
    <div class="small text-muted mt-1"><i class="mdi mdi-file-outline me-1"></i><code>$sourceDisplay</code></div>
    <div class="small text-muted"><i class="mdi mdi-arrow-right me-1"></i><code>$destDisplay</code></div>
</div>
HTML;

            case 'rename_move':
                return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <div class="d-flex align-items-center">
        <i class="mdi mdi-file-move-outline text-cyber me-2"></i>
        <strong>File renamed/moved</strong>
    </div>
    <div class="small text-muted mt-1"><i class="mdi mdi-file-outline me-1"></i><code>$sourceDisplay</code></div>
    <div class="small text-muted"><i class="mdi mdi-arrow-right me-1"></i><code>$destDisplay</code></div>
</div>
HTML;

            case 'delete':
                return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <div class="d-flex align-items-center">
        <i class="mdi mdi-delete-outline text-danger me-2"></i>
        <strong>File deleted</strong>
    </div>
    <div class="small text-muted mt-1"><i class="mdi mdi-file-outline me-1"></i><code>$sourceDisplay</code></div>
</div>
HTML;

            default:
                $opEsc = htmlspecialchars($operation);
                return <<<HTML
<div class="bg-dark bg-opacity-50 rounded p-2">
    <i class="mdi mdi-file-cog-outline text-cyber me-1"></i><strong>$opEsc</strong>
</div>
HTML;
        }
    }

    /**
     * Handle file operations (create, copy, rename_move, delete)
     */
    private function handleFileOperations(string $projectId, string $operation, array $arguments): array
    {
        // Validate Spirit access for source path
        if (isset($arguments['sourcePath'])) {
            $this->validateSpiritAccess(['path' => $arguments['sourcePath'], '_spiritSlug' => $arguments['_spiritSlug'] ?? null]);
        }
        // Validate Spirit access for destination path
        if (isset($arguments['destPath'])) {
            $this->validateSpiritAccess(['path' => $arguments['destPath'], '_spiritSlug' => $arguments['_spiritSlug'] ?? null]);
        }

        // Prepare parameters based on operation
        $params = [];

        // Source parameters (required for copy, rename_move, delete)
            // Support both flat (sourcePath, sourceName) and nested (source.path, source.name) formats
        if (in_array($operation, ['copy', 'rename_move', 'delete'])) {
            if (isset($arguments['sourcePath']) && isset($arguments['sourceName'])) {
                    // New flat format
                $params['source'] = [
                    'path' => $arguments['sourcePath'],
                    'name' => $arguments['sourceName']
                ];
            } elseif (isset($arguments['source']) && isset($arguments['source']['path']) && isset($arguments['source']['name'])) {
                    // Legacy nested format
                $params['source'] = $arguments['source'];
            } else {
                throw new \InvalidArgumentException($operation . ' operation requires sourcePath and sourceName');
            }
        }

        // Destination parameters (required for create, copy, rename_move)
            // Support both flat (destPath, destName) and nested (destination.path, destination.name) formats
        if (in_array($operation, ['create', 'copy', 'rename_move'])) {
            if (isset($arguments['destPath']) && isset($arguments['destName'])) {
                    // New flat format
                $params['destination'] = [
                    'path' => $arguments['destPath'],
                    'name' => $arguments['destName']
                ];
            } elseif (isset($arguments['destination']) && isset($arguments['destination']['path']) && isset($arguments['destination']['name'])) {
                    // Legacy nested format
                $params['destination'] = $arguments['destination'];
            } else {
                throw new \InvalidArgumentException($operation . ' operation requires destPath and destName');
            }
        }

        // Content parameter (required for create)
        if ($operation === 'create') {
            if (!isset($arguments['content'])) {
                throw new \InvalidArgumentException('Create operation requires content parameter');
            }
            $params['content'] = $arguments['content'];
        }

        // Execute the operation
        $result = $this->projectFileService->manageFile($projectId, $operation, $params);

        // Run syntax checks for newly created PHP/Twig files.
        $syntaxChecks = [];
        if ($operation === 'create' && isset($params['destination']['path'], $params['destination']['name'])) {
            $createdFile = $this->projectFileService->findByPathAndName(
                $projectId,
                $params['destination']['path'],
                $params['destination']['name']
            );
            if ($createdFile) {
                $syntaxChecks = $this->runFileSyntaxChecks($createdFile);
            }
        }

        $response = [
            'success' => true,
            'operation' => $operation,
            'result' => $result,
            '_frontendData' => $this->buildFileOperationFrontendData($operation, $params, $result, $syntaxChecks)
        ];

        if (!empty($syntaxChecks)) {
            $response['syntaxChecks'] = $syntaxChecks;
        }

        return $response;
    }

    /**
     * Expand combined `pathname` style arguments into the separate path/name
     * components the rest of the file tooling already expects.
     *
     * AI Spirits provide a single full path (e.g. "/spirit/X/memory/notes.md")
     * which is far more natural for an LLM than splitting directory + filename.
     * This keeps all downstream code untouched and stays backward compatible:
     * explicitly provided path/name (or sourcePath/destPath etc.) always win.
     *
     * - pathname        -> path/name AND sourcePath/sourceName AND destPath/destName
     *                      (single-target ops: create, read, delete, createDirectory)
     * - sourcePathname  -> sourcePath/sourceName (copy, rename_move source)
     * - destPathname    -> destPath/destName     (copy, rename_move destination)
     */
    private function expandPathnames(array $arguments): array
    {
        if (!empty($arguments['pathname'])) {
            $split = $this->projectFileService->splitPathname($arguments['pathname']);
            $arguments['path']       ??= $split['path'];
            $arguments['name']       ??= $split['name'];
            $arguments['sourcePath'] ??= $split['path'];
            $arguments['sourceName'] ??= $split['name'];
            $arguments['destPath']   ??= $split['path'];
            $arguments['destName']   ??= $split['name'];
        }

        if (!empty($arguments['sourcePathname'])) {
            $split = $this->projectFileService->splitPathname($arguments['sourcePathname']);
            $arguments['sourcePath'] ??= $split['path'];
            $arguments['sourceName'] ??= $split['name'];
        }

        if (!empty($arguments['destPathname'])) {
            $split = $this->projectFileService->splitPathname($arguments['destPathname']);
            $arguments['destPath'] ??= $split['path'];
            $arguments['destName'] ??= $split['name'];
        }

        return $arguments;
    }

    /**
     * Validate required arguments
     */
    private function validateArguments(array $arguments, array $required): void
    {
        foreach ($required as $arg) {
            if (!isset($arguments[$arg])) {
                throw new \InvalidArgumentException("Missing required argument: $arg");
            }
        }
    }
    
    /**
     * Validate Spirit access to path
     * Spirits can access all files EXCEPT other Spirits' folders in /spirit/
     * 
     * @param array $arguments Tool arguments containing path and optional _spiritSlug
     * @throws \RuntimeException if access is denied
     */
    private function validateSpiritAccess(array $arguments): void
    {
        $spiritSlug = $arguments['_spiritSlug'] ?? null;
        if (!$spiritSlug) {
            return; // No Spirit context, allow all (for non-Spirit usage like user's file browser)
        }
        
        $path = $arguments['path'] ?? '/';
        
        // Normalize path
        $path = '/' . ltrim($path, '/');
        
        // Check if path is within /spirit/ directory
        if (!str_starts_with($path, '/spirit/')) {
            return; // Not in /spirit/ directory, allow access
        }
        
        // Extract the spirit folder name from path (e.g., /spirit/SpiritName/... -> SpiritName)
        $pathParts = explode('/', trim($path, '/'));
        if (count($pathParts) < 2) {
            return; // Just /spirit/ itself, allow access
        }
        
        $targetSpiritSlug = $pathParts[1]; // The folder name after /spirit/
        
        // Allow access only if it's the Spirit's own folder
        if ($targetSpiritSlug !== $spiritSlug) {
            throw new \RuntimeException("Access denied: Spirit can only access its own folder /spirit/{$spiritSlug}/, not /spirit/{$targetSpiritSlug}/");
        }
    }
}
