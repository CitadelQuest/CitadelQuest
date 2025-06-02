<?php

namespace App\Service;

use App\Entity\AiTool;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

class AiToolService
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
        return $this->userDatabaseManager->getDatabaseConnection($user);
    }

    /**
     * Create a new AI tool
     */
    public function createTool(
        string $name,
        string $description,
        array $parameters,
        bool $isActive = true
    ): AiTool {
        $parametersJson = json_encode($parameters);
        $tool = new AiTool($name, $description, $parametersJson, $isActive);

        // Store in user's database
        $userDb = $this->getUserDb();
        $userDb->executeStatement(
            'INSERT INTO ai_tool (id, name, description, parameters, is_active, created_at, updated_at) 
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $tool->getId(),
                $tool->getName(),
                $tool->getDescription(),
                $tool->getParameters(),
                $tool->isActive() ? 1 : 0,
                $tool->getCreatedAt()->format('Y-m-d H:i:s'),
                $tool->getUpdatedAt()->format('Y-m-d H:i:s')
            ]
        );

        return $tool;
    }

    /**
     * Find a tool by ID
     */
    public function findById(string $id): ?AiTool
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT * FROM ai_tool WHERE id = ?',
            [$id]
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        return AiTool::fromArray($result);
    }

    /**
     * Find a tool by name
     */
    public function findByName(string $name): ?AiTool
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT * FROM ai_tool WHERE name = ?',
            [$name]
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        return AiTool::fromArray($result);
    }

    /**
     * Find all tools
     */
    public function findAll(bool $activeOnly = false): array
    {
        $userDb = $this->getUserDb();
        $sql = 'SELECT * FROM ai_tool';
        $params = [];
        
        if ($activeOnly) {
            $sql .= ' WHERE is_active = ?';
            $params[] = 1;
        }
        
        $sql .= ' ORDER BY name ASC';
        
        $results = $userDb->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(fn($data) => AiTool::fromArray($data), $results);
    }

    /**
     * Update a tool
     */
    public function updateTool(
        string $id,
        array $data
    ): ?AiTool {
        $tool = $this->findById($id);
        if (!$tool) {
            return null;
        }

        $updates = [];
        $params = [];

        if (isset($data['name'])) {
            $updates[] = 'name = ?';
            $params[] = $data['name'];
            $tool->setName($data['name']);
        }

        if (isset($data['description'])) {
            $updates[] = 'description = ?';
            $params[] = $data['description'];
            $tool->setDescription($data['description']);
        }

        if (isset($data['parameters'])) {
            $parametersJson = is_string($data['parameters']) ? $data['parameters'] : json_encode($data['parameters']);
            $updates[] = 'parameters = ?';
            $params[] = $parametersJson;
            $tool->setParameters($parametersJson);
        }

        if (isset($data['isActive'])) {
            $updates[] = 'is_active = ?';
            $params[] = $data['isActive'] ? 1 : 0;
            $tool->setIsActive($data['isActive']);
        }

        if (!empty($updates)) {
            $tool->updateUpdatedAt();
            $updates[] = 'updated_at = ?';
            $params[] = $tool->getUpdatedAt()->format('Y-m-d H:i:s');

            $userDb = $this->getUserDb();
            $userDb->executeStatement(
                'UPDATE ai_tool SET ' . implode(', ', $updates) . ' WHERE id = ?',
                [...$params, $id]
            );
        }

        return $tool;
    }

    /**
     * Delete a tool
     */
    public function deleteTool(string $id): bool
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeStatement(
            'DELETE FROM ai_tool WHERE id = ?',
            [$id]
        );

        return $result > 0;
    }

    /**
     * Get tool definitions in a format compatible with AI services
     */
    public function getToolDefinitions(): array
    {
        $tools = $this->findAll(true); // Get only active tools
        $definitions = [];
        
        foreach ($tools as $tool) {
            $definitions[$tool->getName()] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'parameters' => $tool->getParametersAsArray()
            ];
        }
        
        return $definitions;
    }
}
