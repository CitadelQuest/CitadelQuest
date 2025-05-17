<?php

namespace App\Service;

use App\Entity\Settings;
use App\Entity\User;
use App\Service\UserDatabaseManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

class SettingsService
{
    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
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
        if (!$user) {
            throw new \Exception('User not found');
        }
        return $this->userDatabaseManager->getDatabaseConnection($user);
    }

    /**
     * Create or update a setting
     */
    public function setSetting(string $key, ?string $value): ?Settings
    {
        try {
            $userDb = $this->getUserDb();
            
            // Check if setting already exists
            $existingSetting = $this->getSetting($key);
            
            if ($existingSetting) {
                // Update existing setting
                $existingSetting->setValue($value);
                
                $userDb->executeStatement(
                    'UPDATE settings SET value = ?, updated_at = ? WHERE id = ?',
                    [
                        $existingSetting->getValue(),
                        $existingSetting->getUpdatedAt()->format('Y-m-d H:i:s'),
                        $existingSetting->getId()
                    ]
                );
                
                return $existingSetting;
            } else {
                // Create new setting
                $setting = new Settings($key, $value);
                
                $userDb->executeStatement(
                    'INSERT INTO settings (id, `key`, value, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
                    [
                        $setting->getId(),
                        $setting->getKey(),
                        $setting->getValue(),
                        $setting->getCreatedAt()->format('Y-m-d H:i:s'),
                        $setting->getUpdatedAt()->format('Y-m-d H:i:s')
                    ]
                );
                
                return $setting;
            }
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get a setting by key
     */
    public function getSetting(string $key): ?Settings
    {
        try {
            $userDb = $this->getUserDb();
            $result = $userDb->executeQuery(
                'SELECT * FROM settings WHERE `key` = ?',
                [$key]
            )->fetchAssociative();

            if (!$result) {
                return null;
            }

            return Settings::fromArray($result);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get a setting value by key
     */
    public function getSettingValue(string $key, ?string $default = null): ?string
    {
        $setting = $this->getSetting($key);
        return $setting ? $setting->getValue() : $default;
    }

    /**
     * Delete a setting by key
     */
    public function deleteSetting(string $key): bool
    {
        try {
            $userDb = $this->getUserDb();
            $result = $userDb->executeStatement(
                'DELETE FROM settings WHERE `key` = ?',
                [$key]
            );

            return $result > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get all settings
     * 
     * @return Settings[] as key => value
     */
    public function getAllSettings(): array
    {
        try {
            $userDb = $this->getUserDb();
            $results = $userDb->executeQuery(
                'SELECT * FROM settings ORDER BY `key` ASC'
            )->fetchAllAssociative();

            $settings = array_map(fn($data) => Settings::fromArray($data), $results);
            return array_combine(array_map(fn($setting) => $setting->getKey(), $settings), array_map(fn($setting) => $setting->getValue(), $settings));
        } catch (\Exception $e) {
            return [];
        }
    }
}
