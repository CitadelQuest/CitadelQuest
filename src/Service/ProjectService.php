<?php

namespace App\Service;

use App\Entity\Project;
use App\Entity\ProjectSpirit;
use App\Entity\ProjectSpiritConversation;
use App\Entity\ProjectCqContact;
use App\Entity\User;
use App\Service\UserDatabaseManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Manages Projects (workspaces).
 *
 * A project `id` is a short, human-readable, immutable slug (e.g. 'general',
 * 'marketing') and is used everywhere as `projectId` — filesystem paths,
 * `project_file.project_id`, URLs and `settings.active_project_id`.
 *
 * See docs/features/CQ-PROJECT.md.
 */
class ProjectService
{
    /** Reserved id of the always-present, non-deletable system project. */
    public const GENERAL_PROJECT_ID = 'general';

    /** Max length of a project id (matches project.id VARCHAR(64)). */
    private const MAX_ID_LENGTH = 64;

    /**
     * Transient per-turn project context stack (used during S2S consultations so
     * a callee Spirit executes in its own assigned project rather than the caller's).
     *
     * @var string[]
     */
    private array $projectContextStack = [];

    public function __construct(
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly Security $security,
        private readonly ProjectFileService $projectFileService,
        private readonly SluggerInterface $slugger,
        private readonly SettingsService $settingsService
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

    // ---------------------------------------------------------------------
    // Project
    // ---------------------------------------------------------------------

    public function createProject(
        string $title,
        ?string $description = null,
        bool $isPublic = false,
        bool $isArchived = false,
        ?string $srcUrl = null
    ): Project {
        $id = $this->generateUniqueId($title);
        $project = new Project($id, $title, $description, $isPublic, $isArchived, false, $srcUrl);

        $userDb = $this->getUserDb();
        $userDb->executeStatement(
            'INSERT INTO project (
                id, title, description, is_public, is_archived, is_system, src_url, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $project->getId(),
                $project->getTitle(),
                $project->getDescription(),
                $project->isPublic() ? 1 : 0,
                $project->isArchived() ? 1 : 0,
                $project->isSystem() ? 1 : 0,
                $project->getSrcUrl(),
                $project->getCreatedAt()->format('Y-m-d H:i:s'),
                $project->getUpdatedAt()->format('Y-m-d H:i:s')
            ]
        );

        return $project;
    }

    /**
     * Derive a unique, url/filesystem-safe id from a title.
     * Reserved ids (e.g. 'general') are never handed out to user projects.
     */
    public function generateUniqueId(string $title): string
    {
        $base = strtolower($this->slugger->slug($title)->toString());
        $base = trim($base, '-');
        if ($base === '') {
            $base = 'project';
        }
        $base = substr($base, 0, self::MAX_ID_LENGTH);

        $candidate = $base;
        $suffix = 2;
        while ($candidate === self::GENERAL_PROJECT_ID || $this->idExists($candidate)) {
            $suffixStr = '-' . $suffix;
            $candidate = substr($base, 0, self::MAX_ID_LENGTH - strlen($suffixStr)) . $suffixStr;
            $suffix++;
        }

        return $candidate;
    }

    public function idExists(string $id): bool
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT 1 FROM project WHERE id = ?',
            [$id]
        )->fetchOne();

        return $result !== false;
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

    public function getGeneralProject(): ?Project
    {
        return $this->findById(self::GENERAL_PROJECT_ID);
    }

    /**
     * The currently-selected workspace (settings.active_project_id), or the top of
     * the transient S2S project context stack when inside a cross-project spiritCall.
     * Falls back to 'general' when unset or stale. Safe in CLI/background contexts.
     */
    public function getActiveProjectId(): string
    {
        if (!empty($this->projectContextStack)) {
            return (string) end($this->projectContextStack);
        }

        try {
            $activeId = $this->settingsService->getSettingValue('active_project_id', self::GENERAL_PROJECT_ID)
                ?: self::GENERAL_PROJECT_ID;

            if ($activeId !== self::GENERAL_PROJECT_ID && !$this->findById($activeId)) {
                return self::GENERAL_PROJECT_ID;
            }

            return $activeId;
        } catch (\Exception $e) {
            return self::GENERAL_PROJECT_ID;
        }
    }

    /**
     * Push a transient project context (e.g. for the duration of an S2S turn).
     */
    public function pushProjectContext(string $projectId): void
    {
        $this->projectContextStack[] = $projectId;
    }

    /**
     * Pop the most recently pushed transient project context.
     */
    public function popProjectContext(): void
    {
        array_pop($this->projectContextStack);
    }

    /**
     * Resolve the effective project for a specific Spirit:
     * - If $preferredProjectId is given and the Spirit belongs to it, use it.
     * - Otherwise if the current active project is one of the Spirit's projects, use it.
     * - Otherwise fall back to the Spirit's first assigned project (or 'general').
     *
     * Ensures a Spirit never runs in or initializes files inside a project it is
     * not assigned to.
     */
    public function resolveProjectIdForSpirit(string $spiritId, ?string $preferredProjectId = null): string
    {
        try {
            $assignedIds = $this->getProjectIdsForSpirit($spiritId);
        } catch (\Exception $e) {
            $assignedIds = [];
        }

        if (empty($assignedIds)) {
            return self::GENERAL_PROJECT_ID;
        }

        if ($preferredProjectId !== null && in_array($preferredProjectId, $assignedIds, true)) {
            return $preferredProjectId;
        }

        $activeId = $this->getActiveProjectId();
        if (in_array($activeId, $assignedIds, true)) {
            return $activeId;
        }

        if (in_array(self::GENERAL_PROJECT_ID, $assignedIds, true)) {
            return self::GENERAL_PROJECT_ID;
        }

        return $assignedIds[0];
    }

    /**
     * Whether a Spirit is assigned to the given project.
     */
    public function isSpiritInProject(string $spiritId, string $projectId): bool
    {
        try {
            return in_array($projectId, $this->getProjectIdsForSpirit($spiritId), true);
        } catch (\Exception $e) {
            return $projectId === self::GENERAL_PROJECT_ID;
        }
    }

    /**
     * Set the active project (single source of truth).
     */
    public function setActiveProjectId(string $projectId): void
    {
        if (!$this->findById($projectId)) {
            throw new \RuntimeException('Project not found');
        }

        $this->settingsService->setSetting('active_project_id', $projectId);
    }

    public function findAll(bool $includeArchived = false): array
    {
        $userDb = $this->getUserDb();
        $sql = 'SELECT * FROM project';
        $params = [];

        if (!$includeArchived) {
            $sql .= ' WHERE is_archived = 0';
        }

        $sql .= ' ORDER BY is_system DESC, title ASC';

        $results = $userDb->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(fn($data) => Project::fromArray($data), $results);
    }

    public function updateProject(Project $project): bool
    {
        $project->updateUpdatedAt();

        $userDb = $this->getUserDb();
        $result = $userDb->executeStatement(
            'UPDATE project SET
                title = ?, description = ?, is_public = ?, is_archived = ?, src_url = ?, updated_at = ?
             WHERE id = ?',
            [
                $project->getTitle(),
                $project->getDescription(),
                $project->isPublic() ? 1 : 0,
                $project->isArchived() ? 1 : 0,
                $project->getSrcUrl(),
                $project->getUpdatedAt()->format('Y-m-d H:i:s'),
                $project->getId()
            ]
        );

        return $result > 0;
    }

    /**
     * Delete a project and everything in it (files + assignments).
     *
     * System projects (e.g. 'general') cannot be deleted.
     */
    public function deleteProject(string $id): bool
    {
        if ($id === self::GENERAL_PROJECT_ID) {
            throw new \RuntimeException('The General project cannot be deleted.');
        }

        $project = $this->findById($id);
        if (!$project) {
            return false;
        }

        if ($project->isSystem()) {
            throw new \RuntimeException('System projects cannot be deleted.');
        }

        // Cascade: remove all files (database records + the whole project directory).
        $this->projectFileService->deleteProjectFiles($id);

        $userDb = $this->getUserDb();
        $userDb->executeStatement('DELETE FROM project_spirit WHERE project_id = ?', [$id]);
        $userDb->executeStatement('DELETE FROM project_spirit_conversation WHERE project_id = ?', [$id]);
        $userDb->executeStatement('DELETE FROM project_cq_contact WHERE project_id = ?', [$id]);

        $result = $userDb->executeStatement('DELETE FROM project WHERE id = ?', [$id]);

        return $result > 0;
    }

    // ---------------------------------------------------------------------
    // ProjectSpirit — which Spirits belong to a project
    // ---------------------------------------------------------------------

    public function addSpiritToProject(string $projectId, string $spiritId): ProjectSpirit
    {
        $projectSpirit = new ProjectSpirit($projectId, $spiritId);

        $userDb = $this->getUserDb();
        $userDb->executeStatement(
            'INSERT OR IGNORE INTO project_spirit (id, project_id, spirit_id) VALUES (?, ?, ?)',
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

    /**
     * @return string[] Spirit ids assigned to the given project
     */
    public function getProjectSpiritIds(string $projectId): array
    {
        $userDb = $this->getUserDb();
        return $userDb->executeQuery(
            'SELECT spirit_id FROM project_spirit WHERE project_id = ?',
            [$projectId]
        )->fetchFirstColumn();
    }

    /**
     * Replace the set of Spirits assigned to a project.
     *
     * @param string[] $spiritIds
     */
    public function setProjectSpirits(string $projectId, array $spiritIds): void
    {
        $userDb = $this->getUserDb();
        $userDb->executeStatement('DELETE FROM project_spirit WHERE project_id = ?', [$projectId]);

        foreach (array_unique($spiritIds) as $spiritId) {
            $this->addSpiritToProject($projectId, (string) $spiritId);
        }
    }

    /**
     * @return string[] Project ids a Spirit belongs to
     */
    public function getProjectIdsForSpirit(string $spiritId): array
    {
        $userDb = $this->getUserDb();
        return $userDb->executeQuery(
            'SELECT project_id FROM project_spirit WHERE spirit_id = ?',
            [$spiritId]
        )->fetchFirstColumn();
    }

    /**
     * Replace the set of projects a Spirit belongs to.
     *
     * @param string[] $projectIds
     */
    public function setSpiritProjects(string $spiritId, array $projectIds): void
    {
        $userDb = $this->getUserDb();
        $userDb->executeStatement('DELETE FROM project_spirit WHERE spirit_id = ?', [$spiritId]);

        foreach (array_unique($projectIds) as $projectId) {
            $this->addSpiritToProject((string) $projectId, $spiritId);
        }
    }

    // ---------------------------------------------------------------------
    // ProjectSpiritConversation — pair a conversation to its project
    // ---------------------------------------------------------------------

    public function createProjectSpiritConversation(string $projectId, string $spiritConversationId): ProjectSpiritConversation
    {
        $conversation = new ProjectSpiritConversation($projectId, $spiritConversationId);

        $userDb = $this->getUserDb();
        $userDb->executeStatement(
            'INSERT OR IGNORE INTO project_spirit_conversation (
                id, project_id, spirit_conversation_id, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?)',
            [
                $conversation->getId(),
                $conversation->getProjectId(),
                $conversation->getSpiritConversationId(),
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

    /**
     * @return string[] Conversation ids belonging to the given project
     */
    public function getProjectConversationIds(string $projectId): array
    {
        $userDb = $this->getUserDb();
        return $userDb->executeQuery(
            'SELECT spirit_conversation_id FROM project_spirit_conversation WHERE project_id = ?',
            [$projectId]
        )->fetchFirstColumn();
    }

    public function removeConversationFromProject(string $projectId, string $spiritConversationId): bool
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeStatement(
            'DELETE FROM project_spirit_conversation WHERE project_id = ? AND spirit_conversation_id = ?',
            [$projectId, $spiritConversationId]
        );

        return $result > 0;
    }

    /**
     * Whether a conversation belongs to the given project.
     */
    public function isConversationInProject(string $spiritConversationId, string $projectId): bool
    {
        $userDb = $this->getUserDb();
        $result = $userDb->executeQuery(
            'SELECT 1 FROM project_spirit_conversation WHERE project_id = ? AND spirit_conversation_id = ?',
            [$projectId, $spiritConversationId]
        )->fetchOne();

        return $result !== false;
    }

    // ---------------------------------------------------------------------
    // ProjectCqContact — reserved for the future federation phase
    // ---------------------------------------------------------------------

    public function addContactToProject(string $projectId, string $cqContactId): ProjectCqContact
    {
        $projectContact = new ProjectCqContact($projectId, $cqContactId);

        $userDb = $this->getUserDb();
        $userDb->executeStatement(
            'INSERT OR IGNORE INTO project_cq_contact (id, project_id, cq_contact_id) VALUES (?, ?, ?)',
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
