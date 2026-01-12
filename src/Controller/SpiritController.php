<?php

namespace App\Controller;

use App\Service\SpiritService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/spirit')]
#[IsGranted('ROLE_USER')]
class SpiritController extends AbstractController
{
    public function __construct(
        private readonly SpiritService $spiritService
    ) {}

    #[Route('', name: 'spirit_index')]
    public function index(): Response
    {
        return $this->render('spirit/index.html.twig');
    }

    #[Route('/{id}', name: 'spirit_show', methods: ['GET'])]
    public function show(string $id): Response
    {
        return $this->render('spirit/index.html.twig', ['spiritId' => $id]);
    }
}
