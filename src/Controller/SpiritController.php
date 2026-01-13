<?php

namespace App\Controller;

use App\Service\SpiritService;
use App\Service\AiServiceModelService;
use App\Service\AiGatewayService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/spirit')]
#[IsGranted('ROLE_USER')]
class SpiritController extends AbstractController
{
    public function __construct(
        private readonly SpiritService $spiritService,
        private readonly AiServiceModelService $aiServiceModelService,
        private readonly AiGatewayService $aiGatewayService
    ) {}

    #[Route('', name: 'spirit_index')]
    public function index(): Response
    {
        // Get CQ AI Gateway
        $gateway = $this->aiGatewayService->findByName('CQ AI Gateway');
        
        if (!$gateway) {
            return $this->render('spirit/index.html.twig', [
                'spiritId' => null,
                'aiModels' => []
            ]);
        }
        
        // Get all active AI models for the gateway
        $aiModels = $this->aiServiceModelService->findByGateway($gateway->getId(), true);
        
        // Get image capable models to exclude them
        $imageModels = $this->aiServiceModelService->findImageOutputModelsByGateway($gateway->getId(), true);
        $imageModelIds = array_map(fn($model) => $model->getId(), $imageModels);
        
        // Filter to only include text models (exclude image models)
        $textModels = array_filter($aiModels, function($model) use ($imageModelIds) {
            return !in_array($model->getId(), $imageModelIds);
        });
        
        return $this->render('spirit/index.html.twig', [
            'spiritId' => $this->spiritService->getUserSpirit()->getId(),
            'aiModels' => $textModels
        ]);
    }

    #[Route('/{id}', name: 'spirit_show', methods: ['GET'])]
    public function show(string $id): Response
    {
        // Get CQ AI Gateway
        $gateway = $this->aiGatewayService->findByName('CQ AI Gateway');
        
        if (!$gateway) {
            return $this->render('spirit/index.html.twig', [
                'spiritId' => $id,
                'aiModels' => []
            ]);
        }
        
        // Get all active AI models for the gateway
        $aiModels = $this->aiServiceModelService->findByGateway($gateway->getId(), true);
        
        // Get image capable models to exclude them
        $imageModels = $this->aiServiceModelService->findImageOutputModelsByGateway($gateway->getId(), true);
        $imageModelIds = array_map(fn($model) => $model->getId(), $imageModels);
        
        // Filter to only include text models (exclude image models)
        $textModels = array_filter($aiModels, function($model) use ($imageModelIds) {
            return !in_array($model->getId(), $imageModelIds);
        });
        
        return $this->render('spirit/index.html.twig', [
            'spiritId' => $id,
            'aiModels' => $textModels
        ]);
    }
}
