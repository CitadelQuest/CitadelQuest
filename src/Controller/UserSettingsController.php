<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\AiGateway;
use App\Repository\UserRepository;
use App\Service\AiGatewayService;
use App\Service\AiServiceModelService;
use App\Service\AiModelsSyncService;
use App\Service\SettingsService;
use App\Service\StorageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\CitadelVersion;
use Psr\Log\LoggerInterface;

#[Route('/settings')]
#[IsGranted('ROLE_USER')]
class UserSettingsController extends AbstractController
{
    public function __construct(
        private readonly AiGatewayService $aiGatewayService,
        private readonly AiServiceModelService $aiServiceModelService,
        private readonly AiModelsSyncService $aiModelsSyncService,
        private readonly SettingsService $settingsService,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly StorageService $storageService
    ) {
    }

    #[Route('', name: 'app_user_settings')]
    public function index(): Response
    {
        // Get user's settings
        $settings = $this->settingsService->getAllSettings();
        
        return $this->render('user_settings/index.html.twig', [
            'settings' => $settings,
            'user' => $this->getUser(),
        ]);
    }

    #[Route('/email', name: 'app_user_settings_email', methods: ['POST'])]
    public function updateEmail(
        Request $request,
        EntityManagerInterface $entityManager,
        NotificationService $notificationService,
        TranslatorInterface $translator
    ): JsonResponse {
        if (!$request->isXmlHttpRequest()) {
            throw $this->createAccessDeniedException();
        }

        $user = $this->getUser();
        $email = $request->request->get('email');
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse([
                'message' => $translator->trans('profile.email.invalid')
            ], Response::HTTP_BAD_REQUEST);
        }

        // Check if email is already used
        $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existingUser && $existingUser !== $user) {
            return new JsonResponse([
                'message' => $translator->trans('auth.register.error.email_already_used')
            ], Response::HTTP_BAD_REQUEST);
        }

        $user->setEmail($email);
        $entityManager->flush();
        
        $notificationService->createNotification(
            $user,
            $translator->trans('profile.email.updated.title'),
            $translator->trans('profile.email.updated.message'),
            'success'
        );

        return new JsonResponse([
            'message' => $translator->trans('profile.email.updated.title')
        ]);
    }

    /**
     * Generate a single-use login URL for CQ AI Gateway web interface
     * This allows seamless login from CitadelQuest without password re-entry
     */
    #[Route('/ai/gateway-login', name: 'app_user_settings_ai_gateway_login', methods: ['POST'])]
    public function gatewayLogin(Request $request): JsonResponse
    {
        $this->logger->info('UserSettingsController::gatewayLogin - Gateway login requested');
        
        try {
            // Get the API key from settings
            $apiKey = $this->settingsService->getSettingValue('cqaigateway.api_key');
            
            if (!$apiKey) {
                // get API Key from AI Gateway Entity
                $apiKey = $this->aiGatewayService->findByName('CQ AI Gateway')?->getApiKey();
                if (!$apiKey) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => 'CQ AI Gateway API key not configured'
                    ], Response::HTTP_BAD_REQUEST);
                }
            }

            // Get redirect destination from request
            $data = json_decode($request->getContent(), true) ?? [];
            $redirect = $data['redirect'] ?? 'dashboard';

            // Request a single-use login token from CQ AI Gateway
            $response = $this->httpClient->request(
                'POST',
                'https://cqaigateway.com/api/user/web-login',
                [
                    'headers' => [
                        'User-Agent' => 'CitadelQuest ' . CitadelVersion::VERSION . ' HTTP Client',
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer ' . $apiKey,
                    ],
                    'json' => [
                        'redirect' => $redirect
                    ]
                ]
            );

            $statusCode = $response->getStatusCode();
            $content = json_decode($response->getContent(false), true);

            if ($statusCode === 200 && isset($content['login_url'])) {
                $this->logger->info('UserSettingsController::gatewayLogin - Login URL generated successfully');
                
                return new JsonResponse([
                    'success' => true,
                    'login_url' => $content['login_url']
                ]);
            } else {
                $this->logger->warning('UserSettingsController::gatewayLogin - Failed to get login URL', [
                    'status_code' => $statusCode,
                    'error' => $content['error'] ?? 'Unknown error'
                ]);
                
                return new JsonResponse([
                    'success' => false,
                    'message' => $content['error'] ?? 'Failed to generate login URL'
                ], Response::HTTP_BAD_REQUEST);
            }
        } catch (\Exception $e) {
            $this->logger->error('UserSettingsController::gatewayLogin - Exception', [
                'error' => $e->getMessage()
            ]);
            
            return new JsonResponse([
                'success' => false,
                'message' => 'Error connecting to CQ AI Gateway: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/ai/sync-models', name: 'app_user_settings_ai_sync_models', methods: ['POST'])]
    public function syncAiModels(): JsonResponse
    {
        $this->logger->info('UserSettingsController::syncAiModels - Manual AI models sync requested');
        
        try {
            $result = $this->aiModelsSyncService->syncModels();
            
            if ($result['success']) {
                $this->logger->info('UserSettingsController::syncAiModels - Manual sync completed successfully', [
                    'models_count' => count($result['models'] ?? [])
                ]);
                
                return new JsonResponse([
                    'success' => true,
                    'message' => 'AI models synchronized successfully',
                    'models_count' => count($result['models'] ?? [])
                ]);
            } else {
                $this->logger->warning('UserSettingsController::syncAiModels - Manual sync failed', [
                    'error' => $result['message'],
                    'error_code' => $result['error_code'] ?? 'UNKNOWN'
                ]);
                
                return new JsonResponse([
                    'success' => false,
                    'message' => $result['message']
                ], Response::HTTP_BAD_REQUEST);
            }
        } catch (\Exception $e) {
            $this->logger->error('UserSettingsController::syncAiModels - Exception during manual sync', [
                'error' => $e->getMessage()
            ]);
            
            return new JsonResponse([
                'success' => false,
                'message' => 'Error synchronizing models: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/password', name: 'app_user_settings_password', methods: ['POST'])]
    public function updatePassword(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        NotificationService $notificationService,
        TranslatorInterface $translator
    ): JsonResponse {
        if (!$request->isXmlHttpRequest()) {
            throw $this->createAccessDeniedException();
        }

        $user = $this->getUser();
        $currentPassword = $request->request->get('current_password');
        $newPassword = $request->request->get('new_password');
        $confirmPassword = $request->request->get('confirm_password');

        if (!$passwordHasher->isPasswordValid($user, $currentPassword)) {
            return new JsonResponse([
                'message' => $translator->trans('profile.password.current_invalid')
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($newPassword !== $confirmPassword) {
            return new JsonResponse([
                'message' => $translator->trans('profile.password.mismatch')
            ], Response::HTTP_BAD_REQUEST);
        }

        if (strlen($newPassword) < 8) {
            return new JsonResponse([
                'message' => $translator->trans('profile.password.too_short')
            ], Response::HTTP_BAD_REQUEST);
        }

        $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
        $entityManager->flush();

        $notificationService->createNotification(
            $user,
            $translator->trans('profile.password.updated.title'),
            $translator->trans('profile.password.updated.message'),
            'success'
        );

        return new JsonResponse([
            'message' => $translator->trans('profile.password.updated.title')
        ]);
    }

    #[Route('/admin', name: 'app_user_settings_admin')]
    public function admin(): Response
    {
        return $this->render('user_settings/admin.html.twig');
    }

    #[Route('/ai', name: 'app_user_settings_ai')]
    public function aiSettings(Request $request, EntityManagerInterface $entityManager, UserRepository $userRepository): Response
    {
        $this->logger->info('UserSettingsController::aiSettings - Starting AI settings method', [
            'method' => $request->getMethod(),
            'user_id' => $this->getUser()?->getId(),
            'user_username' => $this->getUser()?->getUsername(),
            'request_uri' => $request->getRequestUri(),
            'user_agent' => $request->headers->get('User-Agent')
        ]);
        
        try {
            // Get user's settings
            $this->logger->info('UserSettingsController::aiSettings - Getting user settings');
            $settings = $this->settingsService->getAllSettings();
            $this->logger->info('UserSettingsController::aiSettings - Retrieved settings', ['settings_count' => count($settings)]);
        
            // Handle form submission
            if ($request->isMethod('POST')) {
                $this->logger->info('UserSettingsController::aiSettings - Processing POST request');
                
                // Update existing settings:
                // 1. API key
                $request_api_key = $request->request->get('cq_ai_gateway_api_key');
                $this->logger->info('UserSettingsController::aiSettings - Processing API key update', [
                    'has_api_key' => !empty($request_api_key),
                    'api_key_length' => $request_api_key ? strlen($request_api_key) : 0
                ]);
                
                if ($request_api_key && $request_api_key !== '') {
                    $this->logger->info('UserSettingsController::aiSettings - Looking for existing CQ AI Gateway');
                    $gateway = $this->aiGatewayService->findByName('CQ AI Gateway');
                    
                    if ($gateway) {
                        $this->logger->info('UserSettingsController::aiSettings - Found existing gateway', ['gateway_id' => $gateway->getId()]);
                        $gateway->setApiKey($request_api_key);
                        $entityManager->flush();
                        $this->logger->info('UserSettingsController::aiSettings - Updated existing gateway API key');
                        $this->addFlash('success', 'API key updated successfully.');
                    } else {
                        $this->logger->info('UserSettingsController::aiSettings - No existing gateway found, validating new API key');
                        try {
                            // Use the new centralized sync service
                            $this->logger->info('UserSettingsController::aiSettings - Using AiModelsSyncService for API key validation and model sync');
                            
                            $result = $this->aiModelsSyncService->syncModels($request_api_key);
                            
                            if (!$result['success']) {
                                $this->logger->warning('UserSettingsController::aiSettings - Models sync failed', [
                                    'error' => $result['message'],
                                    'error_code' => $result['error_code'] ?? 'UNKNOWN'
                                ]);
                                
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
                            
                            $this->logger->info('UserSettingsController::aiSettings - Models sync completed successfully', [
                                'models_count' => count($result['models'] ?? [])
                            ]);
                            
                            return new JsonResponse([
                                'success' => true,
                                'message' => $result['message'],
                                'gateway' => $result['gateway'],
                                'models' => $result['models']
                            ], Response::HTTP_OK);
                            
                        } catch (\Exception $e) {
                            $this->logger->error('UserSettingsController::aiSettings - Exception during models sync', [
                                'error' => $e->getMessage()
                            ]);
                            
                            return new JsonResponse([
                                'success' => false,
                                'message' => 'Error adding gateway: ' . $e->getMessage()
                            ], Response::HTTP_INTERNAL_SERVER_ERROR);
                        }
                    
            
                    $this->addFlash('success', 'API key updated successfully.');
                }
            }
            // 2. Primary and secondary AI models
            $request_primary_model = $request->request->get('primary_model');
            $request_secondary_model = $request->request->get('secondary_model');
            if ($request_primary_model && $request_primary_model !== '') {
                $this->settingsService->setSetting('ai.primary_ai_service_model_id', $request_primary_model);
            }
            if ($request_secondary_model && $request_secondary_model !== '') {
                $this->settingsService->setSetting('ai.secondary_ai_service_model_id', $request_secondary_model);
            }
            if ($request_primary_model || $request_secondary_model) {
                $this->addFlash('success', 'AI models saved successfully.');
            }
            
            return $this->redirectToRoute('app_user_settings_ai');
        }
        
        // Check if API key is set in aiGateway 'CQ AI Gateway'
        $CQ_AI_GatewayCredits = null;
        $CQ_AI_GatewayUsername = null;
        $apiKeyState = 'not_set';
        $gateway = $this->aiGatewayService->findByName('CQ AI Gateway');
        if ($gateway) {            
            $apiKey = $gateway->getApiKey();
            if (!empty($apiKey)) {
                $apiKeyState = 'not_validated';
                
                // Validate key by requesting balance from CQ AI Gateway
                $responseProfile = $this->httpClient->request(
                    'GET',
                    $gateway->getApiEndpointUrl() . '/payment/balance', 
                    [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $apiKey,
                            'Content-Type' => 'application/json',
                            'User-Agent' => 'CitadelQuest ' . CitadelVersion::VERSION . ' HTTP Client',
                        ]
                    ]
                );
                //  check response status
                $responseStatus = $responseProfile->getStatusCode(false);
                if ($responseStatus !== Response::HTTP_OK) {
                    $apiKeyState = 'not_valid';
                } else {
                    $apiKeyState = 'set_and_valid';
                    $CQ_AI_GatewayCredits = $responseProfile->toArray()['balance'];
                }
            }

            $CQ_AI_GatewayUsername = $this->settingsService->getSettingValue('cqaigateway.username');
            if ($CQ_AI_GatewayUsername === null) {
                $CQ_AI_GatewayUsername = '[cqaigateway.username not set]';//$userRepository->getCQAIGatewayUsername($this->getUser());
            }

            // Check if models need updating and sync if necessary
            if ($this->aiModelsSyncService->shouldUpdateModels()) {
                $this->logger->info('UserSettingsController::aiSettings - Models need updating, syncing from CQ AI Gateway');
                
                try {
                    $syncResult = $this->aiModelsSyncService->syncModels();
                    
                    if ($syncResult['success']) {
                        $this->logger->info('UserSettingsController::aiSettings - Models updated successfully', [
                            'models_count' => count($syncResult['models'] ?? [])
                        ]);
                        
                        if (count($syncResult['models'] ?? []) > 0) {
                            $this->addFlash('success', 'AI models updated successfully from CQ AI Gateway.');
                        }
                    } else {
                        $this->logger->warning('UserSettingsController::aiSettings - Models sync failed', [
                            'error' => $syncResult['message']
                        ]);
                        $this->addFlash('warning', 'Could not update AI models: ' . $syncResult['message']);
                    }
                } catch (\Exception $e) {
                    $this->logger->error('UserSettingsController::aiSettings - Exception during models sync', [
                        'error' => $e->getMessage()
                    ]);
                    $this->addFlash('warning', 'Could not update AI models: ' . $e->getMessage());
                }
            } else {
                $this->logger->info('UserSettingsController::aiSettings - Models are up to date, no sync needed');
            }
            

        }

        // Get all available AI models
        $aiModels = $this->aiServiceModelService->findByGateway($gateway->getId(), true);
        
        // Get image capable models
        $aiImageModels = $this->aiServiceModelService->findImageOutputModelsByGateway($gateway->getId(), true);
        
            return $this->render('user_settings/ai.html.twig', [
                'settings' => $settings,
                'aiModels' => $aiModels,
                'aiImageModels' => $aiImageModels,
                'api_key_state' => $apiKeyState,
                'CQ_AI_GatewayCredits' => ( $CQ_AI_GatewayCredits !== null ) ? round($CQ_AI_GatewayCredits) : '-',
                'CQ_AI_GatewayUsername' => $CQ_AI_GatewayUsername
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('UserSettingsController::aiSettings - Exception occurred', [
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
                'user_id' => $this->getUser()?->getId(),
                'user_username' => $this->getUser()?->getUsername()
            ]);
            
            $this->addFlash('error', 'An error occurred while processing your AI settings. Please try again.');
            
            return $this->render('user_settings/ai.html.twig', [
                'settings' => [],
                'aiModels' => [],
                'aiImageModels' => [],
                'api_key_state' => 'unknown',
                'CQ_AI_GatewayCredits' => '-',
                'CQ_AI_GatewayUsername' => null
            ]);
        }
    }
    
    #[Route('/ai/gateways', name: 'app_user_settings_ai_gateways')]
    public function aiGateways(): Response
    {
        // Get all available AI gateways
        $aiGateways = $this->aiGatewayService->findAll();
        
        return $this->render('user_settings/ai_gateways.html.twig', [
            'aiGateways' => $aiGateways,
        ]);
    }
    
    #[Route('/ai/gateways/add', name: 'app_user_settings_ai_gateways_add', methods: ['POST'])]
    public function addAiGateway(Request $request): Response
    {
        $name = $request->request->get('name');
        $apiEndpointUrl = $request->request->get('apiEndpointUrl');
        $apiKey = $request->request->get('apiKey');
        
        if (!$name || !$apiEndpointUrl || !$apiKey) {
            $this->addFlash('danger', 'All fields are required.');
            return $this->redirectToRoute('app_user_settings_ai_gateways');
        }
        
        // Create new gateway using the service
        $this->aiGatewayService->createGateway(
            $name,
            $apiKey,
            $apiEndpointUrl,
            strtolower($name)
        );
        
        $this->addFlash('success', 'AI gateway added successfully.');
        return $this->redirectToRoute('app_user_settings_ai_gateways');
    }
    
    #[Route('/ai/gateways/edit', name: 'app_user_settings_ai_gateways_edit', methods: ['POST'])]
    public function editAiGateway(Request $request): Response
    {
        $id = $request->request->get('id');
        $name = $request->request->get('name');
        $apiEndpointUrl = $request->request->get('apiEndpointUrl');
        $apiKey = $request->request->get('apiKey');
        
        if (!$id || !$name || !$apiEndpointUrl) {
            $this->addFlash('danger', 'Required fields are missing.');
            return $this->redirectToRoute('app_user_settings_ai_gateways');
        }
        
        // Find the gateway
        $gateway = $this->aiGatewayService->findById($id);
        
        if (!$gateway) {
            $this->addFlash('danger', 'Gateway not found.');
            return $this->redirectToRoute('app_user_settings_ai_gateways');
        }
        
        // Update gateway
        $updateData = [
            'name' => $name,
            'apiEndpointUrl' => $apiEndpointUrl
        ];
        
        // Only update API key if provided
        if ($apiKey) {
            $updateData['apiKey'] = $apiKey;
        }
        
        $this->aiGatewayService->updateGateway($id, $updateData);
        
        $this->addFlash('success', 'AI gateway updated successfully.');
        return $this->redirectToRoute('app_user_settings_ai_gateways');
    }
    
    #[Route('/ai/gateways/delete', name: 'app_user_settings_ai_gateways_delete', methods: ['POST'])]
    public function deleteAiGateway(Request $request): Response
    {
        $id = $request->request->get('id');
        
        if (!$id) {
            $this->addFlash('danger', 'Gateway ID is required.');
            return $this->redirectToRoute('app_user_settings_ai_gateways');
        }
        
        // Find the gateway
        $gateway = $this->aiGatewayService->findById($id);
        
        if (!$gateway) {
            $this->addFlash('danger', 'Gateway not found.');
            return $this->redirectToRoute('app_user_settings_ai_gateways');
        }
        
        // Delete gateway [todo: and its models]
        $this->aiGatewayService->deleteGateway($id);
        
        $this->addFlash('success', 'AI gateway deleted successfully.');
        return $this->redirectToRoute('app_user_settings_ai_gateways');
    }
    
    #[Route('/ai/models', name: 'app_user_settings_ai_models')]
    public function aiModels(): Response
    {
        // Get all available AI models
        $aiModels = $this->aiServiceModelService->findAll();
        
        // Get all available AI gateways for the dropdown
        $aiGateways = $this->aiGatewayService->findAll();
        
        return $this->render('user_settings/ai_models.html.twig', [
            'aiModels' => $aiModels,
            'aiGateways' => $aiGateways,
        ]);
    }
    
    #[Route('/ai/models/add', name: 'app_user_settings_ai_models_add', methods: ['POST'])]
    public function addAiModel(Request $request): Response
    {
        $aiGatewayId = $request->request->get('aiGatewayId');
        $modelName = $request->request->get('modelName');
        $modelSlug = $request->request->get('modelSlug');
        $virtualKey = $request->request->get('virtualKey');
        $contextWindow = $request->request->get('contextWindow');
        $maxInput = $request->request->get('maxInput');
        $maxInputImageSize = $request->request->get('maxInputImageSize');
        $maxOutput = $request->request->get('maxOutput');
        $ppmInput = $request->request->get('ppmInput');
        $ppmOutput = $request->request->get('ppmOutput');
        $isActive = $request->request->getBoolean('isActive');
        
        if (!$aiGatewayId || !$modelName || !$modelSlug) {
            $this->addFlash('danger', 'Gateway, model name, and model slug are required.');
            return $this->redirectToRoute('app_user_settings_ai_models');
        }
        
        // Create new model using the service
        $this->aiServiceModelService->createModel(
            $aiGatewayId,
            $modelName,
            $modelSlug,
            $virtualKey,
            $contextWindow ? (int) $contextWindow : 64000,
            $maxInput,
            $maxInputImageSize,
            $maxOutput ? (int) $maxOutput : 8192,
            $ppmInput ? (float) $ppmInput : null,
            $ppmOutput ? (float) $ppmOutput : null,
            $isActive
        );
        
        $this->addFlash('success', 'AI model added successfully.');
        return $this->redirectToRoute('app_user_settings_ai_models');
    }
    
    #[Route('/ai/models/edit', name: 'app_user_settings_ai_models_edit', methods: ['POST'])]
    public function editAiModel(Request $request): Response
    {
        $id = $request->request->get('id');
        $aiGatewayId = $request->request->get('aiGatewayId');
        $modelName = $request->request->get('modelName');
        $modelSlug = $request->request->get('modelSlug');
        $virtualKey = $request->request->get('virtualKey');
        $contextWindow = $request->request->get('contextWindow');
        $maxInput = $request->request->get('maxInput');
        $maxInputImageSize = $request->request->get('maxInputImageSize');
        $maxOutput = $request->request->get('maxOutput');
        $ppmInput = $request->request->get('ppmInput');
        $ppmOutput = $request->request->get('ppmOutput');
        $isActive = $request->request->getBoolean('isActive');
        
        if (!$id || !$aiGatewayId || !$modelName || !$modelSlug) {
            $this->addFlash('danger', 'Required fields are missing.');
            return $this->redirectToRoute('app_user_settings_ai_models');
        }
        
        // Find the model
        $model = $this->aiServiceModelService->findById($id);
        
        if (!$model) {
            $this->addFlash('danger', 'Model not found.');
            return $this->redirectToRoute('app_user_settings_ai_models');
        }
        
        // Update model
        $updateData = [
            'aiGatewayId' => $aiGatewayId,
            'modelName' => $modelName,
            'modelSlug' => $modelSlug,
            'contextWindow' => $contextWindow ? (int) $contextWindow : 64000,
            'maxInput' => $maxInput,
            'maxInputImageSize' => $maxInputImageSize,
            'maxOutput' => $maxOutput ? (int) $maxOutput : 8192,
            'ppmInput' => $ppmInput ? (float) $ppmInput : null,
            'ppmOutput' => $ppmOutput ? (float) $ppmOutput : null,
            'isActive' => $isActive
        ];
        
        // Only update virtual key if provided
        if ($virtualKey) {
            $updateData['virtualKey'] = $virtualKey;
        }
        
        $this->aiServiceModelService->updateModel($id, $updateData);
        
        $this->addFlash('success', 'AI model updated successfully.');
        return $this->redirectToRoute('app_user_settings_ai_models');
    }
    
    #[Route('/ai/models/delete', name: 'app_user_settings_ai_models_delete', methods: ['POST'])]
    public function deleteAiModel(Request $request): Response
    {
        $id = $request->request->get('id');
        
        if (!$id) {
            $this->addFlash('danger', 'Model ID is required.');
            return $this->redirectToRoute('app_user_settings_ai_models');
        }
        
        // Find the model
        $model = $this->aiServiceModelService->findById($id);
        
        if (!$model) {
            $this->addFlash('danger', 'Model not found.');
            return $this->redirectToRoute('app_user_settings_ai_models');
        }
        
        // Check if this model is in use by the user's settings
        $settings = $this->settingsService->getAllSettings();
        if ($settings && 
            ($settings['ai.primary_ai_service_model_id'] === $id || $settings['ai.secondary_ai_service_model_id'] === $id)) {
            $this->addFlash('danger', 'This model is currently in use in your AI settings. Please select a different model in AI Services settings first.');
            return $this->redirectToRoute('app_user_settings_ai_models');
        }
        
        // Delete model
        $this->aiServiceModelService->deleteModel($id);
        
        $this->addFlash('success', 'AI model deleted successfully.');
        return $this->redirectToRoute('app_user_settings_ai_models');
    }
}
