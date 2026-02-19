<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Centralized service for managing .anno (annotation) files
 * 
 * Annotation files store AI-processed content for reuse, saving processing costs.
 * 
 * Supported types:
 * - 'pdf': Parsed PDF content (from AI LLM processing binary PDF data)
 * - 'url': Fetched and processed web content (with expiration)
 * 
 * Format:
 * - PDF: { "type": "file", "file": { "name", "hash", "content": [{"type": "text", "text": "..."}] } }
 * - URL: { "type": "url", "url": { "hash", "source", "title", "description", "fetched_at", "expires_at", "content": [{"type": "text", "text": "..."}] } }
 */
class AnnoService
{
    public const TYPE_PDF = 'pdf';
    public const TYPE_URL = 'url';
    
    public const DEFAULT_URL_CACHE_HOURS = 24;

    public function __construct(
        private readonly ProjectFileService $projectFileService,
        private readonly SluggerInterface $slugger,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Get the storage path and filename for an annotation
     * 
     * @param string $type Annotation type (pdf, url)
     * @param string $identifier Type-specific identifier (filename for pdf, URL for url)
     * @return array [path, filename]
     */
    public function getAnnotationPath(string $type, string $identifier): array
    {
        switch ($type) {
            case self::TYPE_PDF:
                // PDF: /annotations/pdf/{slug-filename}/{filename}.anno
                $slug = (string) $this->slugger->slug($identifier);
                return [
                    '/annotations/pdf/' . $slug,
                    $identifier . '.anno'
                ];
                
            case self::TYPE_URL:
                // URL: /annotations/web/{domain-slug}/{url-slug}.anno
                $parsed = parse_url($identifier);
                $domain = $parsed['host'] ?? 'unknown';
                $pathPart = ($parsed['path'] ?? '') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
                
                $domainSlug = (string) $this->slugger->slug($domain);
                
                // For homepage (empty or just "/"), use domain slug as filename
                $pathTrimmed = trim($pathPart, '/');
                if (empty($pathTrimmed)) {
                    $urlSlug = $domainSlug;
                } else {
                    $urlSlug = (string) $this->slugger->slug($pathTrimmed);
                }
                
                // Limit slug length
                if (strlen($urlSlug) > 100) {
                    $urlSlug = substr($urlSlug, 0, 100) . '-' . substr(hash('sha256', $pathPart), 0, 8);
                }
                
                return [
                    '/annotations/web/' . $domainSlug,
                    $urlSlug . '.anno'
                ];
                
            default:
                throw new \InvalidArgumentException("Unknown annotation type: {$type}");
        }
    }

    /**
     * Check if an annotation exists
     * 
     * @param string $type Annotation type
     * @param string $identifier Type-specific identifier
     * @param string $projectId Project ID (default: 'general')
     * @return bool
     */
    public function hasAnnotation(string $type, string $identifier, string $projectId = 'general'): bool
    {
        [$path, $filename] = $this->getAnnotationPath($type, $identifier);
        
        try {
            $file = $this->projectFileService->findByPathAndName($projectId, $path, $filename);
            return $file !== null;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Read an annotation file
     * 
     * @param string $type Annotation type
     * @param string $identifier Type-specific identifier
     * @param string $projectId Project ID (default: 'general')
     * @param bool $checkExpiration Whether to check expiration (for URL type)
     * @return array|null The annotation data or null if not found/expired
     */
    public function readAnnotation(string $type, string $identifier, string $projectId = 'general', bool $checkExpiration = true): ?array
    {
        [$path, $filename] = $this->getAnnotationPath($type, $identifier);
        
        try {
            $file = $this->projectFileService->findByPathAndName($projectId, $path, $filename);
            if (!$file) {
                return null;
            }
            
            $content = $this->projectFileService->getFileContent($file->getId());
            $data = json_decode($content, true);
            
            if (!$data) {
                return null;
            }
            
            // Validate type matches
            if (!$this->validateAnnotationType($data, $type)) {
                return null;
            }
            
            // Check expiration for URL type
            if ($checkExpiration && $type === self::TYPE_URL) {
                if ($this->isExpired($data)) {
                    return null;
                }
            }
            
            return $data;
            
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Write an annotation file
     * 
     * @param string $type Annotation type
     * @param string $identifier Type-specific identifier
     * @param array $data The annotation data to write
     * @param string $projectId Project ID (default: 'general')
     * @return bool Success status
     */
    public function writeAnnotation(string $type, string $identifier, array $data, string $projectId = 'general'): bool
    {
        [$path, $filename] = $this->getAnnotationPath($type, $identifier);
        
        try {
            $existingFile = $this->projectFileService->findByPathAndName($projectId, $path, $filename);
            $jsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            
            if ($existingFile) {
                $this->projectFileService->updateFile($existingFile->getId(), $jsonContent);
            } else {
                $this->projectFileService->createFile($projectId, $path, $filename, $jsonContent);
            }
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if a URL annotation is expired
     * 
     * @param array $data The annotation data
     * @return bool True if expired
     */
    public function isExpired(array $data): bool
    {
        // Only URL type has expiration
        if (!isset($data['url']['expires_at'])) {
            return false;
        }
        
        try {
            $expiresAt = new \DateTime($data['url']['expires_at']);
            return $expiresAt < new \DateTime();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Extract text content from annotation data
     * 
     * @param array $data The annotation data
     * @return string The extracted text content
     */
    public function getTextContent(array $data): string
    {
        $contentArray = null;
        
        // Get content array based on type
        if (isset($data['file']['content'])) {
            $contentArray = $data['file']['content'];
        } elseif (isset($data['url']['content'])) {
            $contentArray = $data['url']['content'];
        }
        
        if (!$contentArray || !is_array($contentArray)) {
            return '';
        }
        
        // Extract text from content array
        $textParts = [];
        foreach ($contentArray as $item) {
            if (isset($item['text'])) {
                $textParts[] = $item['text'];
            } elseif (isset($item['type']) && $item['type'] === 'text' && isset($item['text'])) {
                $textParts[] = $item['text'];
            }
        }
        
        return implode("\n\n", $textParts);
    }

    /**
     * Get metadata from annotation data
     * 
     * @param array $data The annotation data
     * @return array Metadata (title, name, source, fetched_at, etc.)
     */
    public function getMetadata(array $data): array
    {
        $metadata = [];
        
        if (isset($data['file'])) {
            $metadata['name'] = $data['file']['name'] ?? null;
            $metadata['hash'] = $data['file']['hash'] ?? null;
        }
        
        if (isset($data['url'])) {
            $metadata['source'] = $data['url']['source'] ?? null;
            $metadata['title'] = $data['url']['title'] ?? null;
            $metadata['description'] = $data['url']['description'] ?? null;
            $metadata['fetched_at'] = $data['url']['fetched_at'] ?? null;
            $metadata['expires_at'] = $data['url']['expires_at'] ?? null;
            $metadata['hash'] = $data['url']['hash'] ?? null;
        }
        
        return array_filter($metadata);
    }

    /**
     * Save annotations from AI response to .anno files
     * 
     * Handles file annotations with rich content (text, images).
     * For PDF files, saves to /annotations/pdf/{slug-filename}/
     * For other files, saves to /annotations/{slug-filename}/
     * 
     * @param array $annotations Array of annotation objects from AI response
     * @param string $projectId Project ID (default: 'general')
     * @return array Array of created ProjectFile objects
     */
    public function saveAnnotations(array $annotations, string $projectId = 'general'): array
    {
        $newFiles = [];

        foreach ($annotations as $annotation) {
            // Skip non-file annotations
            if (!isset($annotation['type']) || $annotation['type'] !== 'file') {
                continue;
            }
            
            // Skip annotations without file name
            if (!isset($annotation['file']) || !isset($annotation['file']['name'])) {
                $this->logger->warning('saveAnnotations(): Annotation file name not found');
                continue;
            }

            $fileName = $annotation['file']['name'];
            
            // Check for PDF file and set path accordingly
            if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'pdf') {
                $filePath = '/annotations/pdf/' . $this->slugger->slug($fileName);
            } else {
                $filePath = '/annotations/' . $this->slugger->slug($fileName);
            }

            // Set annotation filename and create file
            $annoFileName = $fileName . '.anno';
            try {
                $newFile = $this->projectFileService->createFile($projectId, $filePath, $annoFileName, json_encode($annotation));
                $newFiles[] = $newFile;
            } catch (\Throwable $th) {
                $this->logger->error('saveAnnotations(): Error saving annotation file ' . $annoFileName . ': ' . $th->getMessage());
            }

            // Check all annotation.file.content for `image_url` data
            if (isset($annotation['file']['content']) && is_array($annotation['file']['content'])) {
                $i = 0;
                foreach ($annotation['file']['content'] as $content) {
                    // Check if content has image_url
                    $imageDataContent = isset($content['image_url']) && isset($content['image_url']['url']) ? $content['image_url']['url'] : null;
                    if ($imageDataContent) {
                        // Create image file
                        $imgFileName = $fileName . '.img-' . $i . '.jpg';
                        try {
                            $i++;
                            $newImageFile = $this->projectFileService->createFile($projectId, $filePath, $imgFileName, $imageDataContent);
                            $newFiles[] = $newImageFile;
                        } catch (\Throwable $th) {
                            $this->logger->error('saveAnnotations(): Error saving annotation image file ' . $imgFileName . ': ' . $th->getMessage());
                        }
                    }
                }
            }
        }

        return $newFiles;
    }

    /**
     * Build URL annotation data structure
     * 
     * @param string $url The source URL
     * @param string $title Page title
     * @param string $description Page description
     * @param string $textContent The extracted text content
     * @param int $cacheHours Hours until expiration (default: 24)
     * @return array The annotation data structure
     */
    public function buildUrlAnnotation(
        string $url,
        string $title,
        string $description,
        string $textContent,
        int $cacheHours = self::DEFAULT_URL_CACHE_HOURS
    ): array {
        $now = new \DateTime();
        $expiresAt = (clone $now)->modify("+{$cacheHours} hours");
        
        return [
            'type' => 'url',
            'url' => [
                'hash' => hash('sha256', $url),
                'source' => $url,
                'title' => $title,
                'description' => $description,
                'fetched_at' => $now->format('c'),
                'expires_at' => $expiresAt->format('c'),
                'content' => [
                    ['type' => 'text', 'text' => $textContent]
                ]
            ]
        ];
    }

    /**
     * Validate that annotation data matches expected type
     * 
     * @param array $data The annotation data
     * @param string $type Expected type
     * @return bool
     */
    private function validateAnnotationType(array $data, string $type): bool
    {
        switch ($type) {
            case self::TYPE_PDF:
                // PDF type should have 'file' key or type='file'
                return isset($data['file']) || (isset($data['type']) && $data['type'] === 'file');
                
            case self::TYPE_URL:
                // URL type should have type='url'
                return isset($data['type']) && $data['type'] === 'url';
                
            default:
                return false;
        }
    }

    /**
     * Verify PDF annotation matches the expected file
     * 
     * @param array $data The annotation data
     * @param string $filename The expected filename
     * @return bool
     */
    public function verifyPdfAnnotation(array $data, string $filename): bool
    {
        return isset($data['file']['name']) && $data['file']['name'] === $filename;
    }

    /**
     * Replace PDF base64 data with annotations if available (uses AnnoService)
     * This saves AI processing costs by using pre-extracted text instead of raw PDF data
     * 
     * @param array $message The message array with content
     * @param string $projectId The project ID (default: 'general')
     * @return array The message with PDF data replaced by annotations where available
     */
    public function updatePDFannotationsInMessage(array $message, string $projectId = 'general'): array
    {
        if (!isset($message['content']) || !is_array($message['content'])) {
            return $message;
        }
        
        $updatedContent = [];
        
        foreach ($message['content'] as $contentItem) {
            $contentItemAdded = false;
            
            // Check if content is a PDF file
            if (isset($contentItem['type']) && $contentItem['type'] === 'file' && 
                isset($contentItem['file']['filename'])) {
                
                $filename = $contentItem['file']['filename'];
                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                
                // Only process PDF files
                if ($extension === 'pdf') {
                    $annoData = $this->readAnnotation(AnnoService::TYPE_PDF, $filename, $projectId, false);
                    
                    if ($annoData && $this->verifyPdfAnnotation($annoData, $filename)) {
                        // Get the content array from annotation
                        $annotationContent = $annoData['file']['content'] ?? [];
                        
                        if (is_array($annotationContent)) {
                            foreach ($annotationContent as $annotationItem) {
                                $updatedContent[] = $annotationItem;
                            }
                        }
                        
                        $contentItemAdded = true;
                    }
                }
            }
            
            // If content item was not replaced, keep original
            if (!$contentItemAdded) {
                $updatedContent[] = $contentItem;
            }
        }
        
        $message['content'] = $updatedContent;
        return $message;
    }
}
