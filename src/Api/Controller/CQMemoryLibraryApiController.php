<?php

namespace App\Api\Controller;

use App\Service\CQMemoryLibraryService;
use App\Service\CQMemoryPackService;
use App\Service\ProjectFileService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API Controller for CQ Memory Library (.cqmlib) operations
 * 
 * Memory Libraries are JSON files that group multiple Memory Packs.
 * They are standalone and not bound to any Spirit.
 * 
 * All endpoints accept projectId + relative path instead of absolute paths.
 */
#[Route('/api/memory')]
#[IsGranted('ROLE_USER')]
class CQMemoryLibraryApiController extends AbstractController
{
    public function __construct(
        private readonly CQMemoryLibraryService $libraryService,
        private readonly CQMemoryPackService $packService,
        private readonly ProjectFileService $projectFileService
    ) {}

    /**
     * Open a library and get its contents
     */
    #[Route('/library/open', name: 'api_memory_library_open', methods: ['POST'])]
    public function openLibrary(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $projectId = $data['projectId'] ?? 'general';
        $path = $data['path'] ?? null;
        $name = $data['name'] ?? null;

        if (!$path || !$name) {
            return new JsonResponse(['error' => 'path and name are required'], 400);
        }

        try {
            // Sync first to prune stale packs and refresh stats
            $library = $this->libraryService->syncPackStats($projectId, $path, $name);

            return new JsonResponse([
                'success' => true,
                'library' => $library,
                'projectId' => $projectId,
                'path' => $path,
                'name' => $name
            ]);

        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Failed to open library: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Create a new library
     */
    #[Route('/library/create', name: 'api_memory_library_create', methods: ['POST'])]
    public function createLibrary(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $projectId = $data['projectId'] ?? 'general';
        $path = $data['path'] ?? null;
        $name = $data['name'] ?? null;
        $description = $data['description'] ?? '';

        if (!$path || !$name) {
            return new JsonResponse(['error' => 'path and name are required'], 400);
        }

        try {
            // Sanitize name for filename
            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '-', $name);

            // Create the library
            $library = $this->libraryService->createLibrary($projectId, $path, $safeName, [
                'name' => $name,
                'description' => $description
            ]);

            return new JsonResponse([
                'success' => true,
                'projectId' => $projectId,
                'path' => $path,
                'name' => $safeName . '.' . CQMemoryLibraryService::FILE_EXTENSION,
                'displayName' => $name,
                'message' => 'Library created successfully'
            ]);

        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Failed to create library: ' . $e->getMessage()], 500);
        }
    }

    /**
     * List libraries in a directory
     */
    #[Route('/library/list', name: 'api_memory_library_list', methods: ['POST'])]
    public function listLibraries(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $projectId = $data['projectId'] ?? 'general';
        $path = $data['path'] ?? null;

        if (!$path) {
            return new JsonResponse(['error' => 'path is required'], 400);
        }

        try {
            $libraries = $this->libraryService->findLibrariesInDirectory($projectId, $path);

            return new JsonResponse([
                'success' => true,
                'libraries' => $libraries,
                'projectId' => $projectId,
                'path' => $path
            ]);

        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Failed to list libraries: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Add a pack to a library
     */
    #[Route('/library/add-pack', name: 'api_memory_library_add_pack', methods: ['POST'])]
    public function addPackToLibrary(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $projectId = $data['projectId'] ?? 'general';
        $libraryPath = $data['libraryPath'] ?? null;
        $libraryName = $data['libraryName'] ?? null;
        $packPath = $data['packPath'] ?? null;
        $packName = $data['packName'] ?? null;

        if (!$libraryPath || !$libraryName || !$packPath || !$packName) {
            return new JsonResponse(['error' => 'libraryPath, libraryName, packPath and packName are required'], 400);
        }

        try {
            $library = $this->libraryService->addPackToLibrary($projectId, $libraryPath, $libraryName, $packPath, $packName);

            return new JsonResponse([
                'success' => true,
                'library' => $library,
                'message' => 'Pack added to library successfully'
            ]);

        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Failed to add pack: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Remove a pack from a library
     */
    #[Route('/library/remove-pack', name: 'api_memory_library_remove_pack', methods: ['POST'])]
    public function removePackFromLibrary(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $projectId = $data['projectId'] ?? 'general';
        $libraryPath = $data['libraryPath'] ?? null;
        $libraryName = $data['libraryName'] ?? null;
        $packPath = $data['packPath'] ?? null;

        if (!$libraryPath || !$libraryName || !$packPath) {
            return new JsonResponse(['error' => 'libraryPath, libraryName and packPath are required'], 400);
        }

        try {
            $library = $this->libraryService->removePackFromLibrary($projectId, $libraryPath, $libraryName, $packPath);

            return new JsonResponse([
                'success' => true,
                'library' => $library,
                'message' => 'Pack removed from library successfully'
            ]);

        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Failed to remove pack: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get combined graph data from all packs in a library
     */
    #[Route('/library/graph', name: 'api_memory_library_graph', methods: ['POST'])]
    public function getLibraryGraph(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $projectId = $data['projectId'] ?? 'general';
        $path = $data['path'] ?? null;
        $name = $data['name'] ?? null;

        if (!$path || !$name) {
            return new JsonResponse(['error' => 'path and name are required'], 400);
        }

        try {
            $graphData = $this->libraryService->getLibraryGraphData($projectId, $path, $name);

            return new JsonResponse([
                'success' => true,
                'projectId' => $projectId,
                'path' => $path,
                'name' => $name,
                'nodes' => $graphData['nodes'],
                'edges' => $graphData['edges'],
                'packs' => $graphData['packs'],
                'stats' => $graphData['stats']
            ]);

        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Failed to get library graph: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Delete a library file
     */
    #[Route('/library/delete', name: 'api_memory_library_delete', methods: ['POST'])]
    public function deleteLibrary(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $projectId = $data['projectId'] ?? 'general';
        $path = $data['path'] ?? null;
        $name = $data['name'] ?? null;

        if (!$path || !$name) {
            return new JsonResponse(['error' => 'path and name are required'], 400);
        }

        try {
            $file = $this->projectFileService->findByPathAndName($projectId, $path, $name);
            
            if (!$file) {
                return new JsonResponse(['error' => 'Library not found'], 404);
            }
            
            $this->projectFileService->delete($file->getId());

            return new JsonResponse([
                'success' => true,
                'message' => 'Library deleted successfully'
            ]);

        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Failed to delete library: ' . $e->getMessage()], 500);
        }
    }
}
