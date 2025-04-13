<?php

namespace App\Service;

use App\Entity\AiUserSettings;
use App\Entity\AiGateway;
use App\Entity\AiServiceModel;
use App\Service\UserDatabaseManager;
use App\Service\AiGatewayService;
use App\Service\AiServiceModelService;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Bundle\SecurityBundle\Security;

class AiUserSettingsService
{
    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly AiGatewayService $aiGatewayService,
        private readonly AiServiceModelService $aiServiceModelService,
        private readonly Security $security
    ) {
    }

    public function createSettings(
        string $aiGatewayId,
        ?string $primaryAiServiceModelId = null,
        ?string $secondaryAiServiceModelId = null
    ): AiUserSettings {
        $settings = new AiUserSettings($aiGatewayId);
        
        if ($primaryAiServiceModelId) {
            $settings->setPrimaryAiServiceModelId($primaryAiServiceModelId);
        }
        
        if ($secondaryAiServiceModelId) {
            $settings->setSecondaryAiServiceModelId($secondaryAiServiceModelId);
        }
        
        $userDb = $this->userDatabaseManager->getDatabaseConnection($this->security->getUser());
        $userDb->executeStatement(
            'INSERT INTO ai_user_settings (id, ai_gateway_id, primary_ai_service_model_id, secondary_ai_service_model_id, created_at, updated_at) 
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $settings->getId(),
                $settings->getAiGatewayId(),
                $settings->getPrimaryAiServiceModelId(),
                $settings->getSecondaryAiServiceModelId(),
                $settings->getCreatedAt()->format('Y-m-d H:i:s'),
                $settings->getUpdatedAt()->format('Y-m-d H:i:s')
            ]
        );

        return $settings;
    }

    public function findById(string $id): ?AiUserSettings
    {
        $userDb = $this->userDatabaseManager->getDatabaseConnection($this->security->getUser());
        $result = $userDb->executeQuery(
            'SELECT * FROM ai_user_settings WHERE id = ?',
            [$id]
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        $settings = AiUserSettings::fromArray($result);
        
        // Load related gateway
        $gateway = $this->aiGatewayService->findById($settings->getAiGatewayId());
        if ($gateway) {
            $settings->setAiGateway($gateway);
        }
        
        // Load related models if set
        if ($settings->getPrimaryAiServiceModelId()) {
            $primaryModel = $this->aiServiceModelService->findById($settings->getPrimaryAiServiceModelId());
            if ($primaryModel) {
                $settings->setPrimaryAiServiceModel($primaryModel);
            }
        }
        
        if ($settings->getSecondaryAiServiceModelId()) {
            $secondaryModel = $this->aiServiceModelService->findById($settings->getSecondaryAiServiceModelId());
            if ($secondaryModel) {
                $settings->setSecondaryAiServiceModel($secondaryModel);
            }
        }

        return $settings;
    }

    public function findForUser(): ?AiUserSettings
    {
        $userDb = $this->userDatabaseManager->getDatabaseConnection($this->security->getUser());
        $result = $userDb->executeQuery(
            'SELECT * FROM ai_user_settings ORDER BY created_at DESC LIMIT 1'
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        $settings = AiUserSettings::fromArray($result);
        
        // Load related gateway
        $gateway = $this->aiGatewayService->findById($settings->getAiGatewayId());
        if ($gateway) {
            $settings->setAiGateway($gateway);
        }
        
        // Load related models if set
        if ($settings->getPrimaryAiServiceModelId()) {
            $primaryModel = $this->aiServiceModelService->findById($settings->getPrimaryAiServiceModelId());
            if ($primaryModel) {
                $settings->setPrimaryAiServiceModel($primaryModel);
            }
        }
        
        if ($settings->getSecondaryAiServiceModelId()) {
            $secondaryModel = $this->aiServiceModelService->findById($settings->getSecondaryAiServiceModelId());
            if ($secondaryModel) {
                $settings->setSecondaryAiServiceModel($secondaryModel);
            }
        }

        return $settings;
    }

    public function updateSettings(
        string $id,
        array $data
    ): ?AiUserSettings {
        $settings = $this->findById($id);
        if (!$settings) {
            return null;
        }

        if (isset($data['aiGatewayId'])) {
            $settings->setAiGatewayId($data['aiGatewayId']);
        }
        
        if (isset($data['primaryAiServiceModelId'])) {
            $settings->setPrimaryAiServiceModelId($data['primaryAiServiceModelId']);
        }
        
        if (isset($data['secondaryAiServiceModelId'])) {
            $settings->setSecondaryAiServiceModelId($data['secondaryAiServiceModelId']);
        }

        $settings->updateUpdatedAt();

        $userDb = $this->userDatabaseManager->getDatabaseConnection($this->security->getUser());
        $userDb->executeStatement(
            'UPDATE ai_user_settings SET 
             ai_gateway_id = ?, 
             primary_ai_service_model_id = ?,
             secondary_ai_service_model_id = ?,
             updated_at = ? 
             WHERE id = ?',
            [
                $settings->getAiGatewayId(),
                $settings->getPrimaryAiServiceModelId(),
                $settings->getSecondaryAiServiceModelId(),
                $settings->getUpdatedAt()->format('Y-m-d H:i:s'),
                $settings->getId()
            ]
        );

        return $settings;
    }

    public function deleteSettings(string $id): bool
    {
        $userDb = $this->userDatabaseManager->getDatabaseConnection($this->security->getUser());
        $result = $userDb->executeStatement(
            'DELETE FROM ai_user_settings WHERE id = ?',
            [$id]
        );

        return $result > 0;
    }
}
