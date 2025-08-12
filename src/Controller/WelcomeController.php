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
        
        return $this->render('welcome/onboarding.html.twig', [
            'apiKey' => $apiKey,
            '_locale' => $session->get('_locale')
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
            // Validate key
            //  request models from CQ AI Gateway via http client
            $responseModels = $this->httpClient->request(
                'GET',
                'https://cqaigateway.com/api/ai/models', 
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'User-Agent' => 'CitadelQuest ' . CitadelVersion::VERSION . ' HTTP Client',
                        'Content-Type' => 'application/json',
                    ]
                ]
            );
            //  check response status
            $responseStatus = $responseModels->getStatusCode(false);
            if ($responseStatus !== Response::HTTP_OK) {
                if ($responseStatus === Response::HTTP_UNAUTHORIZED) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => 'Invalid API key'
                    ], Response::HTTP_UNAUTHORIZED);
                } else if ($responseStatus === Response::HTTP_SERVICE_UNAVAILABLE 
                            || $responseStatus === Response::HTTP_GATEWAY_TIMEOUT 
                            || $responseStatus === Response::HTTP_INTERNAL_SERVER_ERROR 
                            || $responseStatus === Response::HTTP_BAD_GATEWAY
                            || $responseStatus === Response::HTTP_TOO_MANY_REQUESTS
                            || $responseStatus === Response::HTTP_NOT_FOUND) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => 'CQ AI Gateway is currently unavailable, please try again later'
                    ], Response::HTTP_SERVICE_UNAVAILABLE);
                } else {
                    return new JsonResponse([
                        'success' => false,
                        'message' => 'Failed to validate API key (' . $responseStatus . ')'
                    ], Response::HTTP_INTERNAL_SERVER_ERROR);
                }
            }
            
            // Get models from response
            $cq_ai_models = json_decode($responseModels->getContent(), true)['models']??[];
            $models = [];

            if (!$cq_ai_models || !is_array($cq_ai_models) || count($cq_ai_models) === 0) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'No models found on CQ AI Gateway'
                ], Response::HTTP_SERVICE_UNAVAILABLE);
            }

            // Create CQ AI Gateway if not already created
            $gateway = $this->aiGatewayService->findByName('CQ AI Gateway');
            if (!$gateway) {
                $gateway = $this->aiGatewayService->createGateway(
                    'CQ AI Gateway',
                    $apiKey,
                    'https://cqaigateway.com/api',
                    'cq_ai_gateway'
                );
            }
            
            // Add models to database
            foreach ($cq_ai_models as $model) {
                $modelSlug = $model['id'];
                $modelSlugProvider = substr($modelSlug, 0, strpos($modelSlug, '/'));

                if ($modelSlugProvider === 'citadelquest') {            
                    $maxOutputTokens = isset($model['top_provider']) && isset($model['top_provider']['max_completion_tokens']) ? $model['top_provider']['max_completion_tokens'] : null;
                    $pricingInput = isset($model['pricing']) && isset($model['pricing']['prompt']) ? $model['pricing']['prompt'] : null;
                    $pricingOutput = isset($model['pricing']) && isset($model['pricing']['completion']) ? $model['pricing']['completion'] : null;
        
                    $newModel = $this->aiServiceModelService->createModel(
                        $gateway->getId(),          // gateway id
                        $model['name'],             // model name
                        $modelSlug,                 // model slug
                        null,                       // virtual key = null, deprecated
                        $model['context_length'],   // context length
                        $model['context_length'],   // max input
                        0,                          // maxInputImageSize
                        $maxOutputTokens,           // max output(response) tokens
                        $pricingInput,              // ppmInput
                        $pricingOutput,             // ppmOutput
                        true                        // is active
                    );

                    $models[] = $newModel;

                    // Set first model as primary model
                    if (count($models) === 1) {
                        $this->settingsService->setSetting('ai.primary_ai_service_model_id', $newModel->getId());
                        $this->settingsService->setSetting('ai.secondary_ai_service_model_id', $newModel->getId());
                    }
                }
            }

            $session->set('models', json_encode($models));
            
            return new JsonResponse([
                'success' => true,
                'message' => 'CQ AI Gateway added successfully',
                'gateway' => [
                    'id' => $gateway->getId(),
                    'name' => $gateway->getName()
                ],
                'models' => $models
            ], Response::HTTP_OK);
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
            error_log('Create Spirit Exception: ' . $e->getMessage() . '
' . $e->getTraceAsString());
            return new JsonResponse([
                'success' => false,
                'message' => 'Error creating spirit: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
