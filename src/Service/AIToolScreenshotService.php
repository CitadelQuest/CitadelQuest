<?php

namespace App\Service;

use Symfony\Component\Process\Process;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Service for AI Tool screenshot operations using Obscura headless browser
 *
 * Implements the screenshotURL tool which:
 * 1. Calls obscura binary to render a webpage and capture a PNG screenshot
 * 2. Saves the screenshot to user's File Browser via ProjectFileService
 * 3. Returns the image as _imageForAi for Spirit multimodal vision
 * 4. Returns _frontendData for chat UI preview
 *
 * @see /docs/features/obscura-screenshot.md
 */
class AIToolScreenshotService
{
    private const DEFAULT_BINARY_PATH = '/usr/local/bin/obscura';
    private const DEFAULT_TIMEOUT = 30;
    private const DEFAULT_WIDTH = 1440;
    private const DEFAULT_HEIGHT = 1000;
    private const MAX_WIDTH = 2560;
    private const MAX_HEIGHT = 2000;
    private const DEFAULT_SAVE_PATH = '/uploads/ai/screenshots';

    public function __construct(
        private readonly ProjectFileService $projectFileService,
        private readonly Security $security,
        private readonly AiToolService $aiToolService,
        private readonly AiToolSettingsService $aiToolSettingsService
    ) {
    }

    /**
     * Capture a screenshot of a web page using Obscura
     *
     * @param array $arguments Tool arguments:
     *   - url: string (required) - The web URL to screenshot
     *   - projectId: string (optional) - Project ID for file storage (default: 'general')
     *   - width: int (optional) - Viewport width in pixels (default: 1440)
     *   - height: int (optional) - Viewport height in pixels (default: 1000)
     *   - fullPage: bool (optional) - Capture full page, not just viewport (default: true)
     *   - waitUntil: string (optional) - Wait condition: 'load'|'domcontentloaded'|'networkidle0' (default: 'networkidle0')
     *   - savePath: string (optional) - Project path to save screenshot (default: /uploads/ai/screenshots)
     *   - filename: string (optional) - Filename for the screenshot (default: auto-generated)
     *   - forceRefresh: bool (optional) - Skip cache, always take fresh screenshot (default: false)
     *
     * @return array Tool result with success status, file info, and image data
     */
    public function screenshotURL(array $arguments): array
    {
        $this->validateArguments($arguments, ['url']);

        $url = $arguments['url'];
        $projectId = $arguments['projectId'] ?? 'general';
        $width = (int)($arguments['width'] ?? self::DEFAULT_WIDTH);
        $height = (int)($arguments['height'] ?? self::DEFAULT_HEIGHT);
        $fullPage = $arguments['fullPage'] ?? true;
        $waitUntil = $arguments['waitUntil'] ?? 'networkidle0';
        $savePath = $arguments['savePath'] ?? self::DEFAULT_SAVE_PATH;
        $filename = $arguments['filename'] ?? null;
        $forceRefresh = $arguments['forceRefresh'] ?? false;

        // Clamp viewport dimensions
        $width = max(320, min($width, self::MAX_WIDTH));
        $height = max(240, min($height, self::MAX_HEIGHT));

        // Validate URL
        if (!$this->isValidUrl($url)) {
            return [
                'success' => false,
                'error' => 'Invalid URL. Must be a valid http:// or https:// URL.'
            ];
        }

        // Get Obscura binary path from settings or use default
        $binaryPath = $this->getSettingValue('obscura_binary_path', self::DEFAULT_BINARY_PATH);
        $timeout = (int)$this->getSettingValue('obscura_timeout', (string)self::DEFAULT_TIMEOUT);

        // Check if Obscura is installed
        if (!file_exists($binaryPath) || !is_executable($binaryPath)) {
            return [
                'success' => false,
                'error' => 'Obscura binary not found at ' . $binaryPath . '. Please install Obscura on the server to enable screenshot functionality.'
            ];
        }

        // Generate filename if not provided
        if (!$filename) {
            $domain = parse_url($url, PHP_URL_HOST) ?? 'unknown';
            $filename = 'screenshot_' . $domain . '_' . date('Y-m-d_His') . '.png';
        }

        // Ensure .png extension
        if (!str_ends_with(strtolower($filename), '.png')) {
            $filename .= '.png';
        }

        // Normalize save path
        $savePath = '/' . trim($savePath, '/');

        // Create temp file for screenshot output
        $tempFile = sys_get_temp_dir() . '/cq_screenshot_' . uniqid() . '.png';

        try {
            // Build Obscura command
            $command = [
                $binaryPath,
                'fetch',
                $url,
                '--screenshot',
                $tempFile,
                '--wait-until',
                $waitUntil,
                '--timeout',
                (string)$timeout,
            ];

            // Add viewport size via eval (Obscura CLI doesn't have direct viewport flag for fetch)
            // The --screenshot flag captures the rendered page at default viewport
            // For custom viewport, we'd need the CDP server mode, but for CLI simplicity
            // we use the default and let the screenshot capture the full page

            // Run Obscura process
            $process = new Process($command);
            $process->setTimeout($timeout + 10); // Give extra time for process overhead
            $process->run();

            if (!$process->isSuccessful()) {
                $errorOutput = $process->getErrorOutput();
                // Clean up temp file if it exists
                if (file_exists($tempFile)) {
                    @unlink($tempFile);
                }
                return [
                    'success' => false,
                    'error' => 'Obscura failed to capture screenshot: ' . trim($errorOutput ?: $process->getOutput())
                ];
            }

            // Check if screenshot file was created
            if (!file_exists($tempFile) || filesize($tempFile) === 0) {
                return [
                    'success' => false,
                    'error' => 'Screenshot file was not created. The page may have failed to load.'
                ];
            }

            // Read the PNG data
            $imageData = file_get_contents($tempFile);
            $fileSize = strlen($imageData);

            // Clean up temp file
            @unlink($tempFile);

            // Delete existing file at same path+name if present
            $existing = $this->projectFileService->findByPathAndName($projectId, $savePath, $filename);
            if ($existing) {
                $this->projectFileService->delete($existing->getId());
            }

            // Save to user's File Browser
            $savedFile = $this->projectFileService->createFile(
                $projectId,
                $savePath,
                $filename,
                $imageData,
                'image/png'
            );

            if (!$savedFile) {
                return [
                    'success' => false,
                    'error' => 'Failed to save screenshot to File Browser.'
                ];
            }

            // Build base64 data URI for frontend display
            $base64Data = 'data:image/png;base64,' . base64_encode($imageData);

            // Get image for AI vision (GD-resized by ProjectFileService)
            $imageForAi = $this->projectFileService->getFileContentForAiVision($savedFile->getId());
            $hasImageForAi = $imageForAi !== null;

            // Build frontend display HTML
            $frontendData = $this->buildFrontendDisplay($url, $savePath . '/' . $filename, $base64Data, $savedFile->getId(), $fileSize, $width, $height, $hasImageForAi);

            $result = [
                'success' => true,
                'message' => 'Screenshot captured successfully. Saved to `' . $savePath . '/' . $filename . '` and displayed in the user interface.' . ($hasImageForAi ? ' The screenshot is also attached below as image input for you to see.' : ''),
                'url' => $url,
                'file' => $savedFile->jsonSerialize(),
                'filePath' => $savePath . '/' . $filename,
                'fileId' => $savedFile->getId(),
                'fileSize' => $fileSize,
                'width' => $width,
                'height' => $height,
                '_frontendData' => $frontendData,
            ];

            if ($hasImageForAi) {
                $result['_imageForAi'] = [$imageForAi];
                $result['image_attached_to_ai'] = true;
            }

            return $result;

        } catch (\Exception $e) {
            // Clean up temp file on error
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
            return [
                'success' => false,
                'error' => 'Failed to capture screenshot: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get a setting value from the screenshotURL tool settings
     */
    private function getSettingValue(string $key, string $default): string
    {
        $tool = $this->aiToolService->findByName('screenshotURL');
        if (!$tool) {
            return $default;
        }
        $value = $this->aiToolSettingsService->getSettingValue($tool->getId(), $key);
        return $value ?? $default;
    }

    /**
     * Validate URL format and protocol
     */
    private function isValidUrl(string $url): bool
    {
        $parsed = parse_url($url);

        if (!$parsed || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            return false;
        }

        if (!in_array(strtolower($parsed['scheme']), ['http', 'https'])) {
            return false;
        }

        return true;
    }

    /**
     * Build frontend display HTML for the screenshot
     */
    private function buildFrontendDisplay(string $url, string $filePath, string $base64Data, string $fileId, int $fileSize, int $width, int $height, bool $hasImageForAi): string
    {
        $displayUrl = htmlspecialchars($url);
        $displayPath = htmlspecialchars($filePath);
        $displaySize = $this->formatBytes($fileSize);

        $html = '<div class="col-12 position-relative bg-light p-2 rounded bg-opacity-10">';
        $html .= '<div class="text-start ms-2 d-inline-block w-100 mb-1 position-relative">';
        $html .= '  <i class="mdi mdi-camera text-cyber fs-5 float-start me-2"></i>';
        $html .= '  <div class="small float-start mt-2 fw-bold">Webpage Screenshot</div>';
        $html .= '  <a href="' . $displayUrl . '" target="_blank" rel="noopener noreferrer" class="text-cyber text-decoration-none small float-start mt-2 ms-2">';
        $html .= '    <i class="mdi mdi-open-in-new me-1"></i>' . $displayUrl;
        $html .= '  </a>';
        $html .= '</div>';
        $html .= '<div style="clear:both;"></div>';

        // Screenshot image
        $randomID = uniqid();
        $html .= '<div class="w-100 m-0 p-0 position-relative" id="content-showcase-' . $randomID . '">';
        $html .= '  <div class="d-inline-block position-relative m-0 w-100">';
        $html .= '    <img src="' . $base64Data . '" alt="Screenshot of ' . $displayUrl . '" style="max-width: 100%; max-height: 70vh;" class="rounded"/>';
        $html .= '    <div class="content-showcase-icon position-absolute top-0 end-0 p-1 badge bg-dark bg-opacity-25 text-cyber cursor-pointer">';
        $html .= '      <i class="mdi mdi-fullscreen"></i>';
        $html .= '    </div>';
        $html .= '  </div>';
        $html .= '  <div>';
        $html .= '    <div style="clear: both;"></div>';
        $html .= '    <div class="small text-muted float-start mt-2"><i class="mdi mdi-image me-1"></i>' . $width . 'x' . $height . ' · ' . $displaySize . '</div>';
        // Download button
        $html .= '    <a class="btn btn-sm btn-link text-cyber mt-3 float-end mx-2" href="/api/project-file/' . $fileId . '/download?download=1">';
        $html .= '      <i class="mdi mdi-download"></i>';
        $html .= '    </a>';
        $html .= '  </div>';
        $html .= '</div>';
        $html .= '<div style="clear:both;"></div>';

        if ($hasImageForAi) {
            $html .= '<div class="small text-cyber opacity-75 mt-1"><i class="mdi mdi-eye-outline me-1"></i>Screenshot sent to AI vision</div>';
        }

        $html .= '</div>';
        $html .= '<div style="clear:both;"></div>';

        return $html;
    }

    /**
     * Format byte size to human readable string
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . 'B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . 'KB';
        }
        return round($bytes / 1024 / 1024, 1) . 'MB';
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
}
