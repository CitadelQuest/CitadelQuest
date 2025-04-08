<?php

namespace App\Api\Controller;

use App\Service\AiServiceModelService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/ai/model')]
#[IsGranted('ROLE_USER')]
class AiServiceModelApiController extends AbstractController
{
    public function __construct(
        private readonly AiServiceModelService $aiServiceModelService
    ) {
    }

    #[Route('', name: 'app_api_ai_service_model_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $gatewayId = $request->query->get('gateway');
        $activeOnly = $request->query->getBoolean('active', false);
        
        $models = match(true) {
            $gatewayId !== null => $this->aiServiceModelService->findByGateway($this->getUser(), $gatewayId),
            default => $this->aiServiceModelService->findAll($this->getUser(), $activeOnly),
        };

        return $this->json([
            'models' => array_map(fn($model) => $model->jsonSerialize(), $models)
        ]);
    }

    #[Route('', name: 'app_api_ai_service_model_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['aiGatewayId']) || !isset($data['modelName']) || !isset($data['modelSlug'])) {
            return $this->json(['error' => 'Missing required fields'], Response::HTTP_BAD_REQUEST);
        }

        $model = $this->aiServiceModelService->createModel(
            $this->getUser(),
            $data['aiGatewayId'],
            $data['modelName'],
            $data['modelSlug'],
            $data['virtualKey'] ?? null,
            $data['contextWindow'] ?? null,
            $data['maxInput'] ?? null,
            $data['maxInputImageSize'] ?? null,
            $data['maxOutput'] ?? null,
            $data['ppmInput'] ?? null,
            $data['ppmOutput'] ?? null,
            $data['isActive'] ?? true
        );

        return $this->json([
            'model' => $model->jsonSerialize()
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'app_api_ai_service_model_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $model = $this->aiServiceModelService->findById($this->getUser(), $id);
        
        if (!$model) {
            return $this->json(['error' => 'Model not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'model' => $model->jsonSerialize()
        ]);
    }

    #[Route('/{id}', name: 'app_api_ai_service_model_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (empty($data)) {
            return $this->json(['error' => 'No update data provided'], Response::HTTP_BAD_REQUEST);
        }

        $model = $this->aiServiceModelService->updateModel(
            $this->getUser(),
            $id,
            $data
        );

        if (!$model) {
            return $this->json(['error' => 'Model not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'model' => $model->jsonSerialize()
        ]);
    }

    #[Route('/{id}', name: 'app_api_ai_service_model_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $result = $this->aiServiceModelService->deleteModel($this->getUser(), $id);
        
        if (!$result) {
            return $this->json(['error' => 'Model not found'], Response::HTTP_NOT_FOUND);
        }
        
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
