<?php

namespace App\Api\Controller;

use App\Service\AiToolService;
use App\Service\AiToolSettingsService;
use App\Service\AiToolPolicyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/ai/tool')]
#[IsGranted('ROLE_USER')]
class AiToolApiController extends AbstractController
{
    public function __construct(
        private readonly AiToolService $aiToolService,
        private readonly AiToolSettingsService $aiToolSettingsService,
        private readonly AiToolPolicyService $aiToolPolicyService
    ) {
    }

    #[Route('', name: 'app_api_ai_tool_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $activeOnly = $request->query->getBoolean('active_only', false);
        $tools = $this->aiToolService->findAll($activeOnly);

        return $this->json([
            'tools' => array_map(fn($tool) => $tool->jsonSerialize(), $tools),
            'isAdmin' => $this->aiToolService->currentUserIsAdmin()
        ]);
    }

    /**
     * Set the Citadel-level admin-only policy for a tool (admins only).
     */
    #[Route('/{id}/admin-only', name: 'app_api_ai_tool_admin_only', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function setAdminOnly(string $id, Request $request): JsonResponse
    {
        $tool = $this->aiToolService->findById($id);
        if (!$tool) {
            return $this->json(['error' => 'Tool not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !array_key_exists('adminOnly', $data)) {
            return $this->json(['error' => 'Missing adminOnly flag'], Response::HTTP_BAD_REQUEST);
        }

        $adminOnly = (bool) $data['adminOnly'];
        $this->aiToolPolicyService->setAdminOnly($tool->getName(), $adminOnly);

        return $this->json([
            'success' => true,
            'toolName' => $tool->getName(),
            'adminOnly' => $adminOnly
        ]);
    }

    #[Route('', name: 'app_api_ai_tool_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['name']) || !isset($data['description']) || !isset($data['parameters'])) {
            return $this->json(['error' => 'Missing required fields'], Response::HTTP_BAD_REQUEST);
        }

        $tool = $this->aiToolService->createTool(
            $data['name'],
            $data['description'],
            $data['parameters'],
            $data['isActive'] ?? true
        );

        return $this->json([
            'tool' => $tool->jsonSerialize()
        ], Response::HTTP_CREATED);
    }

    #[Route('/definitions', name: 'app_api_ai_tool_definitions', methods: ['GET'])]
    public function getDefinitions(): JsonResponse
    {
        $definitions = $this->aiToolService->getToolDefinitions();
        
        return $this->json($definitions);
    }

    #[Route('/{id}', name: 'app_api_ai_tool_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $tool = $this->aiToolService->findById($id);
        
        if (!$tool) {
            return $this->json(['error' => 'Tool not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($tool->jsonSerialize());
    }

    #[Route('/{id}', name: 'app_api_ai_tool_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (empty($data)) {
            return $this->json(['error' => 'No update data provided'], Response::HTTP_BAD_REQUEST);
        }

        $tool = $this->aiToolService->updateTool(
            $id,
            $data
        );

        if (!$tool) {
            return $this->json(['error' => 'Tool not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'tool' => $tool->jsonSerialize()
        ]);
    }

    #[Route('/{id}', name: 'app_api_ai_tool_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $result = $this->aiToolService->deleteTool($id);
        
        if (!$result) {
            return $this->json(['error' => 'Tool not found'], Response::HTTP_NOT_FOUND);
        }
        
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/categories', name: 'app_api_ai_tool_categories', methods: ['GET'], priority: 10)]
    public function getCategories(): JsonResponse
    {
        return $this->json([
            'labels' => AiToolSettingsService::getCategoryLabels(),
            'icons' => AiToolSettingsService::getCategoryIcons(),
        ]);
    }

    #[Route('/{id}/settings', name: 'app_api_ai_tool_settings_list', methods: ['GET'])]
    public function getToolSettings(string $id): JsonResponse
    {
        $tool = $this->aiToolService->findById($id);
        if (!$tool) {
            return $this->json(['error' => 'Tool not found'], Response::HTTP_NOT_FOUND);
        }

        $settings = $this->aiToolSettingsService->getSettingsForTool($id);

        return $this->json([
            'settings' => array_map(fn($s) => $s->jsonSerialize(), $settings)
        ]);
    }

    #[Route('/{id}/settings', name: 'app_api_ai_tool_settings_save', methods: ['PUT'])]
    public function saveToolSettings(string $id, Request $request): JsonResponse
    {
        $tool = $this->aiToolService->findById($id);
        if (!$tool) {
            return $this->json(['error' => 'Tool not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        if (empty($data) || !isset($data['settings'])) {
            return $this->json(['error' => 'Missing settings data'], Response::HTTP_BAD_REQUEST);
        }

        $updated = $this->aiToolSettingsService->bulkUpdateValues($id, $data['settings']);

        return $this->json([
            'success' => true,
            'settings' => $updated
        ]);
    }
}
