<?php

namespace App\Service;

use App\Entity\AiServiceModel;
use App\Entity\User;
use App\Service\UserDatabaseManager;
use App\Service\AiGatewayService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

class AiServiceModelService
{
    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly AiGatewayService $aiGatewayService,
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

    public function createModel(
        string $aiGatewayId,
        string $modelName,
        string $modelSlug,
        ?string $virtualKey = null,
        ?int $contextWindow = null,
        ?string $maxInput = null,
        ?string $maxInputImageSize = null,
        ?int $maxOutput = null,
        ?float $ppmInput = null,
        ?float $ppmOutput = null,
        ?bool $isActive = true
    ): AiServiceModel {
        $model = new AiServiceModel($aiGatewayId, $modelName, $modelSlug);
        
        if ($virtualKey !== null) {
            $model->setVirtualKey($virtualKey);
        }
        
        if ($contextWindow !== null) {
            $model->setContextWindow($contextWindow);
        }
        
        if ($maxInput !== null) {
            $model->setMaxInput($maxInput);
        }
        
        if ($maxInputImageSize !== null) {
            $model->setMaxInputImageSize($maxInputImageSize);
        }
        
        if ($maxOutput !== null) {
            $model->setMaxOutput($maxOutput);
        }
        
        if ($ppmInput !== null) {
            $model->setPpmInput($ppmInput);
        }
        
        if ($ppmOutput !== null) {
            $model->setPpmOutput($ppmOutput);
        }
        
        if ($isActive !== null) {
            $model->setIsActive($isActive);
        }

        // Store in user's database
        $userDb = $this->getUserDb();
        $userDb->executeStatement(
            'INSERT INTO ai_service_model (
                id, ai_gateway_id, virtual_key, model_name, model_slug, 
                context_window, max_input, max_input_image_size, max_output, 
                ppm_input, ppm_output, is_active, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $model->getId(),
                $model->getAiGatewayId(),
                $model->getVirtualKey(),
                $model->getModelName(),
                $model->getModelSlug(),
                $model->getContextWindow(),
                $model->getMaxInput(),
                $model->getMaxInputImageSize(),
                $model->getMaxOutput(),
                $model->getPpmInput(),
                $model->getPpmOutput(),
                $model->isActive() ? 1 : 0,
                $model->getCreatedAt()->format('Y-m-d H:i:s'),
                $model->getUpdatedAt()->format('Y-m-d H:i:s')
            ]
        );

        return $model;
    }

    public function findById(string $id): ?AiServiceModel
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT * FROM ai_service_model WHERE id = ?',
            [$id]
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        $model = AiServiceModel::fromArray($result);
        
        // Load related gateway
        $gateway = $this->aiGatewayService->findById($model->getAiGatewayId());
        if ($gateway) {
            $model->setAiGateway($gateway);
        }

        return $model;
    }

    public function findByGateway(string $gatewayId): array
    {
        $userDb = $this->getUserDb();
        $results = $userDb->executeQuery(
            'SELECT * FROM ai_service_model WHERE ai_gateway_id = ? ORDER BY model_name ASC',
            [$gatewayId]
        )->fetchAllAssociative();

        return array_map(fn($data) => AiServiceModel::fromArray($data), $results);
    }

    public function findAll(bool $activeOnly = false): array
    {
        $userDb = $this->getUserDb();
        $query = 'SELECT * FROM ai_service_model';
        $params = [];
        
        if ($activeOnly) {
            $query .= ' WHERE is_active = 1';
        }
        
        $query .= ' ORDER BY model_name ASC';
        
        $results = $userDb->executeQuery($query, $params)->fetchAllAssociative();

        return array_map(fn($data) => AiServiceModel::fromArray($data), $results);
    }

    public function updateModel(
        string $id,
        array $data
    ): ?AiServiceModel {
        $model = $this->findById($id);
        if (!$model) {
            return null;
        }

        if (isset($data['modelName'])) {
            $model->setModelName($data['modelName']);
        }

        if (isset($data['modelSlug'])) {
            $model->setModelSlug($data['modelSlug']);
        }

        if (isset($data['virtualKey'])) {
            $model->setVirtualKey($data['virtualKey']);
        }

        if (isset($data['contextWindow'])) {
            $model->setContextWindow($data['contextWindow']);
        }

        if (isset($data['maxInput'])) {
            $model->setMaxInput($data['maxInput']);
        }

        if (isset($data['maxInputImageSize'])) {
            $model->setMaxInputImageSize($data['maxInputImageSize']);
        }

        if (isset($data['maxOutput'])) {
            $model->setMaxOutput($data['maxOutput']);
        }

        if (isset($data['ppmInput'])) {
            $model->setPpmInput($data['ppmInput']);
        }

        if (isset($data['ppmOutput'])) {
            $model->setPpmOutput($data['ppmOutput']);
        }

        if (isset($data['isActive'])) {
            $model->setIsActive($data['isActive']);
        }

        $model->updateUpdatedAt();

        $userDb = $this->getUserDb();
        $userDb->executeStatement(
            'UPDATE ai_service_model SET 
             model_name = ?, 
             model_slug = ?, 
             virtual_key = ?, 
             context_window = ?, 
             max_input = ?, 
             max_input_image_size = ?, 
             max_output = ?, 
             ppm_input = ?, 
             ppm_output = ?, 
             is_active = ?, 
             updated_at = ? 
             WHERE id = ?',
            [
                $model->getModelName(),
                $model->getModelSlug(),
                $model->getVirtualKey(),
                $model->getContextWindow(),
                $model->getMaxInput(),
                $model->getMaxInputImageSize(),
                $model->getMaxOutput(),
                $model->getPpmInput(),
                $model->getPpmOutput(),
                $model->isActive() ? 1 : 0,
                $model->getUpdatedAt()->format('Y-m-d H:i:s'),
                $model->getId()
            ]
        );

        return $model;
    }

    public function deleteModel(string $id): bool
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeStatement(
            'DELETE FROM ai_service_model WHERE id = ?',
            [$id]
        );

        return $result > 0;
    }
}
