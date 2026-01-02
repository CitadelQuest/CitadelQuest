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
        ?bool $isActive = true,
        ?array $fullConfig = null
    ): AiServiceModel {
        $model = new AiServiceModel($aiGatewayId, $modelName, $modelSlug);
        
        if ($virtualKey !== null) {
            $model->setVirtualKey($virtualKey);
        }
        
        if ($fullConfig !== null) {
            $model->setFullConfig($fullConfig);
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
                ppm_input, ppm_output, is_active, full_config, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
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
                $model->getFullConfig() ? json_encode($model->getFullConfig()) : null,
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

    public function findByModelSlug(string $modelSlug, string $gatewayId): ?AiServiceModel
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT * FROM ai_service_model WHERE is_active = 1 AND model_slug = ? AND ai_gateway_id = ?',
            [$modelSlug, $gatewayId]
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        return AiServiceModel::fromArray($result);
    }

    public function findByGateway(string $gatewayId, bool $activeOnly = false): array
    {
        $userDb = $this->getUserDb();
        $query = 'SELECT * FROM ai_service_model WHERE ai_gateway_id = ?';
        $params = [$gatewayId];
        
        if ($activeOnly) {
            $query .= ' AND is_active = 1';
        }
        
        $query .= ' ORDER BY model_name ASC';
        
        $results = $userDb->executeQuery($query, $params)->fetchAllAssociative();

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

    public function findImageOutputModelsByGateway(string $gatewayId, bool $activeOnly = true): array
    {
        $allModels = $this->findByGateway($gatewayId, $activeOnly);
        $imageModels = [];

        foreach ($allModels as $model) {
            $config = $model->getFullConfig();
            if ($config && 
                isset($config['architecture']['output_modalities']) && 
                is_array($config['architecture']['output_modalities']) && 
                in_array('image', $config['architecture']['output_modalities'])
            ) {
                $imageModels[] = $model;
            }
        }

        return $imageModels;
    }

    public function getDefaultPrimaryAiModelByGateway(string $gatewayId): ?AiServiceModel
    {
        $allModels = $this->findByGateway($gatewayId, true);

        foreach ($allModels as $model) {
            $config = $model->getFullConfig();
            if ($config && 
                isset($config['defaultPrimaryAiModel']) && 
                $config['defaultPrimaryAiModel'] == 1
            ) {
                return $model;
            }
        }

        return null;
    }

    public function getDefaultSecondaryAiModelByGateway(string $gatewayId): ?AiServiceModel
    {
        $allModels = $this->findByGateway($gatewayId, true);

        foreach ($allModels as $model) {
            $config = $model->getFullConfig();
            if ($config && 
                isset($config['defaultSecondaryAiModel']) && 
                $config['defaultSecondaryAiModel'] == 1
            ) {
                return $model;
            }
        }

        return null;
    }

    public function updateModel(
        string $id,
        array $data
    ): ?AiServiceModel {
        $model = $this->findById($id);
        if (!$model) {
            return null;
        }

        if (isset($data['aiGatewayId'])) {
            $model->setAiGatewayId($data['aiGatewayId']);
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

        if (isset($data['fullConfig'])) {
            $model->setFullConfig($data['fullConfig']);
        }

        $model->updateUpdatedAt();

        $userDb = $this->getUserDb();
        $userDb->executeStatement(
            'UPDATE ai_service_model SET 
             ai_gateway_id = ?,
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
             full_config = ?,
             updated_at = ? 
             WHERE id = ?',
            [
                $model->getAiGatewayId(),
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
                $model->getFullConfig() ? json_encode($model->getFullConfig()) : null,
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

    public function setAllModelsInactive(string $gatewayId): void
    {
        $userDb = $this->getUserDb();
        $userDb->executeStatement(
            'UPDATE ai_service_model SET is_active = 0 WHERE ai_gateway_id = ?',
            [$gatewayId]
        );
    }
}