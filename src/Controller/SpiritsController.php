<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/spirits')]
#[IsGranted('ROLE_USER')]
class SpiritsController extends AbstractController
{
    #[Route('', name: 'spirits_index')]
    public function index(): Response
    {
        return $this->render('spirits/index.html.twig');
    }
}
