<?php

namespace App\Api\Controller;

use App\Service\CQMemoryPackService;
use App\Service\CQMemoryLibraryService;
use App\Service\ProjectFileService;
use App\Service\AIToolMemoryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API Controller for CQ Memory Pack (.cqmpack) operations
 * 
 * Memory Packs are standalone SQLite databases - not bound to any Spirit.
 * They can be used by Spirits, shared, or accessed independently.
 * 
 * All endpoints accept projectId + relative path instead of absolute paths.
 */
#[Route('/api/memory')]
#[IsGranted('ROLE_USER')]
class CQMemoryPackApiController extends AbstractController
{
    public function __construct(
        private readonly CQMemoryPackService $packService,
        private readonly CQMemoryLibraryService $libraryService,
        private readonly ProjectFileService $projectFileService,
        private readonly AIToolMemoryService $aiToolMemoryService
    ) {}

    /**
     * List packs in a directory
     */
    #[Route('/pack/list', name: 'api_memory_pack_list', methods: ['POST'])]
    public function listPacks(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $projectId = $data['projectId'] ?? 'general';
        $path = $data['path'] ?? null;

        if (!$path) {
            return new JsonResponse(['error' => 'path is required'], 400);
        }

        try {
            $packs = $this->libraryService->findPacksInDirectory($projectId, $path);
            $libraries = $this->libraryService->findLibrariesInDirectory($projectId, $path);

            return new JsonResponse([
                'success' => true,
                'packs' => $packs,
                'libraries' => $libraries,
                'projectId' => $projectId,
                'path' => $path,
            ]);

        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Failed to list packs: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Open a pack and get its graph data
     */
    #[Route('/pack/open', name: 'api_memory_pack_open', methods: ['POST'])]
    public function openPack(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $projectId = $data['projectId'] ?? 'general';
        $path = $data['path'] ?? null;
        $name = $data['name'] ?? null;

        if (!$path || !$name) {
            return new JsonResponse(['error' => 'path and name are required'], 400);
        }

        try {
            $this->packService->open($projectId, $path, $name);
            $graphData = $this->packService->getGraphData();
            $metadata = $this->packService->getAllMetadata();
            $this->packService->close();

            return new JsonResponse([
                'success' => true,
                'projectId' => $projectId,
                'path' => $path,
                'name' => $name,
                'metadata' => $metadata,
                'nodes' => $graphData['nodes'],
                'edges' => $graphData['edges'],
                'stats' => $graphData['stats']
            ]);

        } catch (\Exception $e) {
            $this->packService->close();
            return new JsonResponse(['error' => 'Failed to open pack: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Create a new memory pack
     */
    #[Route('/pack/create', name: 'api_memory_pack_create', methods: ['POST'])]
    public function createPack(Request $request): JsonResponse
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

            // Create the pack
            $this->packService->create($projectId, $path, $safeName, [
                'name' => $name,
                'description' => $description
            ]);
            $this->packService->close();

            return new JsonResponse([
                'success' => true,
                'projectId' => $projectId,
                'path' => $path,
                'name' => $safeName . '.' . CQMemoryPackService::FILE_EXTENSION,
                'displayName' => $name,
                'message' => 'Memory pack created successfully'
            ]);

        } catch (\Exception $e) {
            $this->packService->close();
            return new JsonResponse(['error' => 'Failed to create pack: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get pack metadata
     */
    #[Route('/pack/metadata', name: 'api_memory_pack_metadata', methods: ['POST'])]
    public function getPackMetadata(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $projectId = $data['projectId'] ?? 'general';
        $path = $data['path'] ?? null;
        $name = $data['name'] ?? null;

        if (!$path || !$name) {
            return new JsonResponse(['error' => 'path and name are required'], 400);
        }

        try {
            $this->packService->open($projectId, $path, $name);
            $metadata = $this->packService->getAllMetadata();
            $stats = $this->packService->getStats();
            $aiUsage = $this->packService->getAiUsageSummary();
            $this->packService->close();

            return new JsonResponse([
                'success' => true,
                'projectId' => $projectId,
                'path' => $path,
                'name' => $name,
                'metadata' => $metadata,
                'stats' => $stats,
                'aiUsage' => $aiUsage
            ]);

        } catch (\Exception $e) {
            $this->packService->close();
            return new JsonResponse(['error' => 'Failed to get metadata: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get delta (new nodes/edges since timestamp)
     */
    #[Route('/pack/delta', name: 'api_memory_pack_delta', methods: ['POST'])]
    public function getPackDelta(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $projectId = $data['projectId'] ?? 'general';
        $path = $data['path'] ?? null;
        $name = $data['name'] ?? null;
        $since = $data['since'] ?? null;

        if (!$path || !$name || !$since) {
            return new JsonResponse(['error' => 'path, name and since are required'], 400);
        }

        try {
            $this->packService->open($projectId, $path, $name);
            
            $sinceDateTime = new \DateTime($since);
            $sinceFormatted = $sinceDateTime->format('Y-m-d H:i:s');
            
            $deltaData = $this->packService->getGraphDelta($sinceFormatted);
            $this->packService->close();

            $deltaData['timestamp'] = (new \DateTime())->format('c');

            return new JsonResponse($deltaData);

        } catch (\Exception $e) {
            $this->packService->close();
            return new JsonResponse(['error' => 'Failed to get delta: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Store a memory node in a pack
     */
    #[Route('/pack/node/store', name: 'api_memory_pack_node_store', methods: ['POST'])]
    public function storeNode(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $projectId = $data['projectId'] ?? 'general';
        $path = $data['path'] ?? null;
        $name = $data['name'] ?? null;
        $content = $data['content'] ?? null;
        $category = $data['category'] ?? 'knowledge';
        $importance = $data['importance'] ?? 0.5;
        $summary = $data['summary'] ?? null;
        $tags = $data['tags'] ?? [];
        $sourceType = $data['sourceType'] ?? null;
        $sourceRef = $data['sourceRef'] ?? null;

        if (!$path || !$name || !$content) {
            return new JsonResponse(['error' => 'path, name and content are required'], 400);
        }

        try {
            $this->packService->open($projectId, $path, $name);
            
            $node = $this->packService->storeNode(
                $content,
                $category,
                $importance,
                $summary,
                $sourceType,
                $sourceRef,
                $tags
            );
            
            $this->packService->close();

            return new JsonResponse([
                'success' => true,
                'nodeId' => $node->getId(),
                'message' => 'Node stored successfully'
            ]);

        } catch (\Exception $e) {
            $this->packService->close();
            return new JsonResponse(['error' => 'Failed to store node: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Delete a pack file
     */
    #[Route('/pack/delete', name: 'api_memory_pack_delete', methods: ['POST'])]
    public function deletePack(Request $request): JsonResponse
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
                return new JsonResponse(['error' => 'Pack file not found'], 404);
            }
            
            $this->projectFileService->delete($file->getId());

            // Remove deleted pack from any libraries that reference it
            $packRelPath = $path . '/' . $name;
            try {
                $libraries = $this->libraryService->findLibrariesInDirectory($projectId, '/');
                foreach ($libraries as $libInfo) {
                    try {
                        $library = $this->libraryService->loadLibrary($projectId, $libInfo['path'], $libInfo['name']);
                        foreach ($library['packs'] as $pack) {
                            if ($pack['path'] === $packRelPath) {
                                $this->libraryService->removePackFromLibrary($projectId, $libInfo['path'], $libInfo['name'], $packRelPath);
                                break;
                            }
                        }
                    } catch (\Exception $e) {
                        // Skip invalid libraries
                    }
                }
            } catch (\Exception $e) {
                // Non-blocking: pack is deleted, library sync is best-effort
            }

            return new JsonResponse([
                'success' => true,
                'message' => 'Pack deleted successfully'
            ]);

        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Failed to delete pack: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Extract memories from content and store in pack
     * 
     * This is the pack-focused extraction endpoint - no Spirit context required.
     * All extracted memories and jobs are stored in the target pack file.
     */
    #[Route('/pack/extract', name: 'api_memory_pack_extract', methods: ['POST'])]
    public function extractManual(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (!$data) {
            return new JsonResponse(['error' => 'Invalid JSON payload'], 400);
        }

        // Target pack is required
        $targetPack = $data['targetPack'] ?? null;
        if (!$targetPack || !isset($targetPack['projectId']) || !isset($targetPack['path']) || !isset($targetPack['name'])) {
            return new JsonResponse(['error' => 'targetPack with projectId, path, and name is required'], 400);
        }

        // Validate source
        $sourceType = $data['sourceType'] ?? null;
        $sourceRef = $data['sourceRef'] ?? null;
        $content = $data['content'] ?? null;

        if (!$sourceRef && !$content) {
            return new JsonResponse(['error' => 'Either sourceRef or content is required'], 400);
        }

        // Release session lock early — extraction involves file I/O + pack DB writes
        $request->getSession()->save();

        try {
            // Prepare arguments for pack-based extraction
            $arguments = [
                'targetPack' => $targetPack,
                'sourceType' => $sourceType ?? 'document',
                'sourceRef' => $sourceRef,
                'content' => $content,
                'maxDepth' => $data['maxDepth'] ?? 3,
                'documentTitle' => $data['documentTitle'] ?? null,
                'skipAnalysis' => (bool)($data['skipAnalysis'] ?? false)
            ];

            // Call pack-based extraction
            $result = $this->aiToolMemoryService->memoryExtractToPack($arguments);

            return new JsonResponse($result);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Extraction failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Start a full-pack relationship analysis job (manual trigger from GUI)
     * Generates all intra-doc + cross-doc candidate pairs for the entire pack
     */
    #[Route('/pack/analyze', name: 'api_memory_pack_analyze', methods: ['POST'])]
    public function analyzeManual(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $projectId = $data['projectId'] ?? 'general';
        $path = $data['path'] ?? null;
        $name = $data['name'] ?? null;

        if (!$path || !$name) {
            return new JsonResponse(['error' => 'path and name are required'], 400);
        }

        $request->getSession()->save();

        try {
            $targetPack = [
                'projectId' => $projectId,
                'path' => $path,
                'name' => $name
            ];

            $job = $this->aiToolMemoryService->createFullPackAnalysisJob($targetPack);

            return new JsonResponse([
                'success' => true,
                'async' => true,
                'jobId' => $job->getId(),
                'initialProgress' => [
                    'progress' => $job->getProgress(),
                    'totalSteps' => $job->getTotalSteps(),
                    'type' => $job->getType()
                ]
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to start analysis: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Start a selective relationship analysis job for chosen root nodes only
     * Pair generation: selected×selected intra-doc + cross-doc root gate
     */
    #[Route('/pack/analyze-selected', name: 'api_memory_pack_analyze_selected', methods: ['POST'])]
    public function analyzeSelected(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $projectId = $data['projectId'] ?? 'general';
        $path = $data['path'] ?? null;
        $name = $data['name'] ?? null;
        $rootNodeIds = $data['rootNodeIds'] ?? [];

        if (!$path || !$name) {
            return new JsonResponse(['error' => 'path and name are required'], 400);
        }
        if (empty($rootNodeIds) || !is_array($rootNodeIds)) {
            return new JsonResponse(['error' => 'rootNodeIds array is required'], 400);
        }

        $request->getSession()->save();

        try {
            $targetPack = [
                'projectId' => $projectId,
                'path' => $path,
                'name' => $name
            ];

            $job = $this->aiToolMemoryService->createSelectedRootsAnalysisJob($targetPack, $rootNodeIds);

            return new JsonResponse([
                'success' => true,
                'async' => true,
                'jobId' => $job->getId(),
                'initialProgress' => [
                    'progress' => $job->getProgress(),
                    'totalSteps' => $job->getTotalSteps(),
                    'type' => $job->getType()
                ]
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to start analysis: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a memory node and its PART_OF children from a pack
     */
    #[Route('/pack/node/delete', name: 'api_memory_pack_node_delete', methods: ['POST'])]
    public function deleteNode(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $projectId = $data['projectId'] ?? 'general';
        $path = $data['path'] ?? null;
        $name = $data['name'] ?? null;
        $nodeId = $data['nodeId'] ?? null;

        if (!$path || !$name || !$nodeId) {
            return new JsonResponse(['error' => 'path, name and nodeId are required'], 400);
        }

        try {
            $this->packService->open($projectId, $path, $name);
            
            // Verify node exists
            $node = $this->packService->findNodeById($nodeId);
            if (!$node) {
                $this->packService->close();
                return new JsonResponse(['error' => 'Node not found'], 404);
            }
            
            $deletedIds = $this->packService->deleteNodeWithChildren($nodeId);
            $this->packService->close();

            // Sync library stats after deletion
            try {
                $this->libraryService->syncLibraryForPack($projectId, $path, $name);
            } catch (\Exception $e) {
                // Non-fatal — library sync is optional
            }

            return new JsonResponse([
                'success' => true,
                'deletedNodeIds' => $deletedIds,
                'deletedCount' => count($deletedIds),
                'message' => 'Deleted ' . count($deletedIds) . ' node(s)'
            ]);

        } catch (\Exception $e) {
            $this->packService->close();
            return new JsonResponse(['error' => 'Failed to delete node: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get active jobs in a specific pack
     * Used by frontend to detect and resume stalled jobs after page refresh
     */
    #[Route('/pack/jobs', name: 'api_memory_pack_jobs', methods: ['POST'])]
    public function getPackJobs(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $projectId = $data['projectId'] ?? 'general';
        $path = $data['path'] ?? null;
        $name = $data['name'] ?? null;

        if (!$path || !$name) {
            return new JsonResponse(['error' => 'path and name are required'], 400);
        }

        try {
            $this->packService->open($projectId, $path, $name);
            $activeJobs = $this->packService->getActiveJobs();
            
            $jobs = [];
            foreach ($activeJobs as $job) {
                $payload = $job->getPayload();
                $jobs[] = [
                    'id' => $job->getId(),
                    'type' => $job->getType(),
                    'status' => $job->getStatus(),
                    'progress' => $job->getProgress(),
                    'totalSteps' => $job->getTotalSteps(),
                    'currentBlock' => $payload['current_block_title'] ?? null
                ];
            }
            
            $this->packService->close();

            return new JsonResponse([
                'success' => true,
                'jobs' => $jobs
            ]);

        } catch (\Exception $e) {
            try { $this->packService->close(); } catch (\Exception $e2) {}
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to get jobs: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Abort (cancel) an active job in a pack
     * Marks the job as cancelled so the step processor skips it on next iteration
     */
    #[Route('/pack/job/abort', name: 'api_memory_pack_job_abort', methods: ['POST'])]
    public function abortJob(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $projectId = $data['projectId'] ?? 'general';
        $path = $data['path'] ?? null;
        $name = $data['name'] ?? null;
        $jobId = $data['jobId'] ?? null;

        if (!$path || !$name || !$jobId) {
            return new JsonResponse(['error' => 'path, name, and jobId are required'], 400);
        }

        try {
            $this->packService->open($projectId, $path, $name);
            $job = $this->packService->findJobById($jobId);

            if (!$job) {
                $this->packService->close();
                return new JsonResponse(['error' => 'Job not found'], 404);
            }

            $this->packService->cancelJob($jobId);
            $this->packService->close();

            return new JsonResponse(['success' => true]);

        } catch (\Exception $e) {
            try { $this->packService->close(); } catch (\Exception $e2) {}
            return new JsonResponse(['error' => 'Failed to abort job: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Search memories in a pack using FTS5 full-text search
     * Returns ranked results with scores, categories, and tags
     */
    #[Route('/pack/search', name: 'api_memory_pack_search', methods: ['POST'])]
    public function searchPack(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $projectId = $data['projectId'] ?? 'general';
        $path = $data['path'] ?? null;
        $name = $data['name'] ?? null;
        $query = trim($data['query'] ?? '');
        $category = $data['category'] ?? null;
        $limit = min((int)($data['limit'] ?? 500), 500);

        if (!$path || !$name) {
            return new JsonResponse(['error' => 'path and name are required'], 400);
        }

        if (empty($query)) {
            return new JsonResponse(['error' => 'query is required'], 400);
        }

        try {
            $this->packService->open($projectId, $path, $name);

            $results = $this->packService->recall(
                $query,
                $category,
                [],           // no tag filter
                $limit,
                false,        // no related memories (user can click to see those)
                0.1,         // recency weight (lower for search — relevance matters most)
                0.3,         // importance weight
                0.5,         // relevance weight (FTS5 match quality)
                0.1          // connectedness weight (more relationships = higher score)
            );

            $hasFTS5 = $this->packService->hasFTS5();
            $this->packService->close();

            // Format results for frontend
            $formattedResults = [];
            foreach ($results as $result) {
                $node = $result['node'];
                $formattedResults[] = [
                    'id' => $node->getId(),
                    'summary' => $node->getSummary(),
                    'content' => $node->getContent(),
                    'category' => $node->getCategory(),
                    'importance' => round($node->getImportance(), 2),
                    'score' => round($result['score'], 3),
                    'tags' => $result['tags'] ?? [],
                    'createdAt' => $node->getCreatedAt(),
                    'accessCount' => $node->getAccessCount(),
                    'isRelated' => $result['isRelated'] ?? false,
                ];
            }

            return new JsonResponse([
                'success' => true,
                'query' => $query,
                'results' => $formattedResults,
                'count' => count($formattedResults),
                'hasFTS5' => $hasFTS5,
            ]);

        } catch (\Exception $e) {
            try { $this->packService->close(); } catch (\Exception $e2) {}
            return new JsonResponse(['error' => 'Search failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Process one job step synchronously
     * Returns the nodes and edges created in this step for immediate graph update
     */
    #[Route('/pack/step', name: 'api_memory_pack_step', methods: ['POST'])]
    public function processJobStep(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $projectId = $data['projectId'] ?? 'general';
        $path = $data['path'] ?? null;
        $name = $data['name'] ?? null;

        if (!$path || !$name) {
            return new JsonResponse(['error' => 'path and name are required'], 400);
        }

        // Release session lock early — job steps make AI calls (3-15s+)
        $request->getSession()->save();

        try {
            $targetPack = [
                'projectId' => $projectId,
                'path' => $path,
                'name' => $name
            ];
            
            // Open pack to check for jobs
            $this->packService->open($projectId, $path, $name);
            $jobsToProcess = $this->packService->getJobsToProcess(1);
            
            if (empty($jobsToProcess)) {
                $this->packService->close();
                return new JsonResponse([
                    'success' => true,
                    'hasMoreSteps' => false,
                    'message' => 'No jobs to process'
                ]);
            }
            
            $job = $jobsToProcess[0];
            $jobId = $job->getId();
            
            // Signal that the frontend /step loop is actively driving this job
            // Backend processMemoryJobs() checks this and skips step processing
            $payload = $job->getPayload();
            $payload['last_step_request_at'] = time();
            $this->packService->updateJobPayload($jobId, $payload);
            
            // Get timestamp before processing to capture new nodes/edges
            $beforeTimestamp = date('Y-m-d H:i:s');
            $this->packService->close();
            
            // Process ONE step (opens/closes pack internally)
            $isComplete = false;
            if ($job->getType() === 'extract_recursive') {
                $isComplete = $this->aiToolMemoryService->processPackExtractionJobStep($targetPack, $jobId);
            } elseif ($job->getType() === 'analyze_relationships') {
                $isComplete = $this->aiToolMemoryService->processPackRelationshipAnalysisJobStep($targetPack, $jobId);
            }
            
            // Get nodes and edges created in this step
            $this->packService->open($projectId, $path, $name);
            $delta = $this->packService->getGraphDelta($beforeTimestamp);
            
            // Get updated job status
            $updatedJob = $this->packService->findJobById($jobId);
            $jobStatus = null;
            if ($updatedJob) {
                $jobStatus = [
                    'id' => $updatedJob->getId(),
                    'type' => $updatedJob->getType(),
                    'status' => $updatedJob->getStatus(),
                    'progress' => $updatedJob->getProgress(),
                    'totalSteps' => $updatedJob->getTotalSteps(),
                    'currentBlock' => $updatedJob->getPayload()['current_block_title'] ?? null
                ];
            }
            
            // Check if there are more jobs
            $remainingJobs = $this->packService->getJobsToProcess(1);
            $hasMoreSteps = !empty($remainingJobs);
            
            $this->packService->close();

            return new JsonResponse([
                'success' => true,
                'hasMoreSteps' => $hasMoreSteps,
                'job' => $jobStatus,
                'delta' => $delta,
                'stepComplete' => $isComplete
            ]);

        } catch (\Exception $e) {
            $this->packService->close();
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to process step: ' . $e->getMessage()
            ], 500);
        }
    }
}
