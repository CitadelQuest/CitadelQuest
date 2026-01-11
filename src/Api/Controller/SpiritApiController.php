<?php

namespace App\Api\Controller;

use App\Service\SpiritService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/spirit')]
#[IsGranted('ROLE_USER')]
class SpiritApiController extends AbstractController
{
    public function __construct(
        private readonly SpiritService $spiritService
    ) {
    }

    #[Route('', name: 'app_api_spirit_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        try {
            $spirit = $this->spiritService->getUserSpirit();

            if (!$spirit) {
                return $this->json(['error' => 'No spirit found'], Response::HTTP_NOT_FOUND);
            }

            // Get spirit settings and include them in the response
            $settings = $this->spiritService->getSpiritSettings($spirit->getId());

            $spiritData = $spirit->jsonSerialize();
            $spiritData['settings'] = $settings;

            return $this->json($spiritData);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('', name: 'app_api_spirit_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $name = $data['name'] ?? 'Spirit';

        try {
            $spirit = $this->spiritService->createSpirit($name);

            // Get spirit settings and include them in the response
            $settings = $this->spiritService->getSpiritSettings($spirit->getId());

            $spiritData = $spirit->jsonSerialize();
            $spiritData['settings'] = $settings;

            return $this->json($spiritData, Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/interact', name: 'app_api_spirit_interact', methods: ['POST'])]
    public function interact(Request $request): JsonResponse
    {
        try {
            $spirit = $this->spiritService->getUserSpirit();
        
            if (!$spirit) {
                return $this->json(['error' => 'No spirit found'], Response::HTTP_NOT_FOUND);
            }
            
            $data = json_decode($request->getContent(), true);
            $interactionType = $data['interactionType'] ?? 'general';
            $context = $data['context'] ?? null;
            $experienceGained = $data['experienceGained'] ?? 5; // Default experience gain
            
            $interaction = $this->spiritService->logInteraction(
                $spirit->getId(),
                $interactionType,
                $experienceGained,
                $context
            );
            
            // Get the updated spirit
            $updatedSpirit = $this->spiritService->getUserSpirit();
            
            return $this->json([
                'spirit' => $updatedSpirit,
                'interaction' => $interaction
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/interactions', name: 'app_api_spirit_interactions', methods: ['GET'])]
    public function interactions(): JsonResponse
    {
        $spirit = $this->spiritService->getUserSpirit();
        
        if (!$spirit) {
            return $this->json(['error' => 'No spirit found'], Response::HTTP_NOT_FOUND);
        }
        
        $interactions = $this->spiritService->getRecentInteractions($spirit->getId());
        
        return $this->json($interactions);
    }

    #[Route('/update', name: 'app_api_spirit_update', methods: ['POST'])]
    public function update(Request $request): JsonResponse
    {
        try {
            $spirit = $this->spiritService->getUserSpirit();
            
            if (!$spirit) {
                return $this->json(['error' => 'No spirit found'], Response::HTTP_NOT_FOUND);
            }
            
            $data = json_decode($request->getContent(), true);
            
            // Update the spirit's system prompt and AI model via settings
            if (isset($data['systemPrompt'])) {
                $this->spiritService->setSpiritSetting($spirit->getId(), 'systemPrompt', $data['systemPrompt']);
            }
            
            if (isset($data['aiModel'])) {
                $this->spiritService->setSpiritSetting($spirit->getId(), 'aiModel', $data['aiModel']);
            }
            
            // Get the updated spirit
            $updatedSpirit = $this->spiritService->getUserSpirit();
            
            return $this->json($updatedSpirit);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}/settings', name: 'app_api_spirit_settings', methods: ['GET'])]
    public function getSettings(string $id): JsonResponse
    {
        try {
            $spirit = $this->spiritService->getSpirit($id);
            
            if (!$spirit) {
                return $this->json(['error' => 'No spirit found'], Response::HTTP_NOT_FOUND);
            }
            
            $settings = $this->spiritService->getSpiritSettings($id);
            
            return $this->json($settings);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}/settings', name: 'app_api_spirit_settings_update', methods: ['POST'])]
    public function updateSettings(string $id, Request $request): JsonResponse
    {
        try {
            $spirit = $this->spiritService->getSpirit($id);
            
            if (!$spirit) {
                return $this->json(['error' => 'No spirit found'], Response::HTTP_NOT_FOUND);
            }
            
            $data = json_decode($request->getContent(), true);
            
            // Update settings
            foreach ($data as $key => $value) {
                $this->spiritService->setSpiritSetting($id, $key, $value);
            }
            
            // Get the updated settings
            $settings = $this->spiritService->getSpiritSettings($id);
            
            return $this->json($settings);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
