<?php

namespace App\Service;

use App\Entity\AiServiceUseLog;
use App\Service\UserDatabaseManager;
use App\Service\AiGatewayService;
use App\Service\AiServiceModelService;
use App\Service\AiServiceRequestService;
use App\Service\AiServiceResponseService;
use Symfony\Bundle\SecurityBundle\Security;

class AiServiceUseLogService
{
    private ?AiGatewayService $aiGatewayService = null;
    
    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly AiServiceModelService $aiServiceModelService,
        private readonly AiServiceRequestService $aiServiceRequestService,
        private readonly AiServiceResponseService $aiServiceResponseService,
        private readonly Security $security
    ) {
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
     * Set the AI gateway service
     */
    public function setAiGatewayService(AiGatewayService $aiGatewayService): void
    {
        $this->aiGatewayService = $aiGatewayService;
    }

    public function createLog(
        string $aiGatewayId,
        string $aiServiceModelId,
        string $aiServiceRequestId,
        string $aiServiceResponseId,
        ?string $purpose = null,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?int $totalTokens = null,
        ?float $inputPrice = null,
        ?float $outputPrice = null,
        ?float $totalPrice = null
    ): AiServiceUseLog {
        $log = new AiServiceUseLog(
            $aiGatewayId,
            $aiServiceModelId,
            $aiServiceRequestId,
            $aiServiceResponseId
        );
        
        if ($purpose !== null) {
            $log->setPurpose($purpose);
        }
        
        if ($inputTokens !== null) {
            $log->setInputTokens($inputTokens);
        }
        
        if ($outputTokens !== null) {
            $log->setOutputTokens($outputTokens);
        }
        
        if ($totalTokens !== null) {
            $log->setTotalTokens($totalTokens);
        }
        
        if ($inputPrice !== null) {
            $log->setInputPrice($inputPrice);
        }
        
        if ($outputPrice !== null) {
            $log->setOutputPrice($outputPrice);
        }
        
        if ($totalPrice !== null) {
            $log->setTotalPrice($totalPrice);
        }

        // Store in user's database
        $userDb = $this->getUserDb();
        $userDb->executeStatement(
            'INSERT INTO ai_service_use_log (
                id, ai_gateway_id, ai_service_model_id, ai_service_request_id, ai_service_response_id,
                purpose, input_tokens, output_tokens, total_tokens,
                input_price, output_price, total_price, created_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $log->getId(),
                $log->getAiGatewayId(),
                $log->getAiServiceModelId(),
                $log->getAiServiceRequestId(),
                $log->getAiServiceResponseId(),
                $log->getPurpose(),
                $log->getInputTokens(),
                $log->getOutputTokens(),
                $log->getTotalTokens(),
                $log->getInputPrice(),
                $log->getOutputPrice(),
                $log->getTotalPrice(),
                $log->getCreatedAt()->format('Y-m-d H:i:s')
            ]
        );

        return $log;
    }

    public function findById(string $id): ?AiServiceUseLog
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT * FROM ai_service_use_log WHERE id = ?',
            [$id]
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        $log = AiServiceUseLog::fromArray($result);
        
        // Load related entities
        if ($this->aiGatewayService) {
            $gateway = $this->aiGatewayService->findById($log->getAiGatewayId());
            if ($gateway) {
                $log->setAiGateway($gateway);
            }
        }
        
        $model = $this->aiServiceModelService->findById($log->getAiServiceModelId());
        if ($model) {
            $log->setAiServiceModel($model);
        }
        
        $request = $this->aiServiceRequestService->findById($log->getAiServiceRequestId());
        if ($request) {
            $log->setAiServiceRequest($request);
        }
        
        $response = $this->aiServiceResponseService->findById($log->getAiServiceResponseId());
        if ($response) {
            $log->setAiServiceResponse($response);
        }

        return $log;
    }

    public function findByGateway(string $gatewayId, int $limit = 100): array
    {
        $userDb = $this->getUserDb();
        $results = $userDb->executeQuery(
            'SELECT * FROM ai_service_use_log WHERE ai_gateway_id = ? ORDER BY created_at DESC LIMIT ?',
            [$gatewayId, $limit]
        )->fetchAllAssociative();

        return array_map(fn($data) => AiServiceUseLog::fromArray($data), $results);
    }

    public function findByModel(string $modelId, int $limit = 100): array
    {
        $userDb = $this->getUserDb();
        $results = $userDb->executeQuery(
            'SELECT * FROM ai_service_use_log WHERE ai_service_model_id = ? ORDER BY created_at DESC LIMIT ?',
            [$modelId, $limit]
        )->fetchAllAssociative();

        return array_map(fn($data) => AiServiceUseLog::fromArray($data), $results);
    }

    public function findByPurpose(string $purpose, int $limit = 100): array
    {
        $userDb = $this->getUserDb();
        $results = $userDb->executeQuery(
            'SELECT * FROM ai_service_use_log WHERE purpose LIKE ? ORDER BY created_at DESC LIMIT ?',
            ['%' . $purpose . '%', $limit]
        )->fetchAllAssociative();

        return array_map(fn($data) => AiServiceUseLog::fromArray($data), $results);
    }

    public function findRecent(int $limit = 100): array
    {
        $userDb = $this->getUserDb();
        $results = $userDb->executeQuery(
            'SELECT * FROM ai_service_use_log ORDER BY created_at DESC LIMIT ?',
            [$limit]
        )->fetchAllAssociative();

        return array_map(fn($data) => AiServiceUseLog::fromArray($data), $results);
    }

    public function getUsageSummary(?\DateTimeInterface $startDate = null, ?\DateTimeInterface $endDate = null): array
    {
        $userDb = $this->getUserDb();
        $query = 'SELECT 
            SUM(input_tokens) as total_input_tokens, 
            SUM(output_tokens) as total_output_tokens, 
            SUM(total_tokens) as total_tokens,
            SUM(input_price) as total_input_price, 
            SUM(output_price) as total_output_price, 
            SUM(total_price) as total_price,
            COUNT(*) as total_requests
            FROM ai_service_use_log';
        
        $params = [];
        $conditions = [];
        
        if ($startDate) {
            $conditions[] = 'created_at >= ?';
            $params[] = $startDate->format('Y-m-d H:i:s');
        }
        
        if ($endDate) {
            $conditions[] = 'created_at <= ?';
            $params[] = $endDate->format('Y-m-d H:i:s');
        }
        
        if (!empty($conditions)) {
            $query .= ' WHERE ' . implode(' AND ', $conditions);
        }
        
        $result = $userDb->executeQuery($query, $params)->fetchAssociative();

        return $result ?: [
            'total_input_tokens' => 0,
            'total_output_tokens' => 0,
            'total_tokens' => 0,
            'total_input_price' => 0,
            'total_output_price' => 0,
            'total_price' => 0,
            'total_requests' => 0
        ];
    }

    public function getUsageByModel(?\DateTimeInterface $startDate = null, ?\DateTimeInterface $endDate = null): array
    {
        $userDb = $this->getUserDb();
        $query = 'SELECT 
            ai_service_model_id,
            SUM(input_tokens) as total_input_tokens, 
            SUM(output_tokens) as total_output_tokens, 
            SUM(total_tokens) as total_tokens,
            SUM(input_price) as total_input_price, 
            SUM(output_price) as total_output_price, 
            SUM(total_price) as total_price,
            COUNT(*) as total_requests
            FROM ai_service_use_log';
        
        $params = [];
        $conditions = [];
        
        if ($startDate) {
            $conditions[] = 'created_at >= ?';
            $params[] = $startDate->format('Y-m-d H:i:s');
        }
        
        if ($endDate) {
            $conditions[] = 'created_at <= ?';
            $params[] = $endDate->format('Y-m-d H:i:s');
        }
        
        if (!empty($conditions)) {
            $query .= ' WHERE ' . implode(' AND ', $conditions);
        }
        
        $query .= ' GROUP BY ai_service_model_id';
        
        $results = $userDb->executeQuery($query, $params)->fetchAllAssociative();

        // Enhance with model details
        foreach ($results as &$result) {
            $model = $this->aiServiceModelService->findById($result['ai_service_model_id']);
            if ($model) {
                $result['model_name'] = $model->getModelName();
                $result['model_slug'] = $model->getModelSlug();
            } else {
                $result['model_name'] = 'Unknown';
                $result['model_slug'] = 'unknown';
            }
        }

        return $results;
    }

    public function deleteLog(string $id): bool
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeStatement(
            'DELETE FROM ai_service_use_log WHERE id = ?',
            [$id]
        );

        return $result > 0;
    }

    /**
     * Get token and price usage for a specific AI service request
     * 
     * @param string $aiServiceRequestId
     * @return array|null Returns array with tokens/price or null if not found
     */
    public function getUsageByRequestId(string $aiServiceRequestId): ?array
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT 
                input_tokens, output_tokens, total_tokens,
                input_price, output_price, total_price
            FROM ai_service_use_log 
            WHERE ai_service_request_id = ?',
            [$aiServiceRequestId]
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        return [
            'inputTokens' => (int) ($result['input_tokens'] ?? 0),
            'outputTokens' => (int) ($result['output_tokens'] ?? 0),
            'totalTokens' => (int) ($result['total_tokens'] ?? 0),
            'inputTokensFormatted' => number_format($result['input_tokens'] ?? 0, 0),
            'outputTokensFormatted' => number_format($result['output_tokens'] ?? 0, 0),
            'totalTokensFormatted' => number_format($result['total_tokens'] ?? 0, 0),
            'inputPrice' => (float) ($result['input_price'] ?? 0),
            'outputPrice' => (float) ($result['output_price'] ?? 0),
            'totalPrice' => (float) ($result['total_price'] ?? 0),
            'inputPriceFormatted' => number_format($result['input_price'] ?? 0, 2),
            'outputPriceFormatted' => number_format($result['output_price'] ?? 0, 2),
            'totalPriceFormatted' => number_format($result['total_price'] ?? 0, 2)
        ];
    }
}
