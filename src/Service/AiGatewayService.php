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
use App\Service\AiGatewayInterface;
use App\Service\AiUserSettingsService;
use App\Service\AiServiceUseLogService;
use App\Service\AiServiceResponseService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

class AiGatewayService
{
    private array $gatewayImplementations = [];
    private ?PortkeyAiGateway $portkeyGateway = null;
    
    private ?AiServiceUseLogService $aiServiceUseLogService = null;
    private ?AiUserSettingsService $aiUserSettingsService = null;
    private ?AiServiceResponseService $aiServiceResponseService = null;
    
    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly Security $security,
        ?PortkeyAiGateway $portkeyGateway = null
    ) {
        $this->portkeyGateway = $portkeyGateway;
        // Register gateway implementations
        $this->registerGatewayImplementation('portkey', 'portkey');
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
     * Set the AI service use log service
     */
    public function setAiServiceUseLogService(?AiServiceUseLogService $aiServiceUseLogService): void
    {
        $this->aiServiceUseLogService = $aiServiceUseLogService;
    }
    
    /**
     * Set the AI user settings service
     */
    public function setAiUserSettingsService(?AiUserSettingsService $aiUserSettingsService): void
    {
        $this->aiUserSettingsService = $aiUserSettingsService;
    }

    /**
     * Set the AI service response service
     */
    public function setAiServiceResponseService(?AiServiceResponseService $aiServiceResponseService): void
    {
        $this->aiServiceResponseService = $aiServiceResponseService;
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
        
        return null;
    }
    
    /**
     * Get the primary AI gateway for a user
     */
    public function getPrimaryAiGateway(): ?AiGateway
    {
        // Try to get from user settings first
        if ($this->aiUserSettingsService) {
            $settings = $this->aiUserSettingsService->findForUser();
            if ($settings && $settings->getAiGatewayId()) {
                return $this->findById($settings->getAiGatewayId());
            }
        }
        
        // Fallback to the first gateway if no settings found
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT * FROM ai_gateway ORDER BY created_at ASC LIMIT 1'
        )->fetchAssociative();
        
        if (!$result) {
            return null;
        }
        
        return AiGateway::fromArray($result);
    }
    
    /**
     * Get the primary AI service model for a user
     */
    public function getPrimaryAiServiceModel(): ?AiServiceModel
    {
        // Try to get from user settings first
        if ($this->aiUserSettingsService) {
            $settings = $this->aiUserSettingsService->findForUser();
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
    
    /**
     * Send a request to an AI service
     */
    public function sendRequest(AiServiceRequest $request, string $purpose): AiServiceResponse
    {
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
        $gatewayImplementation = $this->getGatewayImplementation($aiGateway->getType() ?? 'portkey');
        if (!$gatewayImplementation) {
            throw new \Exception('Gateway implementation not found for type: ' . ($aiGateway->getType() ?? 'portkey'));
        }
        
        // Send the request
        $response = $gatewayImplementation->sendRequest($aiGateway, $aiServiceModel, $request);

        // Save the response
        $response = $this->aiServiceResponseService->createResponse(
            $request->getId(),
            $response->getMessage(),
            $response->getFinishReason(),
            $response->getInputTokens(),
            $response->getOutputTokens(),
            $response->getTotalTokens()
        );
        
        // Log the service use
        $this->logServiceUse($purpose, $aiGateway, $aiServiceModel, $request, $response);
        
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
        // Skip logging if the service is not set
        if (!$this->aiServiceUseLogService) {
            return;
        }
        
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
        $this->aiServiceUseLogService->createLog(
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
