<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\Connection;

class UserKeyManager
{
    public function __construct(
        private UserDatabaseManager $databaseManager
    ) {}

    /**
     * Store user's keys in their personal database
     */
    public function storeKeys(User $user, string $publicKey, string $encryptedPrivateKey, string $keySalt): void
    {
        $connection = $this->databaseManager->getDatabaseConnection($user);
        
        // First, clear any existing main key pair
        $this->clearMainKeyPair($connection);
        
        // Insert the new key pair
        $connection->executeStatement(
            'INSERT INTO keys (key_type, public_key, encrypted_private_key, key_salt, created_at, expires_at) VALUES (?, ?, ?, ?, datetime("now"), datetime("now", "+1 day"))',
            ['main', $publicKey, $encryptedPrivateKey, $keySalt]
        );
    }

    /**
     * Get user's current keys from their personal database
     */
    public function getKeys(User $user): ?array
    {
        $connection = $this->databaseManager->getDatabaseConnection($user);
        
        $result = $connection->executeQuery(
            'SELECT public_key, encrypted_private_key, key_salt FROM keys WHERE key_type = ? AND expires_at > datetime("now") ORDER BY created_at DESC LIMIT 1',
            ['main']
        )->fetchAssociative();
        
        if (!$result) {
            return null;
        }

        return [
            'publicKey' => $result['public_key'],
            'encryptedPrivateKey' => $result['encrypted_private_key'],
            'keySalt' => $result['key_salt']
        ];
    }

    /**
     * Clear expired keys and the current main key pair
     */
    private function clearMainKeyPair(Connection $connection): void
    {
        // Delete expired keys
        $connection->executeStatement(
            'DELETE FROM keys WHERE expires_at <= datetime("now")'
        );
        
        // Delete current main key pair
        $connection->executeStatement(
            'DELETE FROM keys WHERE key_type = ?',
            ['main']
        );
    }

    /**
     * Rotate user's keys (create new pair and mark old as expired)
     */
    public function rotateKeys(User $user, string $publicKey, string $encryptedPrivateKey, string $keySalt): void
    {
        $connection = $this->databaseManager->getDatabaseConnection($user);
        
        // Mark existing keys as expired
        $connection->executeStatement(
            'UPDATE keys SET expires_at = datetime("now") WHERE key_type = ? AND expires_at > datetime("now")',
            ['main']
        );
        
        // Store new keys
        $this->storeKeys($user, $publicKey, $encryptedPrivateKey, $keySalt);
    }
}
