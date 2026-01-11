<?php

namespace App\Service;

use App\Entity\Spirit;
use App\Entity\SpiritInteraction;
use App\Entity\SpiritSettings;
use App\Entity\User;
use PDO;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

class SpiritService
{
    public function __construct(
        private UserDatabaseManager $userDatabaseManager,
        private Security $security,
        private NotificationService $notificationService
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
     * Get the user's primary spirit
     */
    public function getUserSpirit(): ?Spirit
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
     * Get a spirit by ID or the primary spirit if ID is null
     */
    public function getSpirit(?string $spiritId = null): ?Spirit
    {
        $db = $this->getUserDb();

        if ($spiritId === null) {
            $result = $db->executeQuery('SELECT * FROM spirits ORDER BY created_at ASC LIMIT 1');
        } else {
            $result = $db->executeQuery('SELECT * FROM spirits WHERE id = ?', [$spiritId]);
        }

        $data = $result->fetchAssociative();

        if (!$data) {
            return null;
        }

        return Spirit::fromArray($data);
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
        $this->setSpiritSetting($spirit->getId(), 'visualState', 'initial');

        // Set color if provided
        if ($color) {
            $this->setSpiritSetting($spirit->getId(), 'visualState', json_encode(['color' => $color]));
        }

        // Log the creation interaction
        $this->logInteraction($spirit->getId(), 'creation', 10, 'Spirit created');

        // Send a welcome notification
        $this->notificationService->createNotification(
            $this->security->getUser(),
            sprintf('Spirit %s has joined you!', $spirit->getName()),
            'Your spirit companion is now ready to assist and grow with you.',
            'success'
        );

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
}
