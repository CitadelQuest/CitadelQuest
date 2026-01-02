<?php

namespace App\Service;

use App\CitadelVersion;
use App\Entity\User;
use App\Entity\AiGateway;
use App\Entity\AiServiceModel;
use App\Service\AiGatewayService;
use App\Service\AiServiceModelService;
use App\Service\SettingsService;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for synchronizing AI models from CQ AI Gateway
 * Centralizes the logic for fetching, validating, and updating AI models
 */
class AiModelsSyncService
{
    private const MODELS_CACHE_DURATION_HOURS = 2; // 2 hours
    private const SETTINGS_KEY_LAST_UPDATE = 'ai_models_list.updated_at';
    private const SETTINGS_KEY_MODELS_COUNT = 'ai_models_list.count';
    
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AiGatewayService $aiGatewayService,
        private readonly AiServiceModelService $aiServiceModelService,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger
    ) {}
    
    /**
     * Check if AI models need to be updated
     * 
     * @return bool True if models should be refreshed
     */
    public function shouldUpdateModels(): bool
    {
        $lastUpdate = $this->settingsService->getSettingValue(self::SETTINGS_KEY_LAST_UPDATE);
        
        if (!$lastUpdate) {
            $this->logger->info('AiModelsSyncService: No previous update timestamp found, update needed');
            return true;
        }
        
        $lastUpdateTime = new \DateTime($lastUpdate);
        $now = new \DateTime();
        $hoursSinceUpdate = ($now->getTimestamp() - $lastUpdateTime->getTimestamp()) / 3600;
        
        $shouldUpdate = $hoursSinceUpdate >= self::MODELS_CACHE_DURATION_HOURS;
        
        $this->logger->info('AiModelsSyncService: Checking if models need update', [
            'last_update' => $lastUpdate,
            'hours_since_update' => round($hoursSinceUpdate, 2),
            'cache_duration_hours' => self::MODELS_CACHE_DURATION_HOURS,
            'should_update' => $shouldUpdate
        ]);
        
        return $shouldUpdate;
    }
    
    /**
     * Synchronize AI models from CQ AI Gateway
     * 
     * @return array Result with success status, message, and data
     */
    public function syncModels(?User $user = null): array
    {
        $this->logger->info('AiModelsSyncService: Starting models synchronization');

        if ($user) {
            $this->settingsService->setUser($user);
        }
        
        try {
            // Get 'CQ AI Gateway' gateway
            $gateway = $this->aiGatewayService->findByName('CQ AI Gateway');
            if (!$gateway) {
                return [
                    'success' => false,
                    'message' => 'No CQ AI Gateway configured',
                    'error_code' => 'NO_GATEWAY'
                ];
            }
            
            // Fetch models from API
            $modelsData = $this->fetchModelsFromApi($gateway);
            if (!$modelsData['success']) {
                return $modelsData;
            }

            // Set all models inactive first
            $this->aiServiceModelService->setAllModelsInactive($gateway->getId());
            
            // Process and store models
            $result = $this->processAndStoreModels($gateway, $modelsData['models']);
            
            if ($result['success']) {
                // Update cache timestamp
                $this->settingsService->setSetting(self::SETTINGS_KEY_LAST_UPDATE, (new \DateTime())->format('Y-m-d H:i:s'));
                $this->settingsService->setSetting(self::SETTINGS_KEY_MODELS_COUNT, count($result['models']));
                
                // Set primary AI Model, if not set
                $primaryAiServiceModelId = $this->settingsService->getSettingValue('ai.primary_ai_service_model_id');
                if (!$primaryAiServiceModelId) {
                    $defaultPrimaryAiModel = $this->aiServiceModelService->getDefaultPrimaryAiModelByGateway($gateway->getId());
                    if ($defaultPrimaryAiModel) {
                        $this->settingsService->setSetting('ai.primary_ai_service_model_id', $defaultPrimaryAiModel->getId());
                    }
                }

                // Set secondary AI Model, if not set
                $secondaryAiServiceModelId = $this->settingsService->getSettingValue('ai.secondary_ai_service_model_id');
                if (!$secondaryAiServiceModelId) {
                    $defaultSecondaryAiModel = $this->aiServiceModelService->getDefaultSecondaryAiModelByGateway($gateway->getId());
                    if ($defaultSecondaryAiModel) {
                        $this->settingsService->setSetting('ai.secondary_ai_service_model_id', $defaultSecondaryAiModel->getId());
                    }
                }

                $this->logger->info('AiModelsSyncService: Models synchronization completed successfully', [
                    'models_count' => count($result['models'])
                ]);
            }
                        
            return $result;
            
        } catch (\Exception $e) {
            $this->logger->error('AiModelsSyncService: Exception during models synchronization', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'message' => 'Error synchronizing models: ' . $e->getMessage(),
                'error_code' => 'SYNC_EXCEPTION'
            ];
        }
    }
    
    /**
     * Fetch models from CQ AI Gateway API
     */
    private function fetchModelsFromApi(AiGateway $gateway): array
    {
        $this->logger->info('AiModelsSyncService: Fetching models from API', [
            'gateway_id' => $gateway->getId(),
            'api_endpoint' => $gateway->getApiEndpointUrl()
        ]);
        
        try {
            $response = $this->httpClient->request(
                'GET',
                $gateway->getApiEndpointUrl() . '/ai/models',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $gateway->getApiKey(),
                        'User-Agent' => 'CitadelQuest ' . CitadelVersion::VERSION . ' HTTP Client',
                        'Content-Type' => 'application/json',
                    ],
                    'timeout' => 30
                ]
            );
            
            $statusCode = $response->getStatusCode(false);
            $this->logger->info('AiModelsSyncService: Received API response', [
                'status_code' => $statusCode
            ]);
            
            if ($statusCode !== Response::HTTP_OK) {
                return $this->handleApiError($statusCode);
            }
            
            $responseData = json_decode($response->getContent(), true);
            $models = $responseData['models'] ?? [];
            
            if (!is_array($models) || count($models) === 0) {
                $this->logger->warning('AiModelsSyncService: No models found in API response');
                return [
                    'success' => false,
                    'message' => 'No models found on CQ AI Gateway',
                    'error_code' => 'NO_MODELS'
                ];
            }
            
            $this->logger->info('AiModelsSyncService: Successfully fetched models', [
                'models_count' => count($models)
            ]);
            
            return [
                'success' => true,
                'models' => $models
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('AiModelsSyncService: API request failed', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to fetch models from CQ AI Gateway: ' . $e->getMessage(),
                'error_code' => 'API_REQUEST_FAILED'
            ];
        }
    }
    
    /**
     * Handle API error responses
     */
    private function handleApiError(int $statusCode): array
    {
        $this->logger->warning('AiModelsSyncService: API returned error status', [
            'status_code' => $statusCode
        ]);
        
        switch ($statusCode) {
            case Response::HTTP_UNAUTHORIZED:
                return [
                    'success' => false,
                    'message' => 'Invalid API key',
                    'error_code' => 'INVALID_API_KEY'
                ];
                
            case Response::HTTP_SERVICE_UNAVAILABLE:
            case Response::HTTP_GATEWAY_TIMEOUT:
            case Response::HTTP_INTERNAL_SERVER_ERROR:
            case Response::HTTP_BAD_GATEWAY:
            case Response::HTTP_TOO_MANY_REQUESTS:
            case Response::HTTP_NOT_FOUND:
                return [
                    'success' => false,
                    'message' => 'CQ AI Gateway is currently unavailable, please try again later',
                    'error_code' => 'GATEWAY_UNAVAILABLE'
                ];
                
            default:
                return [
                    'success' => false,
                    'message' => 'Failed to validate API key (HTTP ' . $statusCode . ')',
                    'error_code' => 'UNEXPECTED_HTTP_STATUS'
                ];
        }
    }
    
    /**
     * Process and store models in database
     */
    private function processAndStoreModels(AiGateway $gateway, array $modelsData): array
    {
        $this->logger->info('AiModelsSyncService: Processing and storing models', [
            'gateway_id' => $gateway->getId(),
            'models_count' => count($modelsData)
        ]);
        
        $processedModels = [];
        $firstModelId = null;
        
        foreach ($modelsData as $model) {
            if (!isset($model['id'])) {
                continue;
            }
            
            $modelSlug = $model['id'];
            $modelSlugProvider = substr($modelSlug, 0, strpos($modelSlug, '/'));
            
            // Only process CitadelQuest models (following existing logic), with ALL CQ AI Gateway models
            if ($modelSlugProvider === 'citadelquest' /*&& (strpos($modelSlug, '-') === false)*/) {
                $maxOutputTokens = $model['top_provider']['max_completion_tokens'] ?? null;
                $pricingInput = $model['pricing']['prompt'] ?? null;
                $pricingOutput = $model['pricing']['completion'] ?? null;
                
                // Check if model already exists
                $existingModel = $this->aiServiceModelService->findByModelSlug($modelSlug, $gateway->getId());
                
                if ($existingModel) {
                    // Update existing model
                    $updatedModel = $this->aiServiceModelService->updateModel(
                        $existingModel->getId(),
                        [
                            'name' => $model['name'],
                            'contextLength' => $model['context_length'],
                            'maxInput' => $model['context_length'],
                            'maxOutput' => $maxOutputTokens,
                            'ppmInput' => $pricingInput,
                            'ppmOutput' => $pricingOutput,
                            'isActive' => true,
                            'fullConfig' => $model
                        ]
                    );
                    $processedModels[] = $updatedModel;
                    $this->logger->debug('AiModelsSyncService: Updated existing model', [
                        'model_id' => $existingModel->getId(),
                        'model_slug' => $modelSlug
                    ]);
                } else {
                    // Create new model
                    $newModel = $this->aiServiceModelService->createModel(
                        $gateway->getId(),
                        $model['name'],
                        $modelSlug,
                        null, // virtual key = null, deprecated
                        $model['context_length'],
                        $model['context_length'],
                        0, // maxInputImageSize
                        $maxOutputTokens,
                        $pricingInput,
                        $pricingOutput,
                        true,// is active
                        $model // fullConfig
                    );
                    $processedModels[] = $newModel;
                    $this->logger->debug('AiModelsSyncService: Created new model', [
                        'model_id' => $newModel->getId(),
                        'model_slug' => $modelSlug
                    ]);
                }
                
                // Remember first model for setting as primary
                if ($firstModelId === null && !empty($processedModels)) {
                    $firstModelId = $processedModels[0]->getId();
                }
            }
        }        
        
        return [
            'success' => true,
            'message' => 'Models synchronized successfully',
            'models' => $processedModels,
            'gateway' => [
                'id' => $gateway->getId(),
                'name' => $gateway->getName()
            ]
        ];
    }
    
    /**
     * Get models sync status information
     */
    public function getSyncStatus(): array
    {
        $lastUpdate = $this->settingsService->getSettingValue(self::SETTINGS_KEY_LAST_UPDATE);
        $modelsCount = $this->settingsService->getSettingValue(self::SETTINGS_KEY_MODELS_COUNT, 0);
        
        return [
            'last_update' => $lastUpdate,
            'models_count' => $modelsCount,
            'should_update' => $this->shouldUpdateModels(),
            'cache_duration_hours' => self::MODELS_CACHE_DURATION_HOURS
        ];
    }
}
