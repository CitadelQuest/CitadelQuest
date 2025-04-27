<?php

namespace App\Service;

use App\Entity\AiServiceRequest;
use App\Entity\AiServiceResponse;
use App\Entity\User;
use App\Service\UserDatabaseManager;
use App\Service\AiServiceModelService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

class AiServiceRequestService
{
    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly AiServiceModelService $aiServiceModelService,
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

    public function createRequest(
        string $aiServiceModelId,
        array $messages,
        ?int $maxTokens = null,
        ?float $temperature = null,
        ?string $stopSequence = null,
        ?array $tools = []
    ): AiServiceRequest {
        $request = new AiServiceRequest($aiServiceModelId, $messages, $maxTokens, $temperature, $stopSequence, $tools);

        // Store in user's database
        $userDb = $this->getUserDb();
        $userDb->executeStatement(
            'INSERT INTO ai_service_request (
                id, ai_service_model_id, messages, max_tokens, temperature, stop_sequence, tools, created_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $request->getId(),
                $request->getAiServiceModelId(),
                $request->getMessagesRaw(),
                $request->getMaxTokens(),
                $request->getTemperature(),
                $request->getStopSequence(),
                $request->getToolsRaw(),
                $request->getCreatedAt()->format('Y-m-d H:i:s')
            ]
        );

        return $request;
    }

    public function findById(string $id): ?AiServiceRequest
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT * FROM ai_service_request WHERE id = ?',
            [$id]
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        $request = AiServiceRequest::fromArray($result);
        
        // Load related model
        $model = $this->aiServiceModelService->findById($request->getAiServiceModelId());
        if ($model) {
            $request->setAiServiceModel($model);
        }

        return $request;
    }

    public function findByModel(string $modelId, int $limit = 100): array
    {
        $userDb = $this->getUserDb();
        $results = $userDb->executeQuery(
            'SELECT * FROM ai_service_request WHERE ai_service_model_id = ? ORDER BY created_at DESC LIMIT ?',
            [$modelId, $limit]
        )->fetchAllAssociative();

        return array_map(fn($data) => AiServiceRequest::fromArray($data), $results);
    }

    public function findRecent(int $limit = 100): array
    {
        $userDb = $this->getUserDb();
        $results = $userDb->executeQuery(
            'SELECT * FROM ai_service_request ORDER BY created_at DESC LIMIT ?',
            [$limit]
        )->fetchAllAssociative();

        return array_map(fn($data) => AiServiceRequest::fromArray($data), $results);
    }

    public function deleteRequest(string $id): bool
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeStatement(
            'DELETE FROM ai_service_request WHERE id = ?',
            [$id]
        );

        return $result > 0;
    }
}
