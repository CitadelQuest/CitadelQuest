<?php

namespace App\Service;

use App\Entity\Spirit;
use App\Entity\SpiritInteraction;
use App\Entity\SpiritSettings;
use App\Entity\User;
use App\Entity\AiServiceModel;
use App\Service\ProjectFileService;
use PDO;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;
use Psr\Log\LoggerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

class SpiritService
{
    public function __construct(
        private UserDatabaseManager $userDatabaseManager,
        private Security $security,
        private NotificationService $notificationService,
        private AiServiceModelService $aiServiceModelService,
        private AiGatewayService $aiGatewayService,
        private LoggerInterface $logger,
        private SluggerInterface $slugger,
        private ProjectFileService $projectFileService,
    ) {}
    
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
     * Get all spirits for the current user
     * 
     * @return Spirit[]
     */
    public function findAll(): array
    {
        $db = $this->getUserDb();

        $result = $db->executeQuery('SELECT * FROM spirits ORDER BY created_at ASC');
        $data = $result->fetchAllAssociative();

        if (!$data) {
            return [];
        }

        $spirits = [];
        foreach ($data as $spiritData) {
            $spirit = Spirit::fromArray($spiritData);
            $spirits[] = $spirit;
        }

        return $spirits;
    }
    
    /**
     * Get the user's PRIMARY spirit (first created)
     * 
     * WARNING: This returns the FIRST spirit, not necessarily the one in current context.
     * For conversation/tool contexts, use getSpirit($spiritId) with explicit ID instead.
     */
    public function getUserPrimarySpirit(): ?Spirit
    {
        $db = $this->getUserDb();

        $result = $db->executeQuery('SELECT * FROM spirits ORDER BY created_at ASC LIMIT 1');
        $data = $result->fetchAssociative();

        if (!$data) {
            return null;
        }

        return Spirit::fromArray($data);
    }
    
    /**
     * @deprecated Use getUserPrimarySpirit() instead - name is clearer about what it returns
     */
    public function getUserSpirit(): ?Spirit
    {
        return $this->getUserPrimarySpirit();
    }
    
    /**
     * Get a spirit by ID
     * 
     * Returns null if spiritId is null or spirit not found.
     * Does NOT fallback to primary spirit - use getUserPrimarySpirit() explicitly if needed.
     */
    public function getSpirit(?string $spiritId = null): ?Spirit
    {
        if ($spiritId === null) {
            return null;
        }
        
        $db = $this->getUserDb();
        $result = $db->executeQuery('SELECT * FROM spirits WHERE id = ?', [$spiritId]);
        $data = $result->fetchAssociative();

        if (!$data) {
            return null;
        }

        return Spirit::fromArray($data);
    }
    public function findById(string $id): ?Spirit
    {
        return $this->getSpirit($id);
    }
    
    /**
     * Create a new spirit for the user
     * 
     * @param string $name The name of the spirit
     * @param string|null $color The color for the spirit (hex code)
     * @return Spirit
     */
    public function createSpirit(string $name, ?string $color = null): Spirit
    {
        $db = $this->getUserDb();

        $spirit = new Spirit($name);

        $db->executeStatement(
            'INSERT INTO spirits (id, name, created_at, last_interaction) VALUES (?, ?, ?, ?)',
            [
                $spirit->getId(),
                $spirit->getName(),
                $spirit->getCreatedAt()->format('Y-m-d H:i:s'),
                $spirit->getLastInteraction()->format('Y-m-d H:i:s')
            ]
        );

        // Initialize default settings
        $this->setSpiritSetting($spirit->getId(), 'level', '1');
        $this->setSpiritSetting($spirit->getId(), 'experience', '0');
        
        // Set visualState with color (use provided color or default cyber green)
        $defaultColor = $color ?? '#95ec86';
        $this->setSpiritSetting($spirit->getId(), 'visualState', json_encode(['color' => $defaultColor]));

        // Log the creation interaction
        $this->logInteraction($spirit->getId(), 'creation', 10, 'Spirit created');

        return $spirit;
    }
    
    /**
     * Update a spirit's AI model
     * 
     * @param string $spiritId The ID of the spirit to update
     * @param string $modelId The ID of the AI model to use
     * @return void
     */
    public function updateSpiritModel(string $spiritId, string $modelId): void
    {
        $spirit = $this->getSpirit($spiritId);

        if (!$spirit) {
            throw new \RuntimeException('Spirit not found');
        }

        $this->setSpiritSetting($spiritId, 'aiModel', $modelId);
    }
    
    /**
     * Get a spirit's AI model
     * Returns spirit-specific model if set, otherwise falls back to primary AI model
     * 
     * @param string $spiritId The ID of the spirit
     * @return AiServiceModel|null The AI model to use for this spirit
     * @throws \Exception If no model is configured
     */
    public function getSpiritAiModel(string $spiritId): ?AiServiceModel
    {
        // Try to get spirit-specific AI model
        $spiritAiModelId = $this->getSpiritSetting($spiritId, 'aiModel');
        if ($spiritAiModelId) {
            $aiServiceModel = $this->aiServiceModelService->findById($spiritAiModelId);
            if ($aiServiceModel) {
                return $aiServiceModel;
            }
        }
        
        // Fall back to primary AI model
        $aiServiceModel = $this->aiGatewayService->getPrimaryAiServiceModel();
        
        if (!$aiServiceModel) {
            throw new \Exception('AI Service Model not configured');
        }
        
        return $aiServiceModel;
    }
    
    /**
     * Update an existing spirit
     */
    public function updateSpirit(Spirit $spirit): void
    {
        $db = $this->getUserDb();

        $db->executeStatement(
            'UPDATE spirits SET name = ?, last_interaction = ? WHERE id = ?',
            [
                $spirit->getName(),
                $spirit->getLastInteraction()->format('Y-m-d H:i:s'),
                $spirit->getId()
            ]
        );
    }

    /**
     * Log a spirit interaction
     */
    public function logInteraction(string $spiritId, string $interactionType, int $experienceGained = 0, ?string $context = null): SpiritInteraction
    {
        $db = $this->getUserDb();

        $interaction = new SpiritInteraction($spiritId, $interactionType, $experienceGained, $context);

        $db->executeStatement(
            'INSERT INTO spirit_interactions (id, spirit_id, interaction_type, context, experience_gained, created_at) 
            VALUES (?, ?, ?, ?, ?, ?)',
            [
                $interaction->getId(),
                $interaction->getSpiritId(),
                $interaction->getInteractionType(),
                $interaction->getContext(),
                $interaction->getExperienceGained(),
                $interaction->getCreatedAt()->format('Y-m-d H:i:s')
            ]
        );

        // Update spirit experience and last interaction time
        if ($experienceGained > 0) {
            $spirit = $this->getSpirit($spiritId);
            if ($spirit) {
                $currentExperience = (int)$this->getSpiritSetting($spiritId, 'experience', '0');
                $newExperience = $currentExperience + $experienceGained;
                $this->setSpiritSetting($spiritId, 'experience', (string)$newExperience);

                $spirit->updateLastInteraction();
                $this->updateSpirit($spirit);

                // Check if spirit leveled up
                $this->checkForLevelUpNotification($spiritId, $newExperience);
            }
        }

        return $interaction;
    }
    
    /**
     * Get recent interactions for a spirit
     */
    public function getRecentInteractions(string $spiritId, int $limit = 10): array
    {
        $db = $this->getUserDb();
        
        $result = $db->executeQuery(
            'SELECT * FROM spirit_interactions 
            WHERE spirit_id = ? 
            ORDER BY created_at DESC 
            LIMIT ?',
            [$spiritId, $limit],
            [\PDO::PARAM_STR, \PDO::PARAM_INT]
        );
        
        $interactions = [];
        while ($data = $result->fetchAssociative()) {
            $interactions[] = SpiritInteraction::fromArray($data);
        }
        
        return $interactions;
    }

    /**
     * Check if spirit leveled up and send notification if needed
     */
    private function checkForLevelUpNotification(string $spiritId, int $newExperience): void
    {
        $currentLevel = (int)$this->getSpiritSetting($spiritId, 'level', '1');
        $nextLevelThreshold = $this->calculateNextLevelThreshold($currentLevel);

        if ($newExperience >= $nextLevelThreshold) {
            $newLevel = $currentLevel + 1;
            $this->setSpiritSetting($spiritId, 'level', (string)$newLevel);

            $spirit = $this->getSpirit($spiritId);
            if ($spirit) {
                $this->notificationService->createNotification(
                    $this->security->getUser(),
                    sprintf('%s leveled up!', $spirit->getName()),
                    sprintf('Your spirit has reached level %d.', $newLevel),
                    'success'
                );
            }
        }
    }

    private function calculateNextLevelThreshold(int $currentLevel): int
    {
        return (int)(100 * pow(1.5, $currentLevel - 1));
    }

    /**
     * Get level progression data for a spirit
     */
    public function getLevelProgression(string $spiritId): array
    {
        $level = (int)$this->getSpiritSetting($spiritId, 'level', '1');
        $experience = (int)$this->getSpiritSetting($spiritId, 'experience', '0');
        $nextLevelThreshold = $this->calculateNextLevelThreshold($level);
        $percentage = $nextLevelThreshold > 0 ? ($experience / $nextLevelThreshold) * 100 : 100;

        return [
            'level' => $level,
            'experience' => $experience,
            'nextLevelThreshold' => $nextLevelThreshold,
            'percentage' => min(100, $percentage)
        ];
    }

    /**
     * Get a spirit setting value
     */
    public function getSpiritSetting(string $spiritId, string $key, ?string $default = null): ?string
    {
        $db = $this->getUserDb();

        $result = $db->executeQuery(
            'SELECT value FROM spirit_settings WHERE spirit_id = ? AND key = ?',
            [$spiritId, $key]
        );

        $data = $result->fetchAssociative();

        return $data ? $data['value'] : $default;
    }

    /**
     * Set a spirit setting value
     */
    public function setSpiritSetting(string $spiritId, string $key, ?string $value): void
    {
        $db = $this->getUserDb();

        $result = $db->executeQuery(
            'SELECT id FROM spirit_settings WHERE spirit_id = ? AND key = ?',
            [$spiritId, $key]
        );

        $existing = $result->fetchAssociative();

        if ($existing) {
            $db->executeStatement(
                'UPDATE spirit_settings SET value = ?, updated_at = ? WHERE id = ?',
                [$value, date('Y-m-d H:i:s'), $existing['id']]
            );
        } else {
            $setting = new SpiritSettings($spiritId, $key, $value);
            $db->executeStatement(
                'INSERT INTO spirit_settings (id, spirit_id, key, value, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $setting->getId(),
                    $setting->getSpiritId(),
                    $setting->getKey(),
                    $setting->getValue(),
                    $setting->getCreatedAt()->format('Y-m-d H:i:s'),
                    $setting->getUpdatedAt()->format('Y-m-d H:i:s')
                ]
            );
        }
    }

    /**
     * Get all settings for a spirit
     */
    public function getSpiritSettings(string $spiritId): array
    {
        $db = $this->getUserDb();

        $result = $db->executeQuery(
            'SELECT * FROM spirit_settings WHERE spirit_id = ?',
            [$spiritId]
        );

        $settings = [];
        while ($data = $result->fetchAssociative()) {
            $settings[$data['key']] = $data['value'];
        }

        return $settings;
    }

    /**
     * Delete a spirit and all related data (cascade delete)
     * Cannot delete the primary (oldest) spirit
     */
    public function deleteSpirit(string $spiritId): void
    {
        $db = $this->getUserDb();

        // Get the spirit to delete
        $spirit = $this->getSpirit($spiritId);
        if (!$spirit) {
            throw new \RuntimeException('Spirit not found');
        }

        // Check if this is the primary spirit (oldest)
        if ($this->isPrimarySpirit($spiritId)) {
            throw new \RuntimeException('Cannot delete primary spirit');
        }

        // Start transaction for cascade delete
        $db->beginTransaction();

        try {
            // Delete spirit settings
            $db->executeStatement(
                'DELETE FROM spirit_settings WHERE spirit_id = ?',
                [$spiritId]
            );

            // Delete spirit interactions
            $db->executeStatement(
                'DELETE FROM spirit_interactions WHERE spirit_id = ?',
                [$spiritId]
            );

            // Delete spirit conversation messages
            $db->executeStatement(
                'DELETE FROM spirit_conversation_message WHERE conversation_id IN (SELECT id FROM spirit_conversation WHERE spirit_id = ?)',
                [$spiritId]
            );

            // Delete spirit conversations
            $db->executeStatement(
                'DELETE FROM spirit_conversation WHERE spirit_id = ?',
                [$spiritId]
            );

            // Delete the spirit
            $db->executeStatement(
                'DELETE FROM spirits WHERE id = ?',
                [$spiritId]
            );

            $db->commit();

            $this->logger->info('Spirit deleted', ['spiritId' => $spiritId, 'spiritName' => $spirit->getName()]);
        } catch (\Exception $e) {
            $db->rollBack();
            $this->logger->error('Failed to delete spirit', ['spiritId' => $spiritId, 'error' => $e->getMessage()]);
            throw $e;
        }

        // delete also project_file (Spirit directory)
        $spiritNameSlug = $this->slugger->slug($spirit->getName());
        $memoryDir = $this->projectFileService->findByPathAndName('general', '/spirit/', $spiritNameSlug);
        if ($memoryDir) {
            $this->projectFileService->delete($memoryDir->getId());            
        }
    }

    /**
     * Check if a spirit is the primary (oldest) spirit
     */
    public function isPrimarySpirit(string $spiritId): bool
    {
        $primarySpirit = $this->getUserSpirit();
        return $primarySpirit && $primarySpirit->getId() === $spiritId;
    }
}
