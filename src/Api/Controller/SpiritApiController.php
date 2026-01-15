<?php

namespace App\Api\Controller;

use App\Service\SpiritService;
use App\Service\SpiritConversationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/spirit')]
#[IsGranted('ROLE_USER')]
class SpiritApiController extends AbstractController
{
    public function __construct(
        private readonly SpiritService $spiritService,
        private readonly SpiritConversationService $spiritConversationService
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
            $spiritData['progression'] = $this->spiritService->getLevelProgression($spirit->getId());

            return $this->json($spiritData);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/list', name: 'app_api_spirits_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        try {
            $spirits = $this->spiritService->findAll();

            $spiritsData = [];
            foreach ($spirits as $spirit) {
                $settings = $this->spiritService->getSpiritSettings($spirit->getId());
                $progression = $this->spiritService->getLevelProgression($spirit->getId());

                $spiritData = $spirit->jsonSerialize();
                $spiritData['settings'] = $settings;
                $spiritData['progression'] = $progression;
                $spiritData['isPrimary'] = $this->spiritService->isPrimarySpirit($spirit->getId());

                $spiritsData[] = $spiritData;
            }

            return $this->json(['spirits' => $spiritsData]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('', name: 'app_api_spirit_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $name = $data['name'] ?? 'Spirit';
        $color = $data['color'] ?? null;

        try {
            $spirit = $this->spiritService->createSpirit($name, $color);

            // Get spirit settings and include them in the response
            $settings = $this->spiritService->getSpiritSettings($spirit->getId());

            $spiritData = $spirit->jsonSerialize();
            $spiritData['settings'] = $settings;

            return $this->json($spiritData, Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/interactions', name: 'app_api_spirit_interactions', methods: ['GET'])]
    public function interactions(Request $request): JsonResponse
    {
        $spiritId = $request->query->get('spiritId');
        $spirit = $spiritId ? $this->spiritService->getSpirit($spiritId) : $this->spiritService->getUserSpirit();
        
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
            $data = json_decode($request->getContent(), true);
            $spiritId = $data['spiritId'] ?? null;
            $spirit = $spiritId ? $this->spiritService->getSpirit($spiritId) : $this->spiritService->getUserSpirit();
            
            if (!$spirit) {
                return $this->json(['error' => 'No spirit found'], Response::HTTP_NOT_FOUND);
            }
            
            // Update the spirit's system prompt and AI model via settings
            if (isset($data['systemPrompt'])) {
                $this->spiritService->setSpiritSetting($spirit->getId(), 'systemPrompt', $data['systemPrompt']);
            }
            
            if (isset($data['aiModel'])) {
                $this->spiritService->setSpiritSetting($spirit->getId(), 'aiModel', $data['aiModel']);
            }
            
            // Get the updated spirit
            $updatedSpirit = $this->spiritService->getSpirit($spirit->getId());
            
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

    #[Route('/{id}', name: 'app_api_spirit_get_by_id', methods: ['GET'])]
    public function getById(string $id): JsonResponse
    {
        try {
            $spirit = $this->spiritService->getSpirit($id);

            if (!$spirit) {
                return $this->json(['error' => 'Spirit not found'], Response::HTTP_NOT_FOUND);
            }

            // Get spirit settings and include them in the response
            $settings = $this->spiritService->getSpiritSettings($spirit->getId());

            $spiritData = $spirit->jsonSerialize();
            $spiritData['settings'] = $settings;
            $spiritData['progression'] = $this->spiritService->getLevelProgression($spirit->getId());

            return $this->json($spiritData);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}', name: 'app_api_spirit_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        try {
            $this->spiritService->deleteSpirit($id);

            return $this->json(['success' => true, 'message' => 'Spirit deleted successfully']);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Get Spirit system prompt preview for the System Prompt Builder
     * Returns the complete system prompt structure with current config
     */
    #[Route('/{id}/system-prompt-preview', name: 'api_spirit_system_prompt_preview', methods: ['GET'])]
    public function getSystemPromptPreview(string $id, Request $request, TranslatorInterface $translator): JsonResponse
    {
        try {
            $spirit = $this->spiritService->getSpirit($id);
            
            if (!$spirit) {
                return $this->json(['error' => 'Spirit not found'], Response::HTTP_NOT_FOUND);
            }
            
            // Get language from request or default to English
            // Get locale for language
            $locale = $request->getSession()->get('_locale') ?? 
                      $request->getSession()->get('citadel_locale') ?? 'en';
            $lang = $translator->trans('navigation.language.' . $locale) . ' (' . $locale . ')';

            // Get the system prompt preview data
            $previewData = $this->spiritConversationService->getSystemPromptPreview($spirit, $lang);
            
            return $this->json($previewData);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Update Spirit system prompt configuration
     * Updates which optional sections are included in the system prompt
     */
    #[Route('/{id}/system-prompt-config', name: 'api_spirit_system_prompt_config', methods: ['PUT'])]
    public function updateSystemPromptConfig(string $id, Request $request): JsonResponse
    {
        try {
            $spirit = $this->spiritService->getSpirit($id);
            
            if (!$spirit) {
                return $this->json(['error' => 'Spirit not found'], Response::HTTP_NOT_FOUND);
            }
            
            $data = json_decode($request->getContent(), true);
            
            // Update the prompt configuration
            $this->spiritConversationService->updatePromptConfig($id, $data);
            
            // Return the updated config
            $config = $this->spiritConversationService->getPromptConfig($id);
            
            return $this->json([
                'success' => true,
                'config' => $config
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
