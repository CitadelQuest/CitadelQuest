<?php

namespace App\Service;

use App\Entity\Project;
use App\Entity\ProjectSpirit;
use App\Entity\ProjectSpiritConversation;
use App\Entity\ProjectTool;
use App\Entity\ProjectCqContact;
use App\Entity\User;
use App\Service\UserDatabaseManager;
use Symfony\Bundle\SecurityBundle\Security;

class ProjectService
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

    public function createProject(
        string $title,
        string $slug,
        ?string $description = null,
        bool $isPublic = false,
        bool $isActive = true,
        ?string $srcUrl = null
    ): Project {
        $project = new Project($title, $slug, $description, $isPublic, $isActive, $srcUrl);

        // Store in user's database
        $userDb = $this->getUserDb();
        $userDb->executeStatement(
            'INSERT INTO project (
                id, title, slug, description, is_public, is_active, src_url, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $project->getId(),
                $project->getTitle(),
                $project->getSlug(),
                $project->getDescription(),
                $project->isPublic() ? 1 : 0,
                $project->isActive() ? 1 : 0,
                $project->getSrcUrl(),
                $project->getCreatedAt()->format('Y-m-d H:i:s'),
                $project->getUpdatedAt()->format('Y-m-d H:i:s')
            ]
        );

        return $project;
    }

    public function findById(string $id): ?Project
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT * FROM project WHERE id = ?',
            [$id]
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        return Project::fromArray($result);
    }

    public function findBySlug(string $slug): ?Project
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT * FROM project WHERE slug = ?',
            [$slug]
        )->fetchAssociative();

        if (!$result) {
            return null;
        }

        return Project::fromArray($result);
    }

    public function findAll(bool $activeOnly = true): array
    {
        $userDb = $this->getUserDb();
        $sql = 'SELECT * FROM project';
        $params = [];
        
        if ($activeOnly) {
            $sql .= ' WHERE is_active = ?';
            $params[] = 1;
        }
        
        $sql .= ' ORDER BY created_at DESC';
        
        $results = $userDb->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(fn($data) => Project::fromArray($data), $results);
    }

    public function updateProject(Project $project): bool
    {
        $project->updateUpdatedAt();
        
        $userDb = $this->getUserDb();
        $result = $userDb->executeStatement(
            'UPDATE project SET 
                title = ?, slug = ?, description = ?, is_public = ?, is_active = ?, src_url = ?, updated_at = ?
             WHERE id = ?',
            [
                $project->getTitle(),
                $project->getSlug(),
                $project->getDescription(),
                $project->isPublic() ? 1 : 0,
                $project->isActive() ? 1 : 0,
                $project->getSrcUrl(),
                $project->getUpdatedAt()->format('Y-m-d H:i:s'),
                $project->getId()
            ]
        );

        return $result > 0;
    }

    public function deleteProject(string $id): bool
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeStatement(
            'DELETE FROM project WHERE id = ?',
            [$id]
        );

        // Vacuum the database
        $userDb->executeStatement('VACUUM;');

        return $result > 0;
    }

    // ProjectSpirit management
    public function addSpiritToProject(string $projectId, string $spiritId): ProjectSpirit
    {
        $projectSpirit = new ProjectSpirit($projectId, $spiritId);

        $userDb = $this->getUserDb();
        $userDb->executeStatement(
            'INSERT INTO project_spirit (id, project_id, spirit_id) VALUES (?, ?, ?)',
            [
                $projectSpirit->getId(),
                $projectSpirit->getProjectId(),
                $projectSpirit->getSpiritId()
            ]
        );

        return $projectSpirit;
    }

    public function removeSpiritFromProject(string $projectId, string $spiritId): bool
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeStatement(
            'DELETE FROM project_spirit WHERE project_id = ? AND spirit_id = ?',
            [$projectId, $spiritId]
        );

        return $result > 0;
    }

    public function getProjectSpirits(string $projectId): array
    {
        $userDb = $this->getUserDb();
        $results = $userDb->executeQuery(
            'SELECT * FROM project_spirit WHERE project_id = ?',
            [$projectId]
        )->fetchAllAssociative();

        return array_map(fn($data) => ProjectSpirit::fromArray($data), $results);
    }

    // ProjectSpiritConversation management
    public function createProjectSpiritConversation(
        string $projectId,
        string $spiritConversationId,
        ?string $category = null,
        ?string $systemPromptInstructions = null,
        ?string $taskList = null,
        ?string $taskListStatus = null,
        ?string $taskListResult = null,
        ?string $frontendData = null,
        bool $autorun = false
    ): ProjectSpiritConversation {
        $conversation = new ProjectSpiritConversation(
            $projectId,
            $spiritConversationId,
            $category,
            $systemPromptInstructions,
            $taskList,
            $taskListStatus,
            $taskListResult,
            $frontendData,
            $autorun
        );

        $userDb = $this->getUserDb();
        $userDb->executeStatement(
            'INSERT INTO project_spirit_conversation (
                id, project_id, spirit_conversation_id, category, system_prompt_instructions,
                task_list, task_list_status, task_list_result, frontend_data, autorun,
                created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $conversation->getId(),
                $conversation->getProjectId(),
                $conversation->getSpiritConversationId(),
                $conversation->getCategory(),
                $conversation->getSystemPromptInstructions(),
                $conversation->getTaskList(),
                $conversation->getTaskListStatus(),
                $conversation->getTaskListResult(),
                $conversation->getFrontendData(),
                $conversation->isAutorun() ? 1 : 0,
                $conversation->getCreatedAt()->format('Y-m-d H:i:s'),
                $conversation->getUpdatedAt()->format('Y-m-d H:i:s')
            ]
        );

        return $conversation;
    }

    public function getProjectConversations(string $projectId): array
    {
        $userDb = $this->getUserDb();
        $results = $userDb->executeQuery(
            'SELECT * FROM project_spirit_conversation WHERE project_id = ? ORDER BY created_at DESC',
            [$projectId]
        )->fetchAllAssociative();

        return array_map(fn($data) => ProjectSpiritConversation::fromArray($data), $results);
    }

    // ProjectTool management
    public function addToolToProject(string $projectId, string $toolId): ProjectTool
    {
        $projectTool = new ProjectTool($projectId, $toolId);

        $userDb = $this->getUserDb();
        $userDb->executeStatement(
            'INSERT INTO project_tool (id, project_id, tool_id) VALUES (?, ?, ?)',
            [
                $projectTool->getId(),
                $projectTool->getProjectId(),
                $projectTool->getToolId()
            ]
        );

        return $projectTool;
    }

    public function removeToolFromProject(string $projectId, string $toolId): bool
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeStatement(
            'DELETE FROM project_tool WHERE project_id = ? AND tool_id = ?',
            [$projectId, $toolId]
        );

        return $result > 0;
    }

    public function getProjectTools(string $projectId): array
    {
        $userDb = $this->getUserDb();
        $results = $userDb->executeQuery(
            'SELECT * FROM project_tool WHERE project_id = ?',
            [$projectId]
        )->fetchAllAssociative();

        return array_map(fn($data) => ProjectTool::fromArray($data), $results);
    }

    // ProjectCqContact management
    public function addContactToProject(string $projectId, string $cqContactId): ProjectCqContact
    {
        $projectContact = new ProjectCqContact($projectId, $cqContactId);

        $userDb = $this->getUserDb();
        $userDb->executeStatement(
            'INSERT INTO project_cq_contact (id, project_id, cq_contact_id) VALUES (?, ?, ?)',
            [
                $projectContact->getId(),
                $projectContact->getProjectId(),
                $projectContact->getCqContactId()
            ]
        );

        return $projectContact;
    }

    public function removeContactFromProject(string $projectId, string $cqContactId): bool
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeStatement(
            'DELETE FROM project_cq_contact WHERE project_id = ? AND cq_contact_id = ?',
            [$projectId, $cqContactId]
        );

        return $result > 0;
    }

    public function getProjectContacts(string $projectId): array
    {
        $userDb = $this->getUserDb();
        $results = $userDb->executeQuery(
            'SELECT * FROM project_cq_contact WHERE project_id = ?',
            [$projectId]
        )->fetchAllAssociative();

        return array_map(fn($data) => ProjectCqContact::fromArray($data), $results);
    }
}
