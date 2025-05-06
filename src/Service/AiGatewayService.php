<?php

namespace App\Service;

use App\Entity\AiGateway;
use App\Entity\AiServiceModel;
use App\Entity\AiServiceRequest;
use App\Entity\AiServiceResponse;
use App\Entity\AiServiceUseLog;
use App\Entity\User;
use App\Service\UserDatabaseManager;
use App\Service\AnthropicGateway;
use App\Service\GroqGateway;
use App\Service\CQAIGateway;
use App\Service\AiGatewayInterface;
use App\Service\AiServiceUseLogService;
use App\Service\AiServiceResponseService;
use App\Service\AiServiceRequestService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

class AiGatewayService
{
    private array $gatewayImplementations = [];
    private ?AnthropicGateway $anthropicGateway = null;
    private ?GroqGateway $groqGateway = null;
    private ?CQAIGateway $CQAIGateway = null;
    
    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly Security $security,
        private readonly ServiceLocator $serviceLocator,
        ?AnthropicGateway $anthropicGateway = null,
        ?GroqGateway $groqGateway = null,
        ?CQAIGateway $CQAIGateway = null
    ) {
        $this->anthropicGateway = $anthropicGateway;
        $this->groqGateway = $groqGateway;
        $this->CQAIGateway = $CQAIGateway;
        
        // Register gateway implementations
        $this->registerGatewayImplementation('anthropic');
        $this->registerGatewayImplementation('groq');
        $this->registerGatewayImplementation('cqaigateway');
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
        string $type = ''
    ): AiGateway {
        $gateway = new AiGateway($name, $apiKey, $apiEndpointUrl);

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

        if (isset($data['apiKey']) && $data['apiKey'] !== '') {
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
    public function registerGatewayImplementation(string $type): void
    {
        $this->gatewayImplementations[$type] = $type;
    }
    
    /**
     * Get the gateway implementation for a specific type
     */
    private function getGatewayImplementation(string $type): ?AiGatewayInterface
    {
        if (!isset($this->gatewayImplementations[$type])) {
            return null;
        }
        
        if ($type === 'anthropic' && $this->anthropicGateway) {
            return $this->anthropicGateway;
        }
        
        if ($type === 'groq' && $this->groqGateway) {
            return $this->groqGateway;
        }
        
        if ($type === 'cqaigateway' && $this->CQAIGateway) {
            return $this->CQAIGateway;
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
     * Get the primary AI service model for a user, from `settings`
     */
    public function getPrimaryAiServiceModel(): ?AiServiceModel
    {
        $settingsService = $this->serviceLocator->get(SettingsService::class);

        $primaryAiServiceModelId = $settingsService->getSettingValue('ai.primary_ai_service_model_id');
        if ($primaryAiServiceModelId) {
            $aiServiceModelService = $this->serviceLocator->get(AiServiceModelService::class);
            return $aiServiceModelService->findById($primaryAiServiceModelId);
        } else {
            return null;
        }
    }

    public function getSecondaryAiServiceModel(): ?AiServiceModel
    {
        $settingsService = $this->serviceLocator->get(SettingsService::class);
        
        $secondaryAiServiceModelId = $settingsService->getSettingValue('ai.secondary_ai_service_model_id');
        if ($secondaryAiServiceModelId) {
            $aiServiceModelService = $this->serviceLocator->get(AiServiceModelService::class);
            return $aiServiceModelService->findById($secondaryAiServiceModelId);
        } else {
            return null;
        }
    }
    
    /**
     * Send a request to an AI service
     */
    public function sendRequest(AiServiceRequest $request, string $purpose, string $lang = 'English'): AiServiceResponse
    {
        $aiServiceResponseService = $this->serviceLocator->get(AiServiceResponseService::class);
        $aiServiceModelService = $this->serviceLocator->get(AiServiceModelService::class);
        
        // Get the model
        $aiServiceModel = $aiServiceModelService->findById($request->getAiServiceModelId());
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
