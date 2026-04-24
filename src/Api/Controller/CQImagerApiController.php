<?php

namespace App\Api\Controller;

use App\Service\CQImagerService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * CQ Imager API — human-facing, schema-driven image generation.
 *
 * All endpoints are session-authenticated (ROLE_USER). The service layer
 * handles the per-user gateway API key lookup.
 *
 * @see /docs/features/CQ-IMAGER.md
 */
#[Route('/api/imager')]
#[IsGranted('ROLE_USER')]
class CQImagerApiController extends AbstractController
{
    public function __construct(
        private readonly CQImagerService $imagerService,
    ) {}

    /**
     * GET /api/imager/models
     * Returns the enabled-models catalog (normalized descriptors with
     * flat `params[]` ready for the dynamic form engine).
     *
     * Query:
     *   ?refresh=1  → bypass gateway cache and rebuild (dev/debug)
     */
    #[Route('/models', name: 'api_imager_models', methods: ['GET'])]
    public function models(Request $request): JsonResponse
    {
        try {
            $models = $this->imagerService->getModels(
                (bool) $request->query->getBoolean('refresh')
            );
            return new JsonResponse([
                'success' => true,
                'models'  => $models,
                'count'   => count($models),
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/imager/generate
     *
     * Body:
     * {
     *   "model":    "google:4@3",
     *   "params":   { ...flat param set... },
     *   "projectId": "general",            // optional, default "general"
     *   "outputDir": "/uploads/imager",    // optional
     *   "filename":  "my-crane.jpg"        // optional
     * }
     */
    #[Route('/generate', name: 'api_imager_generate', methods: ['POST'])]
    public function generate(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid JSON body'], 400);
        }

        $model      = (string) ($data['model'] ?? '');
        $params     = is_array($data['params'] ?? null) ? $data['params'] : [];
        $projectId  = (string) ($data['projectId'] ?? 'general');
        $outputDir  = (string) ($data['outputDir'] ?? '/uploads/imager');
        $filename   = isset($data['filename']) && $data['filename'] !== ''
            ? (string) $data['filename']
            : null;

        if ($model === '') {
            return new JsonResponse(['success' => false, 'error' => 'model is required'], 400);
        }

        try {
            $result = $this->imagerService->generate($model, $params, $projectId, $outputDir, $filename);
            $status = !empty($result['success']) ? 200 : 422;
            return new JsonResponse($result, $status);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/imager/history
     *
     * Query params:
     *   projectId, projectFileId, model, limit (default 50, max 500), offset
     */
    #[Route('/history', name: 'api_imager_history', methods: ['GET'])]
    public function history(Request $request): JsonResponse
    {
        $filters = array_filter([
            'projectId'     => $request->query->get('projectId'),
            'projectFileId' => $request->query->get('projectFileId'),
            'model'         => $request->query->get('model'),
            'limit'         => $request->query->get('limit'),
            'offset'        => $request->query->get('offset'),
        ], fn($v) => $v !== null && $v !== '');

        try {
            $generations = $this->imagerService->listHistory($filters);
            return new JsonResponse([
                'success'     => true,
                'generations' => $generations,
                'count'       => count($generations),
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    #[Route('/history/{id}', name: 'api_imager_history_item', methods: ['GET'], requirements: ['id' => '[a-zA-Z0-9\-]+'])]
    public function historyItem(string $id): JsonResponse
    {
        try {
            $gen = $this->imagerService->getGeneration($id);
            if (!$gen) {
                return new JsonResponse(['success' => false, 'error' => 'Generation not found'], 404);
            }
            return new JsonResponse(['success' => true, 'generation' => $gen]);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /api/imager/history/{id}
     *
     * Query:
     *   ?deleteFile=1  → also remove the underlying project_file (default keeps file)
     */
    #[Route('/history/{id}', name: 'api_imager_history_delete', methods: ['DELETE'], requirements: ['id' => '[a-zA-Z0-9\-]+'])]
    public function deleteHistoryItem(string $id, Request $request): JsonResponse
    {
        $deleteFile = $request->query->getBoolean('deleteFile');
        try {
            $ok = $this->imagerService->deleteGeneration($id, $deleteFile);
            if (!$ok) {
                return new JsonResponse(['success' => false, 'error' => 'Generation not found'], 404);
            }
            return new JsonResponse(['success' => true, 'deleted' => $id, 'fileDeleted' => $deleteFile]);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
