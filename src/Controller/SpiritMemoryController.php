<?php

namespace App\Controller;

use App\Service\SpiritService;
use App\Service\ProjectFileService;
use App\Service\CQMemoryLibraryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/spirit')]
#[IsGranted('ROLE_USER')]
class SpiritMemoryController extends AbstractController
{
    public function __construct(
        private readonly SpiritService $spiritService,
        private readonly ProjectFileService $projectFileService,
        private readonly CQMemoryLibraryService $libraryService,
        private readonly SluggerInterface $slugger
    ) {}

    /**
     * Redirect legacy Spirit Memory Explorer URL to new CQ Memory Explorer
     */
    #[Route('/{spiritId}/memory', name: 'spirit_memory_explorer', methods: ['GET'])]
    public function memoryExplorerRedirect(string $spiritId): Response
    {
        return $this->redirectToRoute('cq_memory_explorer', ['spirit' => $spiritId], 301);
    }

    /**
     * Get Spirit's memory directory path and initialize if needed
     * This provides the path info for the generic /api/memory endpoints
     */
    #[Route('/{spiritId}/memory/path', name: 'spirit_memory_path', methods: ['GET'])]
    public function getMemoryPath(string $spiritId): JsonResponse
    {
        $spirit = $this->spiritService->findById($spiritId);
        
        if (!$spirit) {
            return new JsonResponse(['error' => 'Spirit not found'], 404);
        }

        try {
            $projectId = 'general';
            $spiritNameSlug = (string) $this->slugger->slug($spirit->getName());

            // Get relative paths
            $memoryPath = $this->libraryService->getSpiritMemoryPath($spiritNameSlug);
            $packsPath = $memoryPath . '/packs';
            $rootLibraryName = $spiritNameSlug . '.' . CQMemoryLibraryService::FILE_EXTENSION;

            // Ensure directories exist via ProjectFileService
            $spiritDir = '/spirit/' . $spiritNameSlug;
            
            // Create directory structure if it doesn't exist
            if (!$this->projectFileService->findByPathAndName($projectId, '/', 'spirit')) {
                $this->projectFileService->createDirectory($projectId, '/', 'spirit');
            }
            if (!$this->projectFileService->findByPathAndName($projectId, '/spirit', $spiritNameSlug)) {
                $this->projectFileService->createDirectory($projectId, '/spirit', $spiritNameSlug);
            }
            if (!$this->projectFileService->findByPathAndName($projectId, $spiritDir, 'memory')) {
                $this->projectFileService->createDirectory($projectId, $spiritDir, 'memory');
            }
            if (!$this->projectFileService->findByPathAndName($projectId, $memoryPath, 'packs')) {
                $this->projectFileService->createDirectory($projectId, $memoryPath, 'packs');
            }

            // Check if root library exists
            $hasRootLibrary = $this->projectFileService->findByPathAndName($projectId, $memoryPath, $rootLibraryName) !== null;

            return new JsonResponse([
                'success' => true,
                'projectId' => $projectId,
                'memoryPath' => $memoryPath,
                'packsPath' => $packsPath,
                'rootLibraryName' => $rootLibraryName,
                'hasRootLibrary' => $hasRootLibrary,
                'spiritNameSlug' => $spiritNameSlug
            ]);

        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Failed to get memory path: ' . $e->getMessage()], 500);
        }
    }
}
