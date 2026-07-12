<?php

namespace App\Controller;

use App\Service\SpiritService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/spirits')]
#[IsGranted('ROLE_USER')]
class SpiritsController extends AbstractController
{
    public function __construct(
        private readonly SpiritService $spiritService
    ) {}

    #[Route('', name: 'spirits_index')]
    public function index(): Response
    {
        $spirits = $this->spiritService->findAll();
        $allSpirits = [];
        foreach ($spirits as $spirit) {
            $settings = $this->spiritService->getSpiritSettings($spirit->getId());

            $allSpirits[] = [
                'id' => $spirit->getId(),
                'name' => $spirit->getName(),
                'isPrimary' => $this->spiritService->isPrimarySpirit($spirit->getId()),
                'progression' => $this->spiritService->getLevelProgression($spirit->getId()),
                'settings' => $settings,
                'color' => $this->spiritService->getSpiritColor($spirit->getId())
            ];
        }

        return $this->render('spirits/index.html.twig', [
            'allSpirits' => $allSpirits
        ]);
    }
}
