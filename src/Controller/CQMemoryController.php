<?php

namespace App\Controller;

use App\Service\SpiritService;
use App\Service\ProjectFileService;
use App\Service\AnnoService;
use App\Service\AIToolMemoryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/memory')]
#[IsGranted('ROLE_USER')]
class CQMemoryController extends AbstractController
{
    public function __construct(
        private readonly SpiritService $spiritService,
        private readonly ProjectFileService $projectFileService,
        private readonly AnnoService $annoService,
        private readonly AIToolMemoryService $aiToolMemoryService
    ) {}

    #[Route('', name: 'cq_memory_explorer', methods: ['GET'])]
    public function memoryExplorer(Request $request): Response
    {
        $spiritId = $request->query->get('spirit');
        $libPath = $request->query->get('lib');
        $spirit = null;

        if ($spiritId) {
            $spirit = $this->spiritService->findById($spiritId);
            if (!$spirit) {
                throw $this->createNotFoundException('Spirit not found');
            }
        }

        return $this->render('cq-memory/explorer.html.twig', [
            'spirit' => $spirit,
            'spiritId' => $spiritId,
            'libPath' => $libPath
        ]);
    }

    #[Route('/source', name: 'cq_memory_source_api', methods: ['POST'])]
    public function getSourceContent(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $sourceRef = $data['source_ref'] ?? null;
        $sourceRange = $data['source_range'] ?? null;

        if (!$sourceRef) {
            return new JsonResponse(['error' => 'source_ref is required'], 400);
        }

        try {
            // Parse source_ref format: {projectId}:{filePath}:{fileName}
            // Example: general:/spirit/Bob/memory:conversations.md
            $parts = explode(':', $sourceRef);
            if (count($parts) < 3) {
                return new JsonResponse(['error' => 'Invalid source_ref format'], 400);
            }
            
            $projectId = $parts[0];
            $filePath = $parts[1];
            $fileName = $parts[2];

            // Get file using findByPathAndName
            $file = $this->projectFileService->findByPathAndName($projectId, $filePath, $fileName);
            if (!$file) {
                return new JsonResponse(['error' => 'Source file not found'], 404);
            }

            // Check if this is a PDF file - use AnnoService to get parsed content
            $isPdf = str_ends_with(strtolower($fileName), '.pdf');
            if ($isPdf) {
                $annoData = $this->annoService->readAnnotation(AnnoService::TYPE_PDF, $fileName, $projectId, false);
                if ($annoData) {
                    $content = $this->annoService->getTextContent($annoData);
                } else {
                    return new JsonResponse(['error' => 'PDF annotation not found - file needs to be processed first'], 404);
                }
            } else {
                // Get content using getFileContent
                $content = $this->projectFileService->getFileContent($file->getId());
                
                // If it's JSON (like .anno files), use AnnoService to extract text
                if (str_ends_with($fileName, '.anno')) {
                    $annoData = json_decode($content, true);
                    if ($annoData) {
                        $content = $this->annoService->getTextContent($annoData);
                    }
                }
            }

            // Apply line range if specified (format: "start:end" like "1:213")
            if ($sourceRange && preg_match('/^(\d+):(\d+)$/', $sourceRange, $rangeMatches)) {
                $startLine = (int)$rangeMatches[1];
                $endLine = (int)$rangeMatches[2];
                
                $lines = explode("\n", $content);
                $startLine = max(1, $startLine) - 1; // Convert to 0-indexed
                $endLine = min(count($lines), $endLine);
                
                $content = implode("\n", array_slice($lines, $startLine, $endLine - $startLine));
            }

            // Use fileName from parsed source_ref
            $filename = $fileName;

            return new JsonResponse([
                'content' => $content,
                'filename' => $filename,
                'source_ref' => $sourceRef,
                'source_range' => $sourceRange
            ]);

        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Failed to read source: ' . $e->getMessage()], 500);
        }
    }

    #[Route('/extract/{spiritId}', name: 'cq_memory_extract_manual_api', methods: ['POST'])]
    public function extractManual(string $spiritId, Request $request): JsonResponse
    {
        $spirit = $this->spiritService->findById($spiritId);
        
        if (!$spirit) {
            return new JsonResponse(['error' => 'Spirit not found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        
        if (!$data) {
            return new JsonResponse(['error' => 'Invalid JSON payload'], 400);
        }

        try {
            // Prepare arguments for memoryExtract AI Tool
            $arguments = [
                'spiritId' => $spiritId,
                'sourceType' => $data['sourceType'] ?? 'file',
                'sourceRef' => $data['sourceRef'] ?? null,
                'content' => $data['content'] ?? null,
                'maxDepth' => $data['maxDepth'] ?? 3,
                'documentTitle' => $data['documentTitle'] ?? null
            ];

            // Validate required fields
            if (!$arguments['sourceRef'] && !$arguments['content']) {
                return new JsonResponse(['error' => 'Either sourceRef or content is required'], 400);
            }

            // Call memoryExtract AI Tool
            $result = $this->aiToolMemoryService->memoryExtract($arguments);

            return new JsonResponse($result);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Extraction failed: ' . $e->getMessage()
            ], 500);
        }
    }

}
