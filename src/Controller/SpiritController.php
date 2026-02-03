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
        private readonly AiGatewayService $aiGatewayService,
        private readonly \App\Service\SpiritConversationService $spiritConversationService
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
                'aiModels' => [],
                'allSpirits' => []
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
        
        // Get all spirits for the spirits list section
        $spirits = $this->spiritService->findAll();
        $allSpirits = [];
        foreach ($spirits as $spirit) {
            $settings = $this->spiritService->getSpiritSettings($spirit->getId());
            
            // Extract spirit color from visualState
            $spiritColor = '#95ec86'; // default
            if (isset($settings['visualState'])) {
                $visualState = json_decode($settings['visualState'], true);
                if (isset($visualState['color'])) {
                    $spiritColor = $visualState['color'];
                }
            }
            
            $spiritData = [
                'id' => $spirit->getId(),
                'name' => $spirit->getName(),
                'isPrimary' => $this->spiritService->isPrimarySpirit($spirit->getId()),
                'progression' => $this->spiritService->getLevelProgression($spirit->getId()),
                'settings' => $settings,
                'color' => $spiritColor
            ];
            $allSpirits[] = $spiritData;
        }
        
        // Get interactions for this spirit
        $interactions = $this->spiritService->getRecentInteractions($id, 10);
        $interactionsData = [];
        foreach ($interactions as $interaction) {
            $interactionsData[] = [
                'type' => $interaction->getInteractionType(),
                'context' => $interaction->getContext(),
                'experienceGained' => $interaction->getExperienceGained(),
                'createdAt' => $interaction->getCreatedAt()->format('Y-m-d H:i:s')
            ];
        }
        
        // Get conversations for this spirit (returns array of arrays)
        $conversationsData = $this->spiritConversationService->getConversationsBySpirit($id);
        
        return $this->render('spirit/index.html.twig', [
            'spiritId' => $id,
            'aiModels' => $textModels,
            'allSpirits' => $allSpirits,
            'interactions' => $interactionsData,
            'conversations' => $conversationsData
        ]);
    }
}
