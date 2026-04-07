<?php

namespace App\Service;

use App\Entity\AiToolSettings;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

class AiToolSettingsService
{
    private ?User $user;
    
    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly Security $security
    ) {
        $this->user = $security->getUser();
    }

    public function setUser(User $user): void
    {
        $this->user = $user;
    }
    
    /**
     * Get a fresh database connection for the current user
     */
    private function getUserDb()
    {
        if (!$this->user) {
            throw new \Exception('User not found');
        }
        return $this->userDatabaseManager->getDatabaseConnection($this->user);
    }

    /**
     * Get all settings for a tool
     * @return AiToolSettings[]
     */
    public function getSettingsForTool(string $toolId): array
    {
        $userDb = $this->getUserDb();
        $results = $userDb->executeQuery(
            'SELECT * FROM ai_tool_settings WHERE tool_id = ? ORDER BY display_order ASC, key ASC',
            [$toolId]
        )->fetchAllAssociative();

        return array_map(fn($data) => AiToolSettings::fromArray($data), $results);
    }

    /**
     * Get a single setting
     */
    public function getSetting(string $toolId, string $key): ?AiToolSettings
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT * FROM ai_tool_settings WHERE tool_id = ? AND key = ?',
            [$toolId, $key]
        )->fetchAssociative();

        return $result ? AiToolSettings::fromArray($result) : null;
    }

    /**
     * Get a setting value with optional default
     */
    public function getSettingValue(string $toolId, string $key, ?string $default = null): ?string
    {
        $setting = $this->getSetting($toolId, $key);
        return $setting ? $setting->getValue() : $default;
    }

    /**
     * Create or update a setting
     */
    public function setSetting(
        string $toolId,
        string $key,
        ?string $value,
        string $type = 'text',
        ?string $label = null,
        ?string $description = null,
        int $displayOrder = 0
    ): AiToolSettings {
        $userDb = $this->getUserDb();
        $existing = $this->getSetting($toolId, $key);

        if ($existing) {
            $existing->setValue($value);
            $userDb->executeStatement(
                'UPDATE ai_tool_settings SET value = ?, updated_at = ? WHERE id = ?',
                [$value, (new \DateTime())->format('Y-m-d H:i:s'), $existing->getId()]
            );
            return $existing;
        }

        $setting = new AiToolSettings($toolId, $key, $value, $type, $label, $description, $displayOrder);
        $userDb->executeStatement(
            'INSERT INTO ai_tool_settings (id, tool_id, key, value, type, label, description, display_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $setting->getId(),
                $setting->getToolId(),
                $setting->getKey(),
                $setting->getValue(),
                $setting->getType(),
                $setting->getLabel(),
                $setting->getDescription(),
                $setting->getDisplayOrder(),
                $setting->getCreatedAt()->format('Y-m-d H:i:s'),
                $setting->getUpdatedAt()->format('Y-m-d H:i:s'),
            ]
        );

        return $setting;
    }

    /**
     * Bulk update settings for a tool (from Settings UI save)
     * @param array $settings Array of ['key' => value, ...] pairs
     */
    public function bulkUpdateValues(string $toolId, array $settings): array
    {
        $updated = [];
        foreach ($settings as $key => $value) {
            $existing = $this->getSetting($toolId, $key);
            if ($existing) {
                $existing->setValue($value);
                $userDb = $this->getUserDb();
                $userDb->executeStatement(
                    'UPDATE ai_tool_settings SET value = ?, updated_at = ? WHERE id = ?',
                    [$value, (new \DateTime())->format('Y-m-d H:i:s'), $existing->getId()]
                );
                $updated[] = $existing;
            }
        }

        return array_map(fn($s) => $s->jsonSerialize(), $updated);
    }

    /**
     * Delete a setting
     */
    public function deleteSetting(string $toolId, string $key): bool
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeStatement(
            'DELETE FROM ai_tool_settings WHERE tool_id = ? AND key = ?',
            [$toolId, $key]
        );
        return $result > 0;
    }

    /**
     * Delete all settings for a tool
     */
    public function deleteAllForTool(string $toolId): int
    {
        $userDb = $this->getUserDb();
        return $userDb->executeStatement(
            'DELETE FROM ai_tool_settings WHERE tool_id = ?',
            [$toolId]
        );
    }

    /**
     * Get category labels for UI display
     */
    public static function getCategoryLabels(): array
    {
        return [
            'file' => 'File Management',
            'web' => 'Web Tools',
            'image' => 'Image Generation',
            'memory' => 'Memory',
            'profile' => 'Profile',
            'development' => 'Development',
            'spirit' => 'Spirit',
            'utility' => 'Utility',
            'general' => 'General',
        ];
    }

    /**
     * Get category icons for UI display
     */
    public static function getCategoryIcons(): array
    {
        return [
            'file' => 'mdi-folder-open',
            'web' => 'mdi-web',
            'image' => 'mdi-image',
            'memory' => 'mdi-brain',
            'profile' => 'mdi-account-box',
            'development' => 'mdi-code-braces',
            'spirit' => 'mdi-ghost',
            'utility' => 'mdi-wrench',
            'general' => 'mdi-tools',
        ];
    }
}
