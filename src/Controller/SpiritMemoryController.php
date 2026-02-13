<?php

namespace App\Controller;

use App\Service\CQMemoryLibraryService;
use App\Service\SpiritService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/spirit')]
#[IsGranted('ROLE_USER')]
class SpiritMemoryController extends AbstractController
{
    public function __construct(
        private readonly SpiritService $spiritService,
        private readonly CQMemoryLibraryService $libraryService
    ) {}

    /**
     * Initialize Spirit's memory infrastructure and return path info
     * Delegates to SpiritService::initSpiritMemory() for all creation logic
     */
    #[Route('/{spiritId}/memory/init', name: 'spirit_memory_init', methods: ['GET'])]
    public function initMemory(string $spiritId): JsonResponse
    {
        $spirit = $this->spiritService->findById($spiritId);
        
        if (!$spirit) {
            return new JsonResponse(['error' => 'Spirit not found'], 404);
        }

        try {
            $memoryInfo = $this->spiritService->initSpiritMemory($spirit);

            return new JsonResponse([
                'success' => true,
                'projectId' => $memoryInfo['projectId'],
                'memoryPath' => $memoryInfo['memoryPath'],
                'packsPath' => $memoryInfo['packsPath'],
                'rootLibraryName' => $memoryInfo['rootLibraryName'],
                'rootPackName' => $memoryInfo['rootPackName'],
                'hasRootLibrary' => true,
                'spiritNameSlug' => $memoryInfo['spiritNameSlug']
            ]);

        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Failed to initialize Spirit memory: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get merged graph data from all packs in Spirit's main library
     * Used by Spirit profile page 3D preview to show the full memory graph
     */
    #[Route('/{spiritId}/memory/library-graph', name: 'spirit_memory_library_graph', methods: ['GET'])]
    public function getLibraryGraph(string $spiritId): JsonResponse
    {
        $spirit = $this->spiritService->findById($spiritId);
        
        if (!$spirit) {
            return new JsonResponse(['error' => 'Spirit not found'], 404);
        }

        try {
            $memoryInfo = $this->spiritService->initSpiritMemory($spirit);

            $graphData = $this->libraryService->getLibraryGraphData(
                $memoryInfo['projectId'],
                $memoryInfo['memoryPath'],
                $memoryInfo['rootLibraryName']
            );

            return new JsonResponse($graphData);

        } catch (\Exception $e) {
            return new JsonResponse([
                'nodes' => [],
                'edges' => [],
                'stats' => ['totalNodes' => 0, 'totalRelationships' => 0, 'packCount' => 0],
                'packs' => []
            ]);
        }
    }
}
