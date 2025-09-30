<?php

namespace App\Service;

use App\Entity\SystemSettings;
use Doctrine\ORM\EntityManagerInterface;

class SystemSettingsService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {}

    /**
     * Get a setting by key
     */
    public function getSetting(string $key): ?SystemSettings
    {
        return $this->entityManager
            ->getRepository(SystemSettings::class)
            ->findOneBy(['settingKey' => $key]);
    }

    /**
     * Get a setting value by key with optional default
     */
    public function getSettingValue(string $key, ?string $default = null): ?string
    {
        $setting = $this->getSetting($key);
        return $setting ? $setting->getValue() : $default;
    }

    /**
     * Get a boolean setting value
     */
    public function getBooleanValue(string $key, bool $default = false): bool
    {
        $value = $this->getSettingValue($key);
        if ($value === null) {
            return $default;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Set a setting value (create or update)
     */
    public function setSetting(string $key, ?string $value): SystemSettings
    {
        $setting = $this->getSetting($key);
        
        if ($setting) {
            $setting->setValue($value);
        } else {
            $setting = new SystemSettings($key, $value);
            $this->entityManager->persist($setting);
        }
        
        $this->entityManager->flush();
        
        return $setting;
    }

    /**
     * Set a boolean setting value
     */
    public function setBooleanValue(string $key, bool $value): SystemSettings
    {
        return $this->setSetting($key, $value ? '1' : '0');
    }

    /**
     * Delete a setting by key
     */
    public function deleteSetting(string $key): bool
    {
        $setting = $this->getSetting($key);
        
        if ($setting) {
            $this->entityManager->remove($setting);
            $this->entityManager->flush();
            return true;
        }
        
        return false;
    }

    /**
     * Get all settings as key => value array
     */
    public function getAllSettings(): array
    {
        $settings = $this->entityManager
            ->getRepository(SystemSettings::class)
            ->findAll();
        
        $result = [];
        foreach ($settings as $setting) {
            $result[$setting->getSettingKey()] = $setting->getValue();
        }
        
        return $result;
    }
}
