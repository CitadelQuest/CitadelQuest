<?php

namespace App\Api\Controller;

use App\Service\ProjectService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Psr\Log\LoggerInterface;

/**
 * Project (workspace) CRUD API.
 *
 * See docs/features/CQ-PROJECT.md.
 */
#[Route('/api/project')]
#[IsGranted('ROLE_USER')]
class ProjectApiController extends AbstractController
{
    public function __construct(
        private readonly ProjectService $projectService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * List all projects plus the current active project id.
     */
    #[Route('', name: 'app_api_project_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        try {
            $projects = $this->projectService->findAll();

            return $this->json([
                'success' => true,
                'projects' => $projects,
                'activeProjectId' => $this->resolveActiveProjectId()
            ]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * Create a new project.
     */
    #[Route('', name: 'app_api_project_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true) ?? [];

            $title = trim((string) ($data['title'] ?? ''));
            if ($title === '') {
                return $this->json(['success' => false, 'error' => 'Title is required'], 400);
            }

            $project = $this->projectService->createProject(
                $title,
                isset($data['description']) ? trim((string) $data['description']) ?: null : null,
                (bool) ($data['isPublic'] ?? false)
            );

            return $this->json(['success' => true, 'project' => $project], 201);
        } catch (\Exception $e) {
            $this->logger->error('Project create failed', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * Update a project (title / description / visibility).
     */
    #[Route('/{id}', name: 'app_api_project_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        try {
            $project = $this->projectService->findById($id);
            if (!$project) {
                return $this->json(['success' => false, 'error' => 'Project not found'], 404);
            }

            $data = json_decode($request->getContent(), true) ?? [];

            if (isset($data['title'])) {
                $title = trim((string) $data['title']);
                if ($title === '') {
                    return $this->json(['success' => false, 'error' => 'Title cannot be empty'], 400);
                }
                $project->setTitle($title);
            }
            if (array_key_exists('description', $data)) {
                $project->setDescription(trim((string) $data['description']) ?: null);
            }
            if (isset($data['isPublic'])) {
                $project->setIsPublic((bool) $data['isPublic']);
            }

            $this->projectService->updateProject($project);

            return $this->json(['success' => true, 'project' => $project]);
        } catch (\Exception $e) {
            $this->logger->error('Project update failed', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * Delete a project and everything in it (cascade).
     */
    #[Route('/{id}', name: 'app_api_project_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        try {
            $this->projectService->deleteProject($id);

            return $this->json(['success' => true]);
        } catch (\RuntimeException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            $this->logger->error('Project delete failed', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * Get the Spirits assigned to a project.
     */
    #[Route('/{id}/spirits', name: 'app_api_project_spirits_get', methods: ['GET'])]
    public function getSpirits(string $id): JsonResponse
    {
        try {
            if (!$this->projectService->findById($id)) {
                return $this->json(['success' => false, 'error' => 'Project not found'], 404);
            }

            return $this->json([
                'success' => true,
                'spiritIds' => $this->projectService->getProjectSpiritIds($id),
            ]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * Replace the set of Spirits assigned to a project.
     */
    #[Route('/{id}/spirits', name: 'app_api_project_spirits_update', methods: ['PUT'])]
    public function updateSpirits(string $id, Request $request): JsonResponse
    {
        try {
            if (!$this->projectService->findById($id)) {
                return $this->json(['success' => false, 'error' => 'Project not found'], 404);
            }

            $data = json_decode($request->getContent(), true) ?? [];
            $spiritIds = is_array($data['spiritIds'] ?? null) ? $data['spiritIds'] : [];

            $this->projectService->setProjectSpirits($id, $spiritIds);

            return $this->json([
                'success' => true,
                'spiritIds' => $this->projectService->getProjectSpiritIds($id),
            ]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * Set the active project (single source of truth).
     */
    #[Route('/active', name: 'app_api_project_set_active', methods: ['POST'])]
    public function setActive(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true) ?? [];
            $id = trim((string) ($data['id'] ?? ''));

            if ($id === '' || !$this->projectService->findById($id)) {
                return $this->json(['success' => false, 'error' => 'Project not found'], 404);
            }

            $this->projectService->setActiveProjectId($id);

            return $this->json(['success' => true, 'activeProjectId' => $id]);
        } catch (\Exception $e) {
            $this->logger->error('Project set-active failed', ['error' => $e->getMessage()]);
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * Resolve the active project id, falling back to 'general' when unset or stale.
     */
    private function resolveActiveProjectId(): string
    {
        return $this->projectService->getActiveProjectId();
    }
}
