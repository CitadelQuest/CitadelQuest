<?php

namespace App\Api\Controller;

use App\Service\AiUserSettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/ai/settings')]
#[IsGranted('ROLE_USER')]
class AiUserSettingsApiController extends AbstractController
{
    public function __construct(
        private readonly AiUserSettingsService $aiUserSettingsService
    ) {
    }

    #[Route('', name: 'app_api_ai_user_settings_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        $settings = $this->aiUserSettingsService->findForUser($this->getUser());
        
        if (!$settings) {
            return $this->json(['error' => 'No settings found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'settings' => $settings->jsonSerialize()
        ]);
    }

    #[Route('', name: 'app_api_ai_user_settings_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['aiGatewayId'])) {
            return $this->json(['error' => 'Missing required fields'], Response::HTTP_BAD_REQUEST);
        }

        $settings = $this->aiUserSettingsService->createSettings(
            $this->getUser(),
            $data['aiGatewayId'],
            $data['primaryAiServiceModelId'] ?? null,
            $data['secondaryAiServiceModelId'] ?? null
        );

        return $this->json([
            'settings' => $settings->jsonSerialize()
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'app_api_ai_user_settings_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (empty($data)) {
            return $this->json(['error' => 'No update data provided'], Response::HTTP_BAD_REQUEST);
        }

        $settings = $this->aiUserSettingsService->updateSettings(
            $this->getUser(),
            $id,
            $data
        );

        if (!$settings) {
            return $this->json(['error' => 'Settings not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'settings' => $settings->jsonSerialize()
        ]);
    }

    #[Route('/{id}', name: 'app_api_ai_user_settings_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $result = $this->aiUserSettingsService->deleteSettings($this->getUser(), $id);
        
        if (!$result) {
            return $this->json(['error' => 'Settings not found'], Response::HTTP_NOT_FOUND);
        }
        
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
