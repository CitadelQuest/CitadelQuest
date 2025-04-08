<?php

namespace App\Service;

use App\Entity\AiGateway;
use App\Service\UserDatabaseManager;
use Symfony\Component\Security\Core\User\UserInterface;

class AiGatewayService
{
    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager
    ) {
    }

    public function createGateway(
        UserInterface $user,
        string $name,
        string $apiKey,
        string $apiEndpointUrl
    ): AiGateway {
        $gateway = new AiGateway($name, $apiKey, $apiEndpointUrl);

        // Store in user's database
        $userDb = $this->userDatabaseManager->getDatabaseConnection($user);
        $userDb->executeStatement(
            'INSERT INTO ai_gateway (id, name, api_key, api_endpoint_url, created_at, updated_at) 
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $gateway->getId(),
                $gateway->getName(),
                $gateway->getApiKey(),
                $gateway->getApiEndpointUrl(),
                $gateway->getCreatedAt()->format('Y-m-d H:i:s'),
                $gateway->getUpdatedAt()->format('Y-m-d H:i:s')
            ]
        );

        return $gateway;
    }

    public function findById(UserInterface $user, string $id): ?AiGateway
    {
        $userDb = $this->userDatabaseManager->getDatabaseConnection($user);
        $result = $userDb->executeQuery(
            'SELECT * FROM ai_gateway WHERE id = ?',
            [$id]
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        return AiGateway::fromArray($result);
    }

    public function findAll(UserInterface $user): array
    {
        $userDb = $this->userDatabaseManager->getDatabaseConnection($user);
        $results = $userDb->executeQuery(
            'SELECT * FROM ai_gateway ORDER BY name ASC'
        )->fetchAllAssociative();

        return array_map(fn($data) => AiGateway::fromArray($data), $results);
    }

    public function updateGateway(
        UserInterface $user,
        string $id,
        array $data
    ): ?AiGateway {
        $gateway = $this->findById($user, $id);
        if (!$gateway) {
            return null;
        }

        if (isset($data['name'])) {
            $gateway->setName($data['name']);
        }

        if (isset($data['apiKey'])) {
            $gateway->setApiKey($data['apiKey']);
        }

        if (isset($data['apiEndpointUrl'])) {
            $gateway->setApiEndpointUrl($data['apiEndpointUrl']);
        }

        $gateway->setUpdatedAt(new \DateTime());

        $userDb = $this->userDatabaseManager->getDatabaseConnection($user);
        $userDb->executeStatement(
            'UPDATE ai_gateway SET 
             name = ?, 
             api_key = ?, 
             api_endpoint_url = ?, 
             updated_at = ? 
             WHERE id = ?',
            [
                $gateway->getName(),
                $gateway->getApiKey(),
                $gateway->getApiEndpointUrl(),
                $gateway->getUpdatedAt()->format('Y-m-d H:i:s'),
                $gateway->getId()
            ]
        );

        return $gateway;
    }

    public function deleteGateway(UserInterface $user, string $id): bool
    {
        $userDb = $this->userDatabaseManager->getDatabaseConnection($user);
        $result = $userDb->executeStatement(
            'DELETE FROM ai_gateway WHERE id = ?',
            [$id]
        );

        return $result > 0;
    }
}
