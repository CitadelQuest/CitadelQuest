<?php

namespace App\Api\Controller;

use App\Service\SettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/settings')]
#[IsGranted('ROLE_USER')]
class SettingsApiController extends AbstractController
{
    public function __construct(
        private readonly SettingsService $settingsService
    ) {
    }

    #[Route('', name: 'api_settings_get_all', methods: ['GET'])]
    public function getAllSettings(): JsonResponse
    {
        try {
            $settings = $this->settingsService->getAllSettings();
            
            $result = [];
            foreach ($settings as $setting) {
                $result[] = $setting->jsonSerialize();
            }
        
            return new JsonResponse($result);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{key}', name: 'api_settings_get', methods: ['GET'])]
    public function getSetting(string $key): JsonResponse
    {
        try {
            $setting = $this->settingsService->getSetting($key);
            
            if (!$setting) {
                return new JsonResponse(['error' => 'Setting not found'], Response::HTTP_NOT_FOUND);
            }
            
            return new JsonResponse($setting->jsonSerialize());
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{key}', name: 'api_settings_set', methods: ['POST'])]
    public function setSetting(Request $request, string $key): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $value = $data['value'] ?? null;
            
            $setting = $this->settingsService->setSetting($key, $value);
            
            return new JsonResponse($setting->jsonSerialize());
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{key}', name: 'api_settings_delete', methods: ['DELETE'])]
    public function deleteSetting(string $key): JsonResponse
    {
        try {
            $success = $this->settingsService->deleteSetting($key);
            
            if (!$success) {
                return new JsonResponse(['error' => 'Setting not found'], Response::HTTP_NOT_FOUND);
            }
        
            return new JsonResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
