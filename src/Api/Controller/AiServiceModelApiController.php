<?php

namespace App\Api\Controller;

use App\Service\AiServiceModelService;
use App\Service\AiGatewayService;
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
        private readonly AiServiceModelService $aiServiceModelService,
        private readonly AiGatewayService $aiGatewayService
    ) {
    }

    #[Route('', name: 'app_api_ai_service_model_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $gatewayId = $request->query->get('gateway');
        $activeOnly = $request->query->getBoolean('active', false);
        
        $models = match(true) {
            $gatewayId !== null => $this->aiServiceModelService->findByGateway($gatewayId),
            default => $this->aiServiceModelService->findAll($activeOnly),
        };

        return $this->json([
            'models' => array_map(fn($model) => $model->jsonSerialize(), $models)
        ]);
    }

    #[Route('/selector', name: 'app_api_ai_service_model_selector', methods: ['GET'])]
    public function selector(Request $request): JsonResponse
    {
        $filterType = $request->query->get('type', 'primary'); // 'primary' (include only models with `text`AND`image` modalities. exclude image models) or 'image' (image models only)
        
        // Get models based on filter
        if ($filterType === 'image') {
            // For image AI - only models with image output capability
            $gateways = $this->aiGatewayService->findAll();
            $models = [];
            foreach ($gateways as $gateway) {
                $imageModels = $this->aiServiceModelService->findImageOutputModelsByGateway($gateway->getId(), true);
                $models = array_merge($models, $imageModels);
            }
        } else {
            // For primary AI - include models with text+image INPUT (can see images)
            // Exclude models with image OUTPUT (we use specialized image sub-agents for that)
            $allModels = $this->aiServiceModelService->findAll(true);
            $models = [];
            foreach ($allModels as $model) {
                $fullConfig = $model->getFullConfig();
                $inputModalities = $fullConfig['architecture']['input_modalities'] ?? [];
                $outputModalities = $fullConfig['architecture']['output_modalities'] ?? [];
                
                // Must have both text and image INPUT (multimodal vision)
                $hasTextInput = in_array('text', $inputModalities);
                $hasImageInput = in_array('image', $inputModalities);
                
                // Must NOT have image OUTPUT (text responses only)
                $hasImageOutput = in_array('image', $outputModalities);
                
                if ($hasTextInput && $hasImageInput && !$hasImageOutput) {
                    $models[] = $model;
                }
            }
        }
        
        // Calculate max values for relative bars
        $maxPrice = 0;
        $maxContextWindow = 0;
        $maxOutput = 0;
        
        foreach ($models as $model) {
            // Weighted average based on real usage: 24 input tokens : 1 output token
            $avgPrice = (24 * $model->getPpmInput() + 1 * $model->getPpmOutput()) / 25;
            if ($avgPrice > $maxPrice) {
                $maxPrice = $avgPrice;
            }
            if ($model->getContextWindow() > $maxContextWindow) {
                $maxContextWindow = $model->getContextWindow();
            }
            if ($model->getMaxOutput() > $maxOutput) {
                $maxOutput = $model->getMaxOutput();
            }
        }
        
        // Add 10% buffer to max price for better visualization
        $maxPrice = $maxPrice * 1.1;
        
        // Enhance models with comparison data
        $enhancedModels = array_map(function($model) use ($maxPrice, $maxContextWindow, $maxOutput) {
            $fullConfig = $model->getFullConfig();
            $gateway = $this->aiGatewayService->findById($model->getAiGatewayId());
            
            // Extract provider from model name
            $provider = 'other';
            $modelName = $model->getModelName();
            if (strpos($modelName, '/') !== false) {
                $provider = strtolower(explode('/', $modelName)[0]);
            }
            
            // Get modalities
            $inputModalities = $fullConfig['architecture']['input_modalities'] ?? [];
            $outputModalities = $fullConfig['architecture']['output_modalities'] ?? [];
            
            // Calculate percentages
            // Weighted average based on real usage: 24 input tokens : 1 output token
            $avgPrice = (24 * $model->getPpmInput() + 1 * $model->getPpmOutput()) / 25;
            $pricePercentage = $maxPrice > 0 ? ($avgPrice / $maxPrice) * 100 : 0;
            $contextPercentage = $maxContextWindow > 0 ? ($model->getContextWindow() / $maxContextWindow) * 100 : 0;
            $maxOutputPercentage = $maxOutput > 0 ? ($model->getMaxOutput() / $maxOutput) * 100 : 0;
            
            return [
                'id' => $model->getId(),
                'modelName' => $model->getModelName(),
                'modelSlug' => $model->getModelSlug(),
                'provider' => $provider,
                'gatewayName' => $gateway ? $gateway->getName() : 'Unknown',
                'contextWindow' => $model->getContextWindow(),
                'contextPercentage' => round($contextPercentage, 1),
                'maxOutput' => $model->getMaxOutput(),
                'maxOutputPercentage' => round($maxOutputPercentage, 1),
                'ppmInput' => $model->getPpmInput(),
                'ppmOutput' => $model->getPpmOutput(),
                'avgPrice' => round($avgPrice, 0),
                'pricePercentage' => round($pricePercentage, 1),
                'inputModalities' => $inputModalities,
                'outputModalities' => $outputModalities,
                'description' => $fullConfig['description'] ?? null,
            ];
        }, $models);
        
        return $this->json([
            'success' => true,
            'models' => $enhancedModels,
            'maxPrice' => $maxPrice,
            'maxContextWindow' => $maxContextWindow,
            'maxOutput' => $maxOutput
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
        $model = $this->aiServiceModelService->findById($id);
        
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
        $result = $this->aiServiceModelService->deleteModel($id);
        
        if (!$result) {
            return $this->json(['error' => 'Model not found'], Response::HTTP_NOT_FOUND);
        }
        
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
