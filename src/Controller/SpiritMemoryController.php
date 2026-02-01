<?php

namespace App\Controller;

use App\Service\SpiritService;
use App\Service\SpiritMemoryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/spirit')]
#[IsGranted('ROLE_USER')]
class SpiritMemoryController extends AbstractController
{
    public function __construct(
        private readonly SpiritService $spiritService,
        private readonly SpiritMemoryService $spiritMemoryService
    ) {}

    #[Route('/{spiritId}/memory', name: 'spirit_memory_explorer', methods: ['GET'])]
    public function memoryExplorer(string $spiritId): Response
    {
        $spirit = $this->spiritService->findById($spiritId);
        
        if (!$spirit) {
            throw $this->createNotFoundException('Spirit not found');
        }

        return $this->render('spirit/memory.html.twig', [
            'spirit' => $spirit,
            'spiritId' => $spiritId
        ]);
    }

    #[Route('/{spiritId}/memory/graph', name: 'spirit_memory_graph_api', methods: ['GET'])]
    public function getGraphData(string $spiritId): JsonResponse
    {
        $spirit = $this->spiritService->findById($spiritId);
        
        if (!$spirit) {
            return new JsonResponse(['error' => 'Spirit not found'], 404);
        }

        $graphData = $this->spiritMemoryService->getGraphData($spiritId);

        return new JsonResponse($graphData);
    }

    #[Route('/{spiritId}/memory/stats', name: 'spirit_memory_stats_api', methods: ['GET'])]
    public function getStats(string $spiritId): JsonResponse
    {
        $spirit = $this->spiritService->findById($spiritId);
        
        if (!$spirit) {
            return new JsonResponse(['error' => 'Spirit not found'], 404);
        }

        $stats = $this->spiritMemoryService->getStats($spiritId);

        return new JsonResponse($stats);
    }
}
