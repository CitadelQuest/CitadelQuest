<?php

namespace App\Controller;

use App\Service\CQMemoryLibraryService;
use App\Service\ProjectService;
use App\Service\SpiritService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/spirit')]
#[IsGranted('ROLE_USER')]
class SpiritMemoryController extends AbstractController
{
    public function __construct(
        private readonly SpiritService $spiritService,
        private readonly CQMemoryLibraryService $libraryService,
        private readonly ProjectService $projectService
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

    /**
     * Get packs currently in Spirit's main memory library
     */
    #[Route('/{spiritId}/memory/library-packs', name: 'spirit_memory_library_packs', methods: ['GET'])]
    public function getLibraryPacks(string $spiritId): JsonResponse
    {
        $spirit = $this->spiritService->findById($spiritId);
        if (!$spirit) {
            return new JsonResponse(['error' => 'Spirit not found'], 404);
        }

        try {
            $memoryInfo = $this->spiritService->initSpiritMemory($spirit);
            $library = $this->libraryService->syncPackStats(
                $memoryInfo['projectId'],
                $memoryInfo['memoryPath'],
                $memoryInfo['rootLibraryName']
            );

            $packs = [];
            foreach ($library['packs'] ?? [] as $packEntry) {
                $packProjectId = $packEntry['projectId'] ?? $memoryInfo['projectId'];
                $project = $this->projectService->findById($packProjectId);
                $packEntry['projectId'] = $packProjectId;
                $packEntry['projectTitle'] = $project ? $project->getTitle() : $packProjectId;
                $packs[] = $packEntry;
            }

            return new JsonResponse([
                'success' => true,
                'packs' => $packs
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Failed to load library packs: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get all available CQ Memory Packs across all projects assigned to the Spirit
     */
    #[Route('/{spiritId}/memory/available-packs', name: 'spirit_memory_available_packs', methods: ['GET'])]
    public function getAvailablePacks(string $spiritId): JsonResponse
    {
        $spirit = $this->spiritService->findById($spiritId);
        if (!$spirit) {
            return new JsonResponse(['error' => 'Spirit not found'], 404);
        }

        try {
            $memoryInfo = $this->spiritService->initSpiritMemory($spirit);
            $activeProjectId = $memoryInfo['projectId'];

            $library = $this->libraryService->loadLibrary(
                $activeProjectId,
                $memoryInfo['memoryPath'],
                $memoryInfo['rootLibraryName']
            );
            $libraryPackKeys = array_map(
                fn($p) => ($p['projectId'] ?? $activeProjectId) . ':' . $p['path'],
                $library['packs'] ?? []
            );

            // Include the active project first, then every other project assigned to this Spirit.
            $assignedProjectIds = array_values(array_unique(array_merge(
                [$activeProjectId],
                $this->projectService->getProjectIdsForSpirit($spiritId)
            )));

            $allPacks = [];
            foreach ($assignedProjectIds as $pid) {
                $project = $this->projectService->findById($pid);
                $projectTitle = $project ? $project->getTitle() : $pid;

                $projectPacks = $this->libraryService->findPacksInDirectory($pid, '/', true);
                foreach ($projectPacks as $pack) {
                    $packKey = $pid . ':' . $pack['path'] . '/' . $pack['name'];
                    $pack['projectId'] = $pid;
                    $pack['projectTitle'] = $projectTitle;
                    $pack['inLibrary'] = in_array($packKey, $libraryPackKeys, true);
                    $allPacks[] = $pack;
                }
            }

            return new JsonResponse([
                'success' => true,
                'packs' => $allPacks
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Failed to load available packs: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Add a pack to Spirit's main memory library
     */
    #[Route('/{spiritId}/memory/library/add-pack', name: 'spirit_memory_library_add_pack', methods: ['POST'])]
    public function addPackToLibrary(Request $request, string $spiritId): JsonResponse
    {
        $spirit = $this->spiritService->findById($spiritId);
        if (!$spirit) {
            return new JsonResponse(['error' => 'Spirit not found'], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $packPath = $data['packPath'] ?? null;
        $packName = $data['packName'] ?? null;
        $packProjectId = $data['packProjectId'] ?? null;

        if (!$packPath || !$packName) {
            return new JsonResponse(['error' => 'packPath and packName are required'], 400);
        }

        try {
            $memoryInfo = $this->spiritService->initSpiritMemory($spirit);
            $options = [];
            if ($packProjectId) {
                $options['projectId'] = $packProjectId;
            }
            $library = $this->libraryService->addPackToLibrary(
                $memoryInfo['projectId'],
                $memoryInfo['memoryPath'],
                $memoryInfo['rootLibraryName'],
                $packPath,
                $packName,
                $options
            );

            return new JsonResponse([
                'success' => true,
                'packs' => $library['packs'] ?? [],
                'message' => 'Pack added to library'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Failed to add pack: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Remove a pack from Spirit's main memory library
     */
    #[Route('/{spiritId}/memory/library/remove-pack', name: 'spirit_memory_library_remove_pack', methods: ['POST'])]
    public function removePackFromLibrary(Request $request, string $spiritId): JsonResponse
    {
        $spirit = $this->spiritService->findById($spiritId);
        if (!$spirit) {
            return new JsonResponse(['error' => 'Spirit not found'], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $packPath = $data['packPath'] ?? null;
        $packProjectId = $data['packProjectId'] ?? null;

        if (!$packPath) {
            return new JsonResponse(['error' => 'packPath is required'], 400);
        }

        try {
            $memoryInfo = $this->spiritService->initSpiritMemory($spirit);
            $library = $this->libraryService->removePackFromLibrary(
                $memoryInfo['projectId'],
                $memoryInfo['memoryPath'],
                $memoryInfo['rootLibraryName'],
                $packPath,
                $packProjectId
            );

            return new JsonResponse([
                'success' => true,
                'packs' => $library['packs'] ?? [],
                'message' => 'Pack removed from library'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Failed to remove pack: ' . $e->getMessage()], 500);
        }
    }
}
