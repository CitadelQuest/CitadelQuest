<?php

namespace App\Api\Controller;

use App\Service\SpiritService;
use App\Service\SpiritSkillService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/spirit/{id}/skills')]
#[IsGranted('ROLE_USER')]
class SpiritSkillApiController extends AbstractController
{
    public function __construct(
        private readonly SpiritService $spiritService,
        private readonly SpiritSkillService $spiritSkillService,
    ) {
    }

    /**
     * List active + available skills for a Spirit
     */
    #[Route('', name: 'api_spirit_skills_list', methods: ['GET'])]
    public function list(string $id): JsonResponse
    {
        try {
            $spirit = $this->spiritService->getSpirit($id);
            if (!$spirit) {
                return $this->json(['error' => 'Spirit not found'], Response::HTTP_NOT_FOUND);
            }

            return $this->json($this->spiritSkillService->listSkills($spirit));
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Get a single skill file's content
     */
    #[Route('/content', name: 'api_spirit_skills_content', methods: ['GET'])]
    public function content(string $id, Request $request): JsonResponse
    {
        try {
            $spirit = $this->spiritService->getSpirit($id);
            if (!$spirit) {
                return $this->json(['error' => 'Spirit not found'], Response::HTTP_NOT_FOUND);
            }

            $fileId = $request->query->get('fileId');
            if (!$fileId) {
                return $this->json(['error' => 'Missing fileId'], Response::HTTP_BAD_REQUEST);
            }

            return $this->json(['content' => $this->spiritSkillService->getSkillContent($fileId)]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Create a new skill
     */
    #[Route('', name: 'api_spirit_skills_create', methods: ['POST'])]
    public function create(string $id, Request $request): JsonResponse
    {
        try {
            $spirit = $this->spiritService->getSpirit($id);
            if (!$spirit) {
                return $this->json(['error' => 'Spirit not found'], Response::HTTP_NOT_FOUND);
            }

            $data = json_decode($request->getContent(), true) ?? [];
            $name = trim($data['name'] ?? '');
            if ($name === '') {
                return $this->json(['error' => 'Missing skill name'], Response::HTTP_BAD_REQUEST);
            }
            $content = $data['content'] ?? '';
            $state = $data['state'] ?? SpiritSkillService::STATE_AVAILABLE;

            $file = $this->spiritSkillService->createSkill($spirit, $name, $content, $state);

            return $this->json(['success' => true, 'id' => $file->getId(), 'name' => $file->getName()], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Update a skill file's content
     */
    #[Route('/content', name: 'api_spirit_skills_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        try {
            $spirit = $this->spiritService->getSpirit($id);
            if (!$spirit) {
                return $this->json(['error' => 'Spirit not found'], Response::HTTP_NOT_FOUND);
            }

            $data = json_decode($request->getContent(), true) ?? [];
            $fileId = $data['fileId'] ?? null;
            if (!$fileId) {
                return $this->json(['error' => 'Missing fileId'], Response::HTTP_BAD_REQUEST);
            }
            $content = $data['content'] ?? '';

            $this->spiritSkillService->updateSkillContent($fileId, $content);

            return $this->json(['success' => true]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Move a skill between active/available
     */
    #[Route('/state', name: 'api_spirit_skills_state', methods: ['POST'])]
    public function state(string $id, Request $request): JsonResponse
    {
        try {
            $spirit = $this->spiritService->getSpirit($id);
            if (!$spirit) {
                return $this->json(['error' => 'Spirit not found'], Response::HTTP_NOT_FOUND);
            }

            $data = json_decode($request->getContent(), true) ?? [];
            $fileId = $data['fileId'] ?? null;
            $state = $data['state'] ?? null;
            if (!$fileId || !$state) {
                return $this->json(['error' => 'Missing fileId or state'], Response::HTTP_BAD_REQUEST);
            }

            $this->spiritSkillService->setSkillState($spirit, $fileId, $state);

            return $this->json(['success' => true]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Delete a skill
     */
    #[Route('', name: 'api_spirit_skills_delete', methods: ['DELETE'])]
    public function delete(string $id, Request $request): JsonResponse
    {
        try {
            $spirit = $this->spiritService->getSpirit($id);
            if (!$spirit) {
                return $this->json(['error' => 'Spirit not found'], Response::HTTP_NOT_FOUND);
            }

            $fileId = $request->query->get('fileId') ?? (json_decode($request->getContent(), true)['fileId'] ?? null);
            if (!$fileId) {
                return $this->json(['error' => 'Missing fileId'], Response::HTTP_BAD_REQUEST);
            }

            $this->spiritSkillService->deleteSkill($fileId);

            return $this->json(['success' => true]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
