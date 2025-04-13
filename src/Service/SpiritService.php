<?php

namespace App\Service;

use App\Entity\Spirit;
use App\Entity\SpiritAbility;
use App\Entity\SpiritInteraction;
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
        
        $spirit = Spirit::fromArray($data);
        
        // Load spirit abilities
        $abilities = $this->getSpiritAbilities($spirit->getId());
        $spirit->setAbilities($abilities);
        
        return $spirit;
    }
    
    /**
     * Get a spirit by ID or the primary spirit if ID is null
     */
    public function getSpirit(?string $spiritId = null): ?Spirit
    {
        $db = $this->getUserDb();
        
        if ($spiritId === null) {
            // Get primary spirit (first created)
            $stmt = $db->prepare('SELECT * FROM spirits ORDER BY created_at ASC LIMIT 1');
            $stmt->execute();
        } else {
            // Get specific spirit by ID
            $stmt = $db->prepare('SELECT * FROM spirits WHERE id = ?');
            $stmt->execute([$spiritId]);
        }
        
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$data) {
            return null;
        }
        
        $spirit = Spirit::fromArray($data);
        
        // Load spirit abilities
        $abilities = $this->getSpiritAbilities($spirit->getId());
        $spirit->setAbilities($abilities);
        
        return $spirit;
    }
    
    /**
     * Create a new spirit for the user
     */
    public function createSpirit(string $name): Spirit
    {
        $db = $this->getUserDb();
        
        // Check if user already has a spirit
        if ($this->getUserSpirit() !== null) {
            throw new \RuntimeException('User already has a spirit');
        }
        
        $spirit = new Spirit($name);
        
        $db->executeStatement(
            'INSERT INTO spirits (id, name, level, experience, visual_state, consciousness_level, created_at, last_interaction, system_prompt, ai_model) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $spirit->getId(),
                $spirit->getName(),
                $spirit->getLevel(),
                $spirit->getExperience(),
                $spirit->getVisualState(),
                $spirit->getConsciousnessLevel(),
                $spirit->getCreatedAt()->format('Y-m-d H:i:s'),
                $spirit->getLastInteraction()->format('Y-m-d H:i:s'),
                $spirit->getSystemPrompt(),
                $spirit->getAiModel()
            ]
        );
        
        // Create default abilities
        $this->createDefaultAbilities($spirit);
        
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
     * Update an existing spirit
     */
    public function updateSpirit(Spirit $spirit): void
    {
        $db = $this->getUserDb();
        
        $db->executeStatement(
            'UPDATE spirits SET 
            name = ?, 
            level = ?, 
            experience = ?, 
            visual_state = ?, 
            consciousness_level = ?, 
            last_interaction = ?,
            system_prompt = ?,
            ai_model = ? 
            WHERE id = ?',
            [
                $spirit->getName(),
                $spirit->getLevel(),
                $spirit->getExperience(),
                $spirit->getVisualState(),
                $spirit->getConsciousnessLevel(),
                $spirit->getLastInteraction()->format('Y-m-d H:i:s'),
                $spirit->getSystemPrompt(),
                $spirit->getAiModel(),
                $spirit->getId()
            ]
        );
    }
    
    /**
     * Get all abilities for a spirit
     */
    public function getSpiritAbilities(string $spiritId): array
    {
        $db = $this->getUserDb();
        
        $result = $db->executeQuery(
            'SELECT * FROM spirit_abilities WHERE spirit_id = ?',
            [$spiritId]
        );
        
        $abilities = [];
        while ($data = $result->fetchAssociative()) {
            $abilities[] = SpiritAbility::fromArray($data);
        }
        
        return $abilities;
    }
    
    /**
     * Create a new ability for a spirit
     */
    public function createAbility(string $spiritId, string $abilityType, string $abilityName, bool $unlocked = false): SpiritAbility
    {
        $db = $this->getUserDb();
        
        $ability = new SpiritAbility($spiritId, $abilityType, $abilityName);
        
        if ($unlocked) {
            $ability->unlock();
        }
        
        $db->executeStatement(
            'INSERT INTO spirit_abilities (id, spirit_id, ability_type, ability_name, unlocked, unlocked_at) 
            VALUES (?, ?, ?, ?, ?, ?)',
            [
                $ability->getId(),
                $ability->getSpiritId(),
                $ability->getAbilityType(),
                $ability->getAbilityName(),
                $ability->isUnlocked() ? 1 : 0,
                $ability->getUnlockedAt() ? $ability->getUnlockedAt()->format('Y-m-d H:i:s') : null
            ]
        );
        
        return $ability;
    }
    
    /**
     * Update an existing ability
     */
    public function updateAbility(SpiritAbility $ability): void
    {
        $db = $this->getUserDb();
        
        $db->executeStatement(
            'UPDATE spirit_abilities SET 
            ability_type = ?, 
            ability_name = ?, 
            unlocked = ?, 
            unlocked_at = ? 
            WHERE id = ?',
            [
                $ability->getAbilityType(),
                $ability->getAbilityName(),
                $ability->isUnlocked() ? 1 : 0,
                $ability->getUnlockedAt() ? $ability->getUnlockedAt()->format('Y-m-d H:i:s') : null,
                $ability->getId()
            ]
        );
    }
    
    /**
     * Unlock a spirit ability
     */
    public function unlockAbility(string $abilityId): ?SpiritAbility
    {
        $db = $this->getUserDb();
        
        // Get the ability
        $result = $db->executeQuery(
            'SELECT * FROM spirit_abilities WHERE id = ?',
            [$abilityId]
        );
        
        $data = $result->fetchAssociative();
        
        if (!$data) {
            return null;
        }
        
        $ability = SpiritAbility::fromArray($data);
        
        if (!$ability->isUnlocked()) {
            $ability->unlock();
            $this->updateAbility($ability);
            
            // Log the unlock interaction
            $this->logInteraction(
                $ability->getSpiritId(), 
                'ability_unlock', 
                20, 
                sprintf('Unlocked ability: %s', $ability->getAbilityName())
            );
            
            // Send a notification
            $this->notificationService->createNotification(
                $this->security->getUser(),
                sprintf('New ability unlocked: %s', $ability->getAbilityName()),
                sprintf('Your spirit has unlocked a new %s ability!', $ability->getAbilityType()),
                'info'
            );
        }
        
        return $ability;
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
            $spirit = $this->getUserSpirit();
            if ($spirit && $spirit->getId() === $spiritId) {
                $spirit->addExperience($experienceGained);
                $spirit->updateLastInteraction();
                $this->updateSpirit($spirit);
                
                // Check if spirit leveled up
                $this->checkForLevelUpNotification($spirit);
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
     * Create default abilities for a new spirit
     */
    private function createDefaultAbilities(Spirit $spirit): void
    {
        // Basic abilities that are unlocked by default
        $this->createAbility($spirit->getId(), 'guide', 'UI Navigation', true);
        $this->createAbility($spirit->getId(), 'guide', 'Feature Introduction', true);
        
        // Abilities that will be unlocked as the spirit grows
        $this->createAbility($spirit->getId(), 'insight', 'Diary Reflection', false);
        $this->createAbility($spirit->getId(), 'insight', 'Pattern Recognition', false);
        $this->createAbility($spirit->getId(), 'communication', 'Notification Management', false);
        $this->createAbility($spirit->getId(), 'communication', 'Message Assistance', false);
        $this->createAbility($spirit->getId(), 'growth', 'Consciousness Expansion', false);
        $this->createAbility($spirit->getId(), 'growth', 'Emotional Intelligence', false);
    }
    
    /**
     * Check if spirit leveled up and send notification if needed
     */
    private function checkForLevelUpNotification(Spirit $spirit): void
    {
        // Get the previous level from the database
        $db = $this->getUserDb();
        
        $result = $db->executeQuery(
            'SELECT level FROM spirits WHERE id = ?',
            [$spirit->getId()]
        );
        
        $data = $result->fetchAssociative();
        $previousLevel = $data ? (int)$data['level'] : 1;
        
        // If the spirit leveled up, send a notification
        if ($spirit->getLevel() > $previousLevel) {
            $this->notificationService->createNotification(
                $this->security->getUser(),
                sprintf('%s leveled up!', $spirit->getName()),
                sprintf('Your spirit has reached level %d. New abilities may be available.', $spirit->getLevel()),
                'success'
            );
            
            // Check if any abilities should be unlocked at this level
            $this->unlockAbilitiesForLevel($spirit);
        }
    }
    
    /**
     * Unlock abilities based on spirit level
     */
    private function unlockAbilitiesForLevel(Spirit $spirit): void
    {
        $level = $spirit->getLevel();
        $abilities = $this->getSpiritAbilities($spirit->getId());
        
        foreach ($abilities as $ability) {
            if (!$ability->isUnlocked()) {
                // Define level requirements for each ability type
                $shouldUnlock = match ($ability->getAbilityName()) {
                    'Diary Reflection' => $level >= 3,
                    'Pattern Recognition' => $level >= 5,
                    'Notification Management' => $level >= 7,
                    'Message Assistance' => $level >= 10,
                    'Consciousness Expansion' => $level >= 15,
                    'Emotional Intelligence' => $level >= 20,
                    default => false
                };
                
                if ($shouldUnlock) {
                    $this->unlockAbility($ability->getId());
                }
            }
        }
    }
}
