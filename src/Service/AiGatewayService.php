<?php

namespace App\Service;

use App\Entity\AiGateway;
use App\Entity\AiServiceModel;
use App\Entity\AiServiceRequest;
use App\Entity\AiServiceResponse;
use App\Entity\AiServiceUseLog;
use App\Entity\User;
use App\Service\UserDatabaseManager;
use App\Service\PortkeyAiGateway;
use App\Service\AnthropicGateway;
use App\Service\GroqGateway;
use App\Service\AiGatewayInterface;
use App\Service\AiUserSettingsService;
use App\Service\AiServiceUseLogService;
use App\Service\AiServiceResponseService;
use App\Service\AiServiceRequestService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

class AiGatewayService
{
    private array $gatewayImplementations = [];
    private ?PortkeyAiGateway $portkeyGateway = null;
    private ?AnthropicGateway $anthropicGateway = null;
    private ?GroqGateway $groqGateway = null;
    
    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly Security $security,
        private readonly ServiceLocator $serviceLocator,
        ?PortkeyAiGateway $portkeyGateway = null,
        ?AnthropicGateway $anthropicGateway = null,
        ?GroqGateway $groqGateway = null
    ) {
        $this->portkeyGateway = $portkeyGateway;
        $this->anthropicGateway = $anthropicGateway;
        $this->groqGateway = $groqGateway;
        
        // Register gateway implementations
        $this->registerGatewayImplementation('portkey', 'portkey');
        $this->registerGatewayImplementation('anthropic', 'anthropic');
        $this->registerGatewayImplementation('groq', 'groq');
    }
    
    /**
     * Get a fresh database connection for the current user
     */
    private function getUserDb()
    {
        /** @var User $user */
        $user = $this->security->getUser();
        return $this->userDatabaseManager->getDatabaseConnection($user);
    }

    /**
     * Create a new AI gateway
     */
    public function createGateway(
        string $name,
        string $apiKey,
        string $apiEndpointUrl,
        string $type = 'portkey'
    ): AiGateway {
        $gateway = new AiGateway($name, $apiKey, $apiEndpointUrl);
        //$gateway->setType($type);

        // Store in user's database
        $userDb = $this->getUserDb();
        $userDb->executeStatement(
            'INSERT INTO ai_gateway (id, name, api_key, api_endpoint_url, created_at, updated_at) 
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $gateway->getId(),
                $gateway->getName(),
                $gateway->getApiKey(),
                $gateway->getApiEndpointUrl(),
                $gateway->getCreatedAt()->format('Y-m-d H:i:s'),
                $gateway->getUpdatedAt()->format('Y-m-d H:i:s')
            ]
        );

        return $gateway;
    }

    public function findById(string $id): ?AiGateway
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT * FROM ai_gateway WHERE id = ?',
            [$id]
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        return AiGateway::fromArray($result);
    }

    public function findAll(): array
    {
        $userDb = $this->getUserDb();
        $results = $userDb->executeQuery(
            'SELECT * FROM ai_gateway ORDER BY name ASC'
        )->fetchAllAssociative();

        return array_map(fn($data) => AiGateway::fromArray($data), $results);
    }

    public function updateGateway(
        string $id,
        array $data
    ): ?AiGateway {
        $gateway = $this->findById($id);
        if (!$gateway) {
            return null;
        }

        if (isset($data['name'])) {
            $gateway->setName($data['name']);
        }

        if (isset($data['apiKey'])) {
            $gateway->setApiKey($data['apiKey']);
        }

        if (isset($data['apiEndpointUrl'])) {
            $gateway->setApiEndpointUrl($data['apiEndpointUrl']);
        }

        $gateway->setUpdatedAt(new \DateTime());

        $userDb = $this->getUserDb();
        $userDb->executeStatement(
            'UPDATE ai_gateway SET 
             name = ?, 
             api_key = ?, 
             api_endpoint_url = ?, 
             updated_at = ? 
             WHERE id = ?',
            [
                $gateway->getName(),
                $gateway->getApiKey(),
                $gateway->getApiEndpointUrl(),
                $gateway->getUpdatedAt()->format('Y-m-d H:i:s'),
                $gateway->getId()
            ]
        );

        return $gateway;
    }

    public function deleteGateway(string $id): bool
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeStatement(
            'DELETE FROM ai_gateway WHERE id = ?',
            [$id]
        );

        return $result > 0;
    }
    
    /**
     * Register a gateway implementation
     */
    public function registerGatewayImplementation(string $type, string $serviceId): void
    {
        $this->gatewayImplementations[$type] = $serviceId;
    }
    
    /**
     * Get the gateway implementation for a specific type
     */
    private function getGatewayImplementation(string $type): ?AiGatewayInterface
    {
        if (!isset($this->gatewayImplementations[$type])) {
            return null;
        }
        
        if ($type === 'portkey' && $this->portkeyGateway) {
            return $this->portkeyGateway;
        }
        
        if ($type === 'anthropic' && $this->anthropicGateway) {
            return $this->anthropicGateway;
        }
        
        if ($type === 'groq' && $this->groqGateway) {
            return $this->groqGateway;
        }
        
        return null;
    }
    
    /**
     * Get available models from a gateway
     * 
     * @param string $gatewayId The ID of the gateway
     * @return array List of available models
     * @throws \Exception If gateway not found or implementation not available
     */
    public function getAvailableModels(string $gatewayId): array
    {
        $gateway = $this->findById($gatewayId);
        
        if (!$gateway) {
            throw new \Exception('Gateway not found');
        }
        
        $type = $gateway->getType();
        $implementation = $this->getGatewayImplementation($type);
        
        if (!$implementation) {
            throw new \Exception('No implementation found for gateway type: ' . $type);
        }
        
        return $implementation->getAvailableModels($gateway);
    }
    
    /**
     * Get available tools for AI services
     * 
     * @param string $gatewayId The ID of the gateway
     * @return array List of available tools
     */
    public function getAvailableTools(string $gatewayId): array
    {
        $gateway = $this->findById($gatewayId);
        
        if (!$gateway) {
            throw new \Exception('Gateway not found');
        }
        
        $type = $gateway->getType();
        $implementation = $this->getGatewayImplementation($type);
        
        if (!$implementation) {
            throw new \Exception('No implementation found for gateway type: ' . $type);
        }
        
        return $implementation->getAvailableTools();
    }
    
    /**
     * Get the primary AI service model for a user
     */
    public function getPrimaryAiServiceModel(): ?AiServiceModel
    {
        $aiUserSettingsService = $this->serviceLocator->get(AiUserSettingsService::class);
        // Try to get from user settings first
        if ($aiUserSettingsService) {
            $settings = $aiUserSettingsService->findForUser();
            if ($settings && $settings->getPrimaryAiServiceModelId()) {
                return $this->getAiServiceModel($settings->getPrimaryAiServiceModelId());
            }
        }
        
        // Fallback to the first model if no settings found
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT * FROM ai_service_model ORDER BY created_at ASC LIMIT 1'
        )->fetchAssociative();
        
        if (!$result) {
            return null;
        }
        
        return AiServiceModel::fromArray($result);
    }

    public function getSecondaryAiServiceModel(): ?AiServiceModel
    {
        $aiUserSettingsService = $this->serviceLocator->get(AiUserSettingsService::class);
        // Try to get from user settings first
        if ($aiUserSettingsService) {
            $settings = $aiUserSettingsService->findForUser();
            if ($settings && $settings->getSecondaryAiServiceModelId()) {
                return $this->getAiServiceModel($settings->getSecondaryAiServiceModelId());
            }
        }
        
        // Fallback to the first model if no settings found
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT * FROM ai_service_model ORDER BY created_at ASC LIMIT 1'
        )->fetchAssociative();
        
        if (!$result) {
            return null;
        }
        
        return AiServiceModel::fromArray($result);
    }
    
    /**
     * Send a request to an AI service
     */
    public function sendRequest(AiServiceRequest $request, string $purpose, string $lang = 'English'): AiServiceResponse
    {
        $aiServiceResponseService = $this->serviceLocator->get(AiServiceResponseService::class);
        
        // Get the model
        $aiServiceModel = $this->getAiServiceModel($request->getAiServiceModelId());
        if (!$aiServiceModel) {
            throw new \Exception('AI Service Model not found');
        }
        
        // Get the gateway
        $aiGateway = $this->findById($aiServiceModel->getAiGatewayId());
        if (!$aiGateway) {
            throw new \Exception('AI Gateway not found');
        }
        
        // Get the gateway implementation
        $gatewayImplementation = $this->getGatewayImplementation($aiGateway->getType());
        if (!$gatewayImplementation) {
            throw new \Exception('Gateway implementation not found for type: ' . $aiGateway->getType());
        }
        
        // Send the request
        $response = $gatewayImplementation->sendRequest($request, $this);

        // Save the response
        $response = $aiServiceResponseService->createResponse(
            $request->getId(),
            $response->getMessage(),
            $response->getFullResponse(),
            $response->getFinishReason(),
            $response->getInputTokens(),
            $response->getOutputTokens(),
            $response->getTotalTokens()
        );
        
        // Log the service use
        $this->logServiceUse($purpose, $aiGateway, $aiServiceModel, $request, $response);
        
        // Handle tool calls
        $response = $gatewayImplementation->handleToolCalls($request, $response, $lang);

        return $response;
    }
    
    /**
     * Get an AI service model by ID
     */
    public function getAiServiceModel(string $modelId): ?AiServiceModel
    {
        $userDb = $this->getUserDb();
        $data = $userDb->executeQuery(
            'SELECT * FROM ai_service_model WHERE id = ?',
            [$modelId]
        )->fetchAssociative();
        
        if (!$data) {
            return null;
        }
        
        return AiServiceModel::fromArray($data);
    }
    
    /**
     * Log the AI service usage
     * TODO: move this to AiServiceUseLogService
     */
    public function logServiceUse(string $purpose, AiGateway $aiGateway, AiServiceModel $aiServiceModel, AiServiceRequest $request, AiServiceResponse $response): void
    {
        $aiServiceUseLogService = $this->serviceLocator->get(AiServiceUseLogService::class);
        
        $useLog = new AiServiceUseLog(
            $aiGateway->getId(),
            $aiServiceModel->getId(),
            $request->getId(),
            $response->getId()
        );
        
        $useLog->setPurpose($purpose);
        $useLog->setInputTokens($response->getInputTokens());
        $useLog->setOutputTokens($response->getOutputTokens());
        $useLog->setTotalTokens($response->getTotalTokens());
        
        // Calculate prices based on model pricing [InputPrice = price per million tokens]
        if ($response->getInputTokens() !== null && $aiServiceModel->getPpmInput() !== null) {
            $inputPrice = ($response->getInputTokens() / 1000000) * $aiServiceModel->getPpmInput();
            $useLog->setInputPrice($inputPrice);
        }
        
        if ($response->getOutputTokens() !== null && $aiServiceModel->getPpmOutput() !== null) {
            $outputPrice = ($response->getOutputTokens() / 1000000) * $aiServiceModel->getPpmOutput();
            $useLog->setOutputPrice($outputPrice);
        }
        
        // Calculate total price
        $totalPrice = ($useLog->getInputPrice() ?? 0) + ($useLog->getOutputPrice() ?? 0);
        $useLog->setTotalPrice($totalPrice);
        
        // Save the log
        $aiServiceUseLogService->createLog(
            $useLog->getAiGatewayId(),
            $useLog->getAiServiceModelId(),
            $useLog->getAiServiceRequestId(),
            $useLog->getAiServiceResponseId(),
            $useLog->getPurpose(),
            $useLog->getInputTokens(),
            $useLog->getOutputTokens(),
            $useLog->getTotalTokens(),
            $useLog->getInputPrice(),
            $useLog->getOutputPrice(),
            $useLog->getTotalPrice()
        );
    }
}
