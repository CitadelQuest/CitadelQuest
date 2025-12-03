<?php

namespace App\Controller;

use App\Entity\Spirit;
use App\Service\AiGatewayService;
use App\Service\AiServiceModelService;
use App\Service\AiModelsSyncService;
use App\Service\SettingsService;
use App\Service\SpiritService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use App\CitadelVersion;

#[Route('/welcome')]
#[IsGranted('ROLE_USER')]
class WelcomeController extends AbstractController
{
    public function __construct(
        private readonly AiGatewayService $aiGatewayService,
        private readonly AiServiceModelService $aiServiceModelService,
        private readonly AiModelsSyncService $aiModelsSyncService,
        private readonly SettingsService $settingsService,
        private readonly SpiritService $spiritService,
        private readonly HttpClientInterface $httpClient
    ) {}
    
    #[Route('', name: 'app_welcome_onboarding')]
    public function index(SessionInterface $session, Request $request): Response
    {
        $session->set('_locale', $request->cookies->get('citadel_locale', 'en'));
        
        // Check if user has completed onboarding
        $hasCompletedOnboarding = $this->settingsService->getSettingValue('onboarding.completed', false);
        
        // If onboarding is already completed, redirect to home
        if ($hasCompletedOnboarding) {
            return $this->redirectToRoute('app_home');
        }

        $apiKey = $this->settingsService->getSettingValue('cqaigateway.api_key');

        $step = 1;
        if ($apiKey && $apiKey !== '') {
            $step = 2;
        }

        $session->set('currentStep', $step);
        
        return $this->render('welcome/onboarding.html.twig', [
            //'apiKey' => $apiKey,
            '_locale' => $session->get('_locale'),
            'step' => $step
        ]);
    }
    
    #[Route('/add-gateway', name: 'app_welcome_add_gateway', methods: ['POST'])]
    public function addGateway(Request $request, SessionInterface $session): JsonResponse
    {
        // check if 'onboarding.completed' is still false
        $hasCompletedOnboarding = $this->settingsService->getSettingValue('onboarding.completed', false);
        if ($hasCompletedOnboarding) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Onboarding already completed'
            ], Response::HTTP_BAD_REQUEST);
        }

        $apiKey = $request->request->get('apiKey');
        
        if (!$apiKey) {
            return new JsonResponse([
                'success' => false,
                'message' => 'API key is required'
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Use the new centralized sync service
            $result = $this->aiModelsSyncService->syncModels($apiKey, $session);
            
            if (!$result['success']) {
                // Map error codes to appropriate HTTP status codes
                $statusCode = match($result['error_code'] ?? 'UNKNOWN') {
                    'INVALID_API_KEY' => Response::HTTP_UNAUTHORIZED,
                    'GATEWAY_UNAVAILABLE', 'NO_MODELS' => Response::HTTP_SERVICE_UNAVAILABLE,
                    default => Response::HTTP_INTERNAL_SERVER_ERROR
                };
                
                return new JsonResponse([
                    'success' => false,
                    'message' => $result['message']
                ], $statusCode);
            }

            return new JsonResponse([
                'success' => true,
                'message' => $result['message'],
                'gateway' => $result['gateway'],
                'models' => $result['models']
            ], Response::HTTP_OK);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error adding gateway: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    
    #[Route('/skip', name: 'app_welcome_skip', methods: ['POST'])]
    public function skip(): JsonResponse
    {
        // Mark onboarding as skipped (completed without full setup)
        $this->settingsService->setSetting('onboarding.completed', '1');
        $this->settingsService->setSetting('onboarding.skipped', '1');
        
        return new JsonResponse([
            'success' => true,
            'message' => 'Onboarding skipped',
            'redirect' => $this->generateUrl('app_home')
        ], Response::HTTP_OK);
    }

    #[Route('/create-spirit', name: 'app_welcome_create_spirit', methods: ['POST'])]
    public function createSpirit(Request $request): JsonResponse
    {
        // check if 'onboarding.completed' is still false
        $hasCompletedOnboarding = $this->settingsService->getSettingValue('onboarding.completed', false);
        if ($hasCompletedOnboarding) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Onboarding already completed'
            ], Response::HTTP_BAD_REQUEST);
        }

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

            // Set spirit as primary ai service model
            $this->settingsService->setSetting('ai.primary_ai_service_model_id', $modelId);
            $this->settingsService->setSetting('ai.secondary_ai_service_model_id', $modelId);
            
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
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            error_log('Create Spirit Exception: ' . $e->getMessage() . ' ' . $e->getTraceAsString());
            return new JsonResponse([
                'success' => false,
                'message' => 'Error creating spirit: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
