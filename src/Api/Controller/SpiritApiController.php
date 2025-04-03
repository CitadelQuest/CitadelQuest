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
        $spirit = $this->spiritService->getUserSpirit();
        
        if (!$spirit) {
            return $this->json(['error' => 'No spirit found'], Response::HTTP_NOT_FOUND);
        }
        
        return $this->json($spirit);
    }

    #[Route('', name: 'app_api_spirit_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $name = $data['name'] ?? 'Spirit';
        
        try {
            $spirit = $this->spiritService->createSpirit($name);
            return $this->json($spirit, Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/interact', name: 'app_api_spirit_interact', methods: ['POST'])]
    public function interact(Request $request): JsonResponse
    {
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

    #[Route('/abilities', name: 'app_api_spirit_abilities', methods: ['GET'])]
    public function abilities(): JsonResponse
    {
        $spirit = $this->spiritService->getUserSpirit();
        
        if (!$spirit) {
            return $this->json(['error' => 'No spirit found'], Response::HTTP_NOT_FOUND);
        }
        
        $abilities = $this->spiritService->getSpiritAbilities($spirit->getId());
        
        return $this->json($abilities);
    }

    #[Route('/abilities/{id}/unlock', name: 'app_api_spirit_ability_unlock', methods: ['POST'])]
    public function unlockAbility(string $id): JsonResponse
    {
        $ability = $this->spiritService->unlockAbility($id);
        
        if (!$ability) {
            return $this->json(['error' => 'Ability not found'], Response::HTTP_NOT_FOUND);
        }
        
        return $this->json($ability);
    }
    
    #[Route('/update', name: 'app_api_spirit_update', methods: ['POST'])]
    public function update(Request $request): JsonResponse
    {
        $spirit = $this->spiritService->getUserSpirit();
        
        if (!$spirit) {
            return $this->json(['error' => 'No spirit found'], Response::HTTP_NOT_FOUND);
        }
        
        $data = json_decode($request->getContent(), true);
        
        // Update the spirit's system prompt and AI model
        if (isset($data['systemPrompt'])) {
            $spirit->setSystemPrompt($data['systemPrompt']);
        }
        
        if (isset($data['aiModel'])) {
            $spirit->setAiModel($data['aiModel']);
        }
        
        // Save the updated spirit
        $this->spiritService->updateSpirit($spirit);
        
        return $this->json($spirit);
    }
}
