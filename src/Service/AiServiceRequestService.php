<?php

namespace App\Service;

use App\Entity\AiServiceRequest;
use App\Entity\AiServiceResponse;
use App\Service\UserDatabaseManager;
use App\Service\AiServiceModelService;
use Symfony\Component\Security\Core\User\UserInterface;

class AiServiceRequestService
{
    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly AiServiceModelService $aiServiceModelService
    ) {
    }

    public function createRequest(
        UserInterface $user,
        string $aiServiceModelId,
        array $messages,
        ?int $maxTokens = null,
        ?float $temperature = null,
        ?string $stopSequence = null
    ): AiServiceRequest {
        $request = new AiServiceRequest($aiServiceModelId, $messages);
        
        if ($maxTokens !== null) {
            $request->setMaxTokens($maxTokens);
        }
        
        if ($temperature !== null) {
            $request->setTemperature($temperature);
        }
        
        if ($stopSequence !== null) {
            $request->setStopSequence($stopSequence);
        }

        // Store in user's database
        $userDb = $this->userDatabaseManager->getDatabaseConnection($user);
        $userDb->executeStatement(
            'INSERT INTO ai_service_request (
                id, ai_service_model_id, messages, max_tokens, temperature, stop_sequence, created_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $request->getId(),
                $request->getAiServiceModelId(),
                $request->getMessagesRaw(),
                $request->getMaxTokens(),
                $request->getTemperature(),
                $request->getStopSequence(),
                $request->getCreatedAt()->format('Y-m-d H:i:s')
            ]
        );

        return $request;
    }

    public function findById(UserInterface $user, string $id): ?AiServiceRequest
    {
        $userDb = $this->userDatabaseManager->getDatabaseConnection($user);
        $result = $userDb->executeQuery(
            'SELECT * FROM ai_service_request WHERE id = ?',
            [$id]
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        $request = AiServiceRequest::fromArray($result);
        
        // Load related model
        $model = $this->aiServiceModelService->findById($user, $request->getAiServiceModelId());
        if ($model) {
            $request->setAiServiceModel($model);
        }

        return $request;
    }

    public function findByModel(UserInterface $user, string $modelId, int $limit = 100): array
    {
        $userDb = $this->userDatabaseManager->getDatabaseConnection($user);
        $results = $userDb->executeQuery(
            'SELECT * FROM ai_service_request WHERE ai_service_model_id = ? ORDER BY created_at DESC LIMIT ?',
            [$modelId, $limit]
        )->fetchAllAssociative();

        return array_map(fn($data) => AiServiceRequest::fromArray($data), $results);
    }

    public function findRecent(UserInterface $user, int $limit = 100): array
    {
        $userDb = $this->userDatabaseManager->getDatabaseConnection($user);
        $results = $userDb->executeQuery(
            'SELECT * FROM ai_service_request ORDER BY created_at DESC LIMIT ?',
            [$limit]
        )->fetchAllAssociative();

        return array_map(fn($data) => AiServiceRequest::fromArray($data), $results);
    }

    public function deleteRequest(UserInterface $user, string $id): bool
    {
        $userDb = $this->userDatabaseManager->getDatabaseConnection($user);
        $result = $userDb->executeStatement(
            'DELETE FROM ai_service_request WHERE id = ?',
            [$id]
        );

        return $result > 0;
    }
}
