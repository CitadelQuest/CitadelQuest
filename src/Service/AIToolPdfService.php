<?php

namespace App\Service;

use App\Entity\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Process\Process;

/**
 * Service for the createPDF AI Tool.
 *
 * Renders HTML/CSS into a PDF document and saves it to the user's File Browser.
 *
 * Two engines:
 *  - "dompdf" (default): pure PHP, true vector PDF with selectable/searchable
 *    text and embedded fonts. CSS 2.1 + partial CSS3 (no flexbox/grid).
 *  - "chromium": renders through the Obscura headless browser (same infra as
 *    screenshotURL) for full modern CSS fidelity. NOTE: Obscura's Page.printToPDF
 *    rasterizes the page into an image, so text is NOT selectable and files are
 *    larger — use only when pixel-perfect visual output matters more than text.
 *
 * Input is either an inline `html` string or a `pathname` pointing to an existing
 * .html file in the File Browser.
 *
 * Security: remote resource loading is disabled for dompdf and its chroot is
 * limited to the user's own project storage; the Obscura helper runs on a random
 * loopback port with file:// access left off.
 */
class AIToolPdfService
{
    private const DEFAULT_SAVE_PATH = '/uploads/ai/pdf';
    private const DEFAULT_PAGE_SIZE = 'A4';
    private const DEFAULT_ORIENTATION = 'portrait';
    private const DEFAULT_MARGIN = '15mm';
    private const DEFAULT_TIMEOUT = 60;
    private const DEFAULT_CHROMIUM_VIEWPORT_WIDTH = 1200;
    private const MAX_HTML_BYTES = 5_000_000; // 5MB HTML input cap
    private const OBSCURA_PDF_SCRIPT = __DIR__ . '/../../bin/obscura-pdf.cjs';
    private const NODE_BINARY = 'node';

    /**
     * Page dimensions in millimetres [width, height] (portrait), used to size the
     * chromium viewport so a page's raster matches the target page aspect ratio.
     */
    private const PAGE_SIZES_MM = [
        'A3' => [297.0, 420.0],
        'A4' => [210.0, 297.0],
        'A5' => [148.0, 210.0],
        'LETTER' => [215.9, 279.4],
        'LEGAL' => [215.9, 355.6],
    ];

    public function __construct(
        private readonly ProjectFileService $projectFileService,
        private readonly Security $security,
        private readonly ParameterBagInterface $params,
        private readonly AiToolService $aiToolService,
        private readonly AiToolSettingsService $aiToolSettingsService
    ) {
    }

    /**
     * Create a PDF from HTML/CSS and save it to the user's File Browser.
     *
     * @param array $arguments Tool arguments:
     *   - html: string (optional) - Inline HTML (full document or fragment) to render
     *   - pathname: string (optional) - Path to an existing .html file in the File Browser (alternative to html)
     *   - filename: string (optional) - Output filename (default: auto-generated .pdf)
     *   - savePath: string (optional) - Project path to save the PDF (default: /uploads/ai/pdf)
     *   - projectId: string (optional) - Project ID for file storage (default: general)
     *   - engine: string (optional) - 'dompdf' (default, selectable text) or 'chromium' (full CSS, rasterized)
     *   - pageSize: string (optional) - Page size, e.g. A4, Letter, A3 (default: A4)
     *   - orientation: string (optional) - 'portrait' (default) or 'landscape'
     *   - margin: string (optional) - Page margin with CSS unit, e.g. 15mm, 1in (default: 15mm)
     *
     * @return array Tool result with success status, saved file info, and frontend preview data
     */
    public function createPDF(array $arguments): array
    {
        try {
            $engine = strtolower(trim($arguments['engine'] ?? 'dompdf'));
            if (!in_array($engine, ['dompdf', 'chromium'], true)) {
                return ['success' => false, 'error' => "Invalid engine '{$engine}'. Supported: dompdf, chromium."];
            }

            $projectId = $arguments['projectId'] ?? 'general';
            $savePath = '/' . trim($arguments['savePath'] ?? self::DEFAULT_SAVE_PATH, '/');
            $pageSize = trim($arguments['pageSize'] ?? self::DEFAULT_PAGE_SIZE) ?: self::DEFAULT_PAGE_SIZE;
            $orientation = strtolower(trim($arguments['orientation'] ?? self::DEFAULT_ORIENTATION));
            $margin = trim($arguments['margin'] ?? self::DEFAULT_MARGIN) ?: self::DEFAULT_MARGIN;

            if (!in_array($orientation, ['portrait', 'landscape'], true)) {
                return ['success' => false, 'error' => "Invalid orientation '{$orientation}'. Supported: portrait, landscape."];
            }

            // Resolve HTML input: inline string or existing .html file
            $html = null;
            $source = null;
            $sourcePath = null;

            if (isset($arguments['html']) && is_string($arguments['html']) && trim($arguments['html']) !== '') {
                $html = $arguments['html'];
                $source = 'inline';
            } elseif (isset($arguments['pathname']) && is_string($arguments['pathname']) && trim($arguments['pathname']) !== '') {
                $pathname = trim($arguments['pathname']);
                $dir = dirname($pathname);
                $name = basename($pathname);
                $file = $this->projectFileService->findByPathAndName($projectId, $dir, $name);
                if (!$file) {
                    return ['success' => false, 'error' => "HTML file not found: {$pathname} (project: {$projectId})"];
                }
                $html = $this->projectFileService->getFileContent($file->getId());
                $source = 'file';
                $sourcePath = $pathname;
            } else {
                return ['success' => false, 'error' => 'createPDF requires either `html` (inline markup) or `pathname` (path to an existing .html file).'];
            }

            if (strlen($html) > self::MAX_HTML_BYTES) {
                return ['success' => false, 'error' => 'HTML input exceeds the maximum allowed size (' . self::MAX_HTML_BYTES . ' bytes).'];
            }

            // The chromium pipeline does not resolve physical CSS units (mm/cm/in/pt
            // fall back to auto), so normalize the injected @page margin to px for it.
            $documentMargin = $engine === 'chromium' ? $this->toPixels($margin) : $margin;
            $documentHtml = $this->buildHtmlDocument($html, $documentMargin);

            // Render to PDF bytes
            if ($engine === 'chromium') {
                $render = $this->renderWithChromium($documentHtml, $pageSize, $orientation, $documentMargin);
            } else {
                $render = $this->renderWithDompdf($documentHtml, $pageSize, $orientation);
            }

            if (!$render['success']) {
                return ['success' => false, 'error' => $render['error']];
            }

            $pdfBytes = $render['bytes'];
            $pages = $render['pages'] ?? $this->countPdfPages($pdfBytes);

            // Resolve output filename
            $filename = $arguments['filename'] ?? null;
            if (!$filename) {
                $filename = 'document_' . date('Y-m-d_His') . '.pdf';
            }
            if (!str_ends_with(strtolower($filename), '.pdf')) {
                $filename .= '.pdf';
            }

            // Replace any existing file at the same path + name
            $existing = $this->projectFileService->findByPathAndName($projectId, $savePath, $filename);
            if ($existing) {
                $this->projectFileService->delete($existing->getId());
            }

            $savedFile = $this->projectFileService->createFile(
                $projectId,
                $savePath,
                $filename,
                $pdfBytes,
                'application/pdf'
            );

            $fileSize = strlen($pdfBytes);
            $filePath = $savePath . '/' . $filename;

            $engineNote = $engine === 'chromium'
                ? ' (chromium engine: full CSS fidelity, but text is rasterized/not selectable)'
                : ' (dompdf engine: selectable text)';

            $canvas = ($engine === 'chromium' && !empty($render['canvasWidth']))
                ? $render['canvasWidth'] . ' × ' . $render['canvasHeight'] . ' px'
                : null;

            $message = 'PDF created successfully with the ' . $engine . ' engine' . $engineNote . '. '
                . 'Saved to `' . $filePath . '`'
                . ($pages ? ' — ' . $pages . ' page' . ($pages === 1 ? '' : 's') : '')
                . ', ' . $this->formatBytes($fileSize) . '.';

            if ($engine === 'chromium') {
                if ($canvas) {
                    $message .= ' Render canvas: ' . $canvas . ' — design for this size using px.';
                }
                // Warn when physical CSS units are used with the chromium engine — they
                // are not resolved by the pipeline and fall back to auto (see F-02).
                if (preg_match('/\d+(?:\.\d+)?\s*(?:mm|cm|in|pt)\b/i', $html)) {
                    $message .= ' WARNING: physical CSS units (mm/cm/in/pt) are not supported by the chromium engine — a sized element falls back to auto (collapsing to its content height; an empty one disappears). Use px only.';
                }
            }

            return [
                'success' => true,
                'message' => $message,
                'engine' => $engine,
                'source' => $source,
                'sourcePath' => $sourcePath,
                'file' => $savedFile->jsonSerialize(),
                'filePath' => $filePath,
                'fileId' => $savedFile->getId(),
                'fileSize' => $fileSize,
                'pages' => $pages,
                'pageSize' => $pageSize,
                'orientation' => $orientation,
                '_frontendData' => $this->buildFrontendDisplay($filePath, $savedFile->getId(), $fileSize, $pages, $engine, $pageSize, $orientation, $canvas),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Failed to create PDF: ' . $e->getMessage()];
        }
    }

    /**
     * Render HTML to PDF using the pure-PHP dompdf engine (vector text).
     *
     * @return array{success: bool, bytes?: string, pages?: int, error?: string}
     */
    private function renderWithDompdf(string $html, string $pageSize, string $orientation): array
    {
        try {
            $options = new Options();
            $options->set('isRemoteEnabled', false);
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isFontSubsettingEnabled', true);
            $options->set('isJavascriptEnabled', false);
            $options->set('isPhpEnabled', false);
            $options->set('defaultFont', 'DejaVu Sans');
            $options->set('chroot', $this->getUserChroot());

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper($pageSize, $orientation);
            $dompdf->render();

            return [
                'success' => true,
                'bytes' => $dompdf->output(),
                'pages' => $dompdf->getCanvas()->get_page_count(),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'dompdf render failed: ' . $e->getMessage()];
        }
    }

    /**
     * Render HTML to PDF using the Obscura headless browser (chromium engine).
     *
     * NOTE: Obscura's Page.printToPDF rasterizes the page into an image, so the
     * output has no selectable text. Use when full modern CSS is required.
     *
     * @return array{success: bool, bytes?: string, pages?: int, error?: string}
     */
    private function renderWithChromium(string $html, string $pageSize, string $orientation, string $margin): array
    {
        if (!file_exists(self::OBSCURA_PDF_SCRIPT)) {
            return ['success' => false, 'error' => 'Chromium engine helper not found at ' . self::OBSCURA_PDF_SCRIPT];
        }

        $binaryPath = $this->getSettingValue('obscura_binary_path', '/usr/local/bin/obscura');
        if (!file_exists($binaryPath) || !is_executable($binaryPath)) {
            return ['success' => false, 'error' => 'Obscura binary not found at ' . $binaryPath . '. Install Obscura or use the default dompdf engine.'];
        }

        $timeout = (int) $this->getSettingValue('obscura_timeout', (string) self::DEFAULT_TIMEOUT);

        $htmlFile = sys_get_temp_dir() . '/cq_pdf_' . uniqid() . '.html';
        $outputFile = sys_get_temp_dir() . '/cq_pdf_' . uniqid() . '.pdf';

        try {
            if (file_put_contents($htmlFile, $html) === false) {
                return ['success' => false, 'error' => 'Failed to write temporary HTML file.'];
            }

            // The helper derives its canvas height from the browser's actual layout
            // width and the target page aspect, so short content fills a whole page
            // (F-03) and an exactly-full page does not spill onto page 2 (F-04),
            // whatever width Obscura happens to use.
            $viewportWidth = (int) $this->getSettingValue('chromium_viewport_width', (string) self::DEFAULT_CHROMIUM_VIEWPORT_WIDTH);
            $aspect = $this->computeChromiumAspect($pageSize, $orientation);

            $command = [
                self::NODE_BINARY,
                self::OBSCURA_PDF_SCRIPT,
                '--html-file', $htmlFile,
                '--output', $outputFile,
                '--format', $pageSize,
                '--margin', $margin,
                '--width', (string) $viewportWidth,
                '--aspect', (string) $aspect,
                '--timeout', (string) $timeout,
                '--binary', $binaryPath,
            ];
            if ($orientation === 'landscape') {
                $command[] = '--landscape';
            }

            $projectRoot = dirname(__DIR__, 2);
            $env = array_filter(array_merge($_ENV, $_SERVER), 'is_scalar');
            $env['NODE_PATH'] = trim(shell_exec('npm root -g 2>/dev/null') ?: '/usr/local/lib/node_modules');
            $process = new Process($command, $projectRoot, $env);
            $process->setTimeout($timeout + 20);
            $process->run();

            if (!$process->isSuccessful()) {
                $stderrJson = json_decode($process->getErrorOutput(), true);
                $errorMsg = $stderrJson['error'] ?? trim($process->getErrorOutput());
                return ['success' => false, 'error' => 'Chromium PDF render failed: ' . $errorMsg];
            }

            if (!file_exists($outputFile) || filesize($outputFile) === 0) {
                return ['success' => false, 'error' => 'Chromium engine did not produce a PDF file.'];
            }

            $bytes = file_get_contents($outputFile);
            if (substr($bytes, 0, 5) !== '%PDF-') {
                return ['success' => false, 'error' => 'Chromium engine produced an invalid PDF (bad header).'];
            }

            $result = json_decode($process->getOutput(), true);
            $pages = isset($result['pages']) ? (int) $result['pages'] : $this->countPdfPages($bytes);

            return [
                'success' => true,
                'bytes' => $bytes,
                'pages' => $pages,
                'canvasWidth' => isset($result['layoutWidth']) ? (int) $result['layoutWidth'] : null,
                'canvasHeight' => isset($result['pageHeight']) ? (int) $result['pageHeight'] : null,
            ];
        } finally {
            @unlink($htmlFile);
            @unlink($outputFile);
        }
    }

    /**
     * Ensure the input is a full HTML document and inject the page margin rule.
     */
    private function buildHtmlDocument(string $html, string $margin): string
    {
        $style = '<style>@page { margin: ' . $margin . '; }</style>';

        if (stripos($html, '<html') !== false) {
            if (stripos($html, '</head>') !== false) {
                return preg_replace('/<\/head>/i', $style . '</head>', $html, 1);
            }
            return preg_replace('/(<html[^>]*>)/i', '$1' . $style, $html, 1);
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8">' . $style . '</head><body>' . $html . '</body></html>';
    }

    /**
     * Best-effort page count for a PDF byte string (uncompressed /Type /Page objects).
     */
    private function countPdfPages(string $bytes): ?int
    {
        $count = preg_match_all('/\/Type\s*\/Page(?![s])/', $bytes);
        return $count > 0 ? $count : null;
    }

    /**
     * Compute the target page aspect ratio (height / width) for the given page
     * size + orientation. The chromium helper uses it to size its render canvas
     * from the browser's actual layout width.
     */
    private function computeChromiumAspect(string $pageSize, string $orientation): float
    {
        [$w, $h] = self::PAGE_SIZES_MM[strtoupper($pageSize)] ?? self::PAGE_SIZES_MM['A4'];
        if ($orientation === 'landscape') {
            [$w, $h] = [$h, $w];
        }
        return round($h / $w, 6);
    }

    /**
     * Convert a CSS length with a physical unit (mm/cm/in/pt) to px (96 dpi).
     * Values already in px (or unitless) are returned as-is.
     */
    private function toPixels(string $value): string
    {
        if (!preg_match('/^([\d.]+)\s*(px|mm|cm|in|pt)?$/i', trim($value), $m)) {
            return $value;
        }
        $n = (float) $m[1];
        $px = match (strtolower($m[2] ?? 'px')) {
            'mm' => $n * 96 / 25.4,
            'cm' => $n * 96 / 2.54,
            'in' => $n * 96,
            'pt' => $n * 96 / 72,
            default => $n,
        };
        return round($px, 2) . 'px';
    }

    /**
     * chroot for dompdf: the current user's own project storage only.
     */
    private function getUserChroot(): string
    {
        $projectDir = $this->params->get('kernel.project_dir');
        $user = $this->security->getUser();
        if ($user instanceof User) {
            $userDir = $projectDir . '/var/user_data/' . $user->getId() . '/p';
            if (is_dir($userDir)) {
                return $userDir;
            }
        }
        return $projectDir;
    }

    /**
     * Read a setting value from the createPDF tool settings.
     */
    private function getSettingValue(string $key, string $default): string
    {
        $tool = $this->aiToolService->findByName('createPDF');
        if (!$tool) {
            return $default;
        }
        return $this->aiToolSettingsService->getSettingValue($tool->getId(), $key) ?? $default;
    }

    /**
     * Build the chat UI preview card for the created PDF.
     */
    private function buildFrontendDisplay(string $filePath, string $fileId, int $fileSize, ?int $pages, string $engine, string $pageSize, string $orientation, ?string $canvas = null): string
    {
        $displayPath = htmlspecialchars($filePath);
        $displaySize = $this->formatBytes($fileSize);
        $engineLabel = $engine === 'chromium' ? 'Chromium (visual)' : 'dompdf (text)';

        $html = '<div class="col-12 position-relative bg-light p-2 rounded bg-opacity-10">';
        $html .= '<div class="text-start ms-2 d-inline-block w-100 mb-1 position-relative">';
        $html .= '  <i class="mdi mdi-file-pdf-box text-cyber fs-5 float-start me-2"></i>';
        $html .= '  <div class="small float-start mt-2 fw-bold">PDF Document</div>';
        $html .= '  <span class="small float-start mt-2 ms-2 opacity-75">' . $displayPath . '</span>';
        $html .= '</div>';
        $html .= '<div style="clear:both;"></div>';

        $html .= '<div class="w-100 m-0 p-0 position-relative">';
        $html .= '  <embed src="/api/project-file/' . $fileId . '/download" type="application/pdf" width="100%" height="420" class="rounded border border-secondary"/>';
        $html .= '</div>';

        $html .= '<div>';
        $html .= '  <div class="small text-muted float-start mt-2"><i class="mdi mdi-information-outline me-1"></i>'
            . $engineLabel . ' · ' . htmlspecialchars($pageSize) . ' ' . htmlspecialchars($orientation)
            . ($canvas ? ' · ' . htmlspecialchars($canvas) : '')
            . ($pages ? ' · ' . $pages . ' page' . ($pages === 1 ? '' : 's') : '')
            . ' · ' . $displaySize . '</div>';
        $html .= '  <a class="btn btn-sm btn-link text-cyber mt-1 float-end mx-2" href="/api/project-file/' . $fileId . '/download?download=1">';
        $html .= '    <i class="mdi mdi-download"></i>';
        $html .= '  </a>';
        $html .= '</div>';
        $html .= '<div style="clear:both;"></div>';
        $html .= '</div>';
        $html .= '<div style="clear:both;"></div>';

        return $html;
    }

    /**
     * Format byte size to human readable string.
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
}
