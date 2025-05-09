<?php

namespace App\Controller;

use App\Entity\Spirit;
use App\Service\AiGatewayService;
use App\Service\AiServiceModelService;
use App\Service\SettingsService;
use App\Service\SpiritService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/welcome')]
#[IsGranted('ROLE_USER')]
class WelcomeController extends AbstractController
{
    public function __construct(
        private readonly AiGatewayService $aiGatewayService,
        private readonly AiServiceModelService $aiServiceModelService,
        private readonly SettingsService $settingsService,
        private readonly SpiritService $spiritService
    ) {}
    
    #[Route('', name: 'app_welcome_onboarding')]
    public function index(): Response
    {
        // Check if user has completed onboarding
        $hasCompletedOnboarding = $this->settingsService->getSettingValue('onboarding.completed', false);
        
        // If onboarding is already completed, redirect to home
        if ($hasCompletedOnboarding) {
            return $this->redirectToRoute('app_home');
        }
        
        // Get existing gateways and models
        $aiGateways = $this->aiGatewayService->findAll();
        $aiModels = $this->aiServiceModelService->findAll(true);
        
        // Convert models to array for easier JSON serialization
        $aiModelsArray = [];
        foreach ($aiModels as $model) {
            $aiModelsArray[$model->getId()] = [
                'id' => $model->getId(),
                'name' => $model->getModelName(),
                'modelSlug' => $model->getModelSlug(),
                'gatewayId' => $model->getAiGatewayId()
            ];
        }
        
        // If no models are available, add fallback models
        if (empty($aiModelsArray)) {
            error_log('No AI models found, adding fallback models');
            $fallbackModels = [
                'claude' => [
                    'id' => 'claude-fallback',
                    'name' => 'Claude 3 Sonnet',
                    'modelSlug' => 'anthropic/claude-3-sonnet-20240229',
                    'gatewayId' => 'fallback'
                ],
                'gemini' => [
                    'id' => 'gemini-fallback',
                    'name' => 'Gemini Pro',
                    'modelSlug' => 'google/gemini-pro',
                    'gatewayId' => 'fallback'
                ],
                'grok' => [
                    'id' => 'grok-fallback',
                    'name' => 'Grok',
                    'modelSlug' => 'xai/grok-1',
                    'gatewayId' => 'fallback'
                ]
            ];
            $aiModelsArray = $fallbackModels;
        }
        
        error_log('AI Models for onboarding: ' . json_encode($aiModelsArray));
        
        // Check if user has a CQ AI Gateway configured
        $hasCqGateway = false;
        foreach ($aiGateways as $gateway) {
            if (str_contains(strtolower($gateway->getName()), 'cq') || 
                str_contains(strtolower($gateway->getApiEndpointUrl()), 'cqaigateway.com')) {
                $hasCqGateway = true;
                break;
            }
        }
        
        // Check if user has any spirits
        $spirits = $this->spiritService->findAll();
        
        return $this->render('welcome/onboarding.html.twig', [
            'hasCqGateway' => $hasCqGateway,
            'hasSpirits' => count($spirits) > 0,
            'aiGateways' => $aiGateways,
            'aiModels' => $aiModels,
            'aiModelsArray' => $aiModelsArray
        ]);
    }
    
    #[Route('/add-gateway', name: 'app_welcome_add_gateway', methods: ['POST'])]
    public function addGateway(Request $request): JsonResponse
    {
        $apiKey = $request->request->get('apiKey');
        
        if (!$apiKey) {
            return new JsonResponse([
                'success' => false,
                'message' => 'API key is required'
            ], Response::HTTP_BAD_REQUEST);
        }
        
        try {
            // Create CQ AI Gateway
            $gateway = $this->aiGatewayService->createGateway(
                'CQ AI Gateway',
                $apiKey,
                'https://cqaigateway.com/api/ai',
                'cq_ai_gateway'
            );
            
            // Add default models
            $this->aiServiceModelService->createModel(
                $gateway->getId(),
                'Claude 3.5 Haiku',
                'anthropic/claude-3.5-haiku',
                null,
                131072,
                131072,
                0,
                8192,
                null,
                null,
                true
            );
            
            $this->aiServiceModelService->createModel(
                $gateway->getId(),
                'Gemini 2.5 Pro',
                'google/gemini-2.5-pro-preview',
                null,
                131072,
                131072,
                0,
                8192,
                null,
                null,
                true
            );
            
            $this->aiServiceModelService->createModel(
                $gateway->getId(),
                'Grok',
                'x-ai/grok-3-beta',
                null,
                131072,
                131072,
                0,
                8192,
                null,
                null,
                true
            );
            
            // Set Claude Sonnet as primary model
            $models = $this->aiServiceModelService->findAll(true);
            foreach ($models as $model) {
                if ($model->getModelSlug() === 'google/gemini-2.5-pro-preview') {
                    $this->settingsService->setSetting('ai.primary_ai_service_model_id', $model->getId());
                    $this->settingsService->setSetting('ai.secondary_ai_service_model_id', $model->getId());
                    break;
                }
            }
            
            return new JsonResponse([
                'success' => true,
                'message' => 'CQ AI Gateway added successfully',
                'gateway' => [
                    'id' => $gateway->getId(),
                    'name' => $gateway->getName()
                ]
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error adding gateway: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    
    #[Route('/create-spirit', name: 'app_welcome_create_spirit', methods: ['POST'])]
    public function createSpirit(Request $request): JsonResponse
    {
        $name = $request->request->get('name');
        $modelId = $request->request->get('modelId');
        $color = $request->request->get('color', '#6c5ce7');
        
        // Log the request data
        error_log('Create Spirit Request - Name: ' . $name . ', ModelId: ' . $modelId . ', Color: ' . $color);
        
        if (!$name || !$modelId) {
            error_log('Create Spirit Error: Name or model ID missing');
            return new JsonResponse([
                'success' => false,
                'message' => 'Name and model are required'
            ], Response::HTTP_BAD_REQUEST);
        }
        
        try {
            // Check if model exists
            $model = $this->aiServiceModelService->findById($modelId);
            if (!$model) {
                error_log('Create Spirit Error: Model not found with ID: ' . $modelId);
                
                // Try to find a default model
                $models = $this->aiServiceModelService->findAll(true);
                if (count($models) > 0) {
                    $model = $models[0];
                    $modelId = $model->getId();
                    error_log('Using default model instead: ' . $modelId);
                } else {
                    throw new \RuntimeException('No AI models available');
                }
            }
            
            // Create spirit
            error_log('Creating spirit with name: ' . $name . ' and color: ' . $color);
            $spirit = $this->spiritService->createSpirit($name, $color);
            error_log('Spirit created with ID: ' . $spirit->getId());
            
            // Set model for spirit
            error_log('Setting spirit model to: ' . $modelId);
            $this->spiritService->updateSpiritModel($spirit->getId(), $modelId);
            
            // Mark onboarding as completed
            $this->settingsService->setSetting('onboarding.completed', true);
            
            return new JsonResponse([
                'success' => true,
                'message' => 'Spirit created successfully',
                'spirit' => [
                    'id' => $spirit->getId(),
                    'name' => $spirit->getName(),
                    'model' => $modelId
                ]
            ]);
        } catch (\Exception $e) {
            error_log('Create Spirit Exception: ' . $e->getMessage() . '
' . $e->getTraceAsString());
            return new JsonResponse([
                'success' => false,
                'message' => 'Error creating spirit: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    
    #[Route('/complete', name: 'app_welcome_complete', methods: ['POST'])]
    public function completeOnboarding(): JsonResponse
    {
        // Mark onboarding as completed
        $this->settingsService->setSetting('onboarding.completed', true);
        
        return new JsonResponse([
            'success' => true,
            'message' => 'Onboarding completed'
        ]);
    }
}
