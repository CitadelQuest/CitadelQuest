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
use Psr\Log\LoggerInterface;

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
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger
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

        $apiKey = $this->aiGatewayService->getDefaultCqAiGatewayApiKey();

        $step = 1;
        if ($apiKey && $apiKey !== '') {
            $step = 2;
        }

        $session->set('currentStep', $step);
        
        return $this->render('welcome/onboarding.html.twig', [
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

        // create 'CQ AI Gateway'
        $cqAiGatewayApiUrl = 'https://cqaigateway.com/api';
        $gateway = $this->aiGatewayService->findByName('CQ AI Gateway');
        if ($gateway) {
            $this->aiGatewayService->updateGateway($gateway->getId(), [
                'apiKey' => $apiKey,
                'apiEndpointUrl' => $cqAiGatewayApiUrl,
                'type' => 'cq_ai_gateway'
            ]);
        } else {
            $gateway = $this->aiGatewayService->createGateway(
                'CQ AI Gateway',
                $apiKey,
                $cqAiGatewayApiUrl,
                'cq_ai_gateway'
            );
        }

        try {
            // Use the new centralized sync service
            $result = $this->aiModelsSyncService->syncModels();
            
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
                'message' => $result['message']
            ], Response::HTTP_OK);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error syncing models: ' . $e->getMessage()
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
        $color = $request->request->get('color', '#6c5ce7');
        
        // Log the request data
        $this->logger->info('Create Spirit Request - Name: ' . $name . ', Color: ' . $color);
        
        if (!$name) {
            $this->logger->error('Create Spirit Error: Name is required');
            return new JsonResponse([
                'success' => false,
                'message' => 'Name is required'
            ], Response::HTTP_BAD_REQUEST);
        }
        
        try {
            // Get default model
            $model = $this->aiServiceModelService->getDefaultPrimaryAiModelByGateway($this->aiGatewayService->findByName('CQ AI Gateway')->getId());
            if (!$model) {
                $this->logger->error('Create Spirit Error: Default AI model not found');
            }
            
            // Create spirit
            $this->logger->info('Creating spirit with name: ' . $name . ' and color: ' . $color);
            $spirit = $this->spiritService->createSpirit($name, $color);
            $this->logger->info('Spirit created with ID: ' . $spirit->getId());
            
            // Set model for spirit
            $this->spiritService->updateSpiritModel($spirit->getId(), $model->getId());
            
            // Mark onboarding as completed
            $this->settingsService->setSetting('onboarding.completed', true);
            
            return new JsonResponse([
                'success' => true,
                'message' => 'Spirit created successfully',
                'spirit' => [
                    'id' => $spirit->getId(),
                    'name' => $spirit->getName(),
                    'model' => $model->getId()
                ]
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            $this->logger->error('Create Spirit Exception: ' . $e->getMessage() . ' ' . $e->getTraceAsString());
            return new JsonResponse([
                'success' => false,
                'message' => 'Error creating spirit: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
