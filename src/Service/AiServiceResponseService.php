<?php

namespace App\Service;

use App\Entity\AiServiceResponse;
use App\Service\UserDatabaseManager;
use App\Service\AiServiceRequestService;
use Symfony\Component\Security\Core\User\UserInterface;

class AiServiceResponseService
{
    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly AiServiceRequestService $aiServiceRequestService
    ) {
    }

    public function createResponse(
        UserInterface $user,
        string $aiServiceRequestId,
        array $message,
        ?string $finishReason = null,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?int $totalTokens = null
    ): AiServiceResponse {
        $response = new AiServiceResponse($aiServiceRequestId, $message);
        
        if ($finishReason !== null) {
            $response->setFinishReason($finishReason);
        }
        
        if ($inputTokens !== null) {
            $response->setInputTokens($inputTokens);
        }
        
        if ($outputTokens !== null) {
            $response->setOutputTokens($outputTokens);
        }
        
        if ($totalTokens !== null) {
            $response->setTotalTokens($totalTokens);
        }

        // Store in user's database
        $userDb = $this->userDatabaseManager->getDatabaseConnection($user);
        $userDb->executeStatement(
            'INSERT INTO ai_service_response (
                id, ai_service_request_id, message, finish_reason, 
                input_tokens, output_tokens, total_tokens, created_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $response->getId(),
                $response->getAiServiceRequestId(),
                $response->getMessageRaw(),
                $response->getFinishReason(),
                $response->getInputTokens(),
                $response->getOutputTokens(),
                $response->getTotalTokens(),
                $response->getCreatedAt()->format('Y-m-d H:i:s')
            ]
        );

        return $response;
    }

    public function findById(UserInterface $user, string $id): ?AiServiceResponse
    {
        $userDb = $this->userDatabaseManager->getDatabaseConnection($user);
        $result = $userDb->executeQuery(
            'SELECT * FROM ai_service_response WHERE id = ?',
            [$id]
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        $response = AiServiceResponse::fromArray($result);
        
        // Load related request
        $request = $this->aiServiceRequestService->findById($user, $response->getAiServiceRequestId());
        if ($request) {
            $response->setAiServiceRequest($request);
        }

        return $response;
    }

    public function findByRequest(UserInterface $user, string $requestId): ?AiServiceResponse
    {
        $userDb = $this->userDatabaseManager->getDatabaseConnection($user);
        $result = $userDb->executeQuery(
            'SELECT * FROM ai_service_response WHERE ai_service_request_id = ? ORDER BY created_at DESC LIMIT 1',
            [$requestId]
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        $response = AiServiceResponse::fromArray($result);
        
        // Load related request
        $request = $this->aiServiceRequestService->findById($user, $response->getAiServiceRequestId());
        if ($request) {
            $response->setAiServiceRequest($request);
        }

        return $response;
    }

    public function findRecent(UserInterface $user, int $limit = 100): array
    {
        $userDb = $this->userDatabaseManager->getDatabaseConnection($user);
        $results = $userDb->executeQuery(
            'SELECT * FROM ai_service_response ORDER BY created_at DESC LIMIT ?',
            [$limit]
        )->fetchAllAssociative();

        return array_map(fn($data) => AiServiceResponse::fromArray($data), $results);
    }

    public function deleteResponse(UserInterface $user, string $id): bool
    {
        $userDb = $this->userDatabaseManager->getDatabaseConnection($user);
        $result = $userDb->executeStatement(
            'DELETE FROM ai_service_response WHERE id = ?',
            [$id]
        );

        return $result > 0;
    }
}
