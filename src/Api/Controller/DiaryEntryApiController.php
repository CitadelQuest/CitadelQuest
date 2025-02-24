<?php

namespace App\Api\Controller;

use App\Service\DiaryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/diary')]
#[IsGranted('ROLE_USER')]
class DiaryEntryApiController extends AbstractController
{
    public function __construct(
        private readonly DiaryService $diaryService
    ) {
    }

    #[Route('', name: 'app_api_diary_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $limit = $request->query->get('limit', 10);
        $tag = $request->query->get('tag');

        $entries = match(true) {
            $tag !== null => $this->diaryService->findByTag($this->getUser(), $tag),
            $request->query->has('favorites') => $this->diaryService->findFavorites($this->getUser()),
            default => $this->diaryService->findLatestEntries($this->getUser(), $limit),
        };

        return $this->json([
            'entries' => array_map(fn($entry) => $entry->jsonSerialize(), $entries)
        ]);
    }

    #[Route('', name: 'app_api_diary_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['title']) || !isset($data['content'])) {
            return $this->json(['error' => 'Missing required fields'], Response::HTTP_BAD_REQUEST);
        }

        $entry = $this->diaryService->createEntry(
            $this->getUser(),
            $data['title'],
            $data['content'],
            $data['mood'] ?? null,
            $data['tags'] ?? null
        );

        return $this->json([
            'entry' => $entry->jsonSerialize()
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}/favorite', name: 'app_api_diary_toggle_favorite', methods: ['POST'])]
    public function toggleFavorite(string $id): JsonResponse
    {
        $entry = $this->diaryService->toggleFavorite($this->getUser(), $id);
        
        return $this->json([
            'entry' => $entry->jsonSerialize()
        ]);
    }

    #[Route('/{id}', name: 'app_api_diary_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (empty($data)) {
            return $this->json(['error' => 'No update data provided'], Response::HTTP_BAD_REQUEST);
        }

        $entry = $this->diaryService->updateEntry(
            $this->getUser(),
            $id,
            $data
        );

        return $this->json([
            'entry' => $entry->jsonSerialize()
        ]);
    }

    #[Route('/{id}', name: 'app_api_diary_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $this->diaryService->deleteEntry($this->getUser(), $id);
        
        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
