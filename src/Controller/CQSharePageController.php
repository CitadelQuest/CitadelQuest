<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/share')]
#[IsGranted('ROLE_USER')]
class CQSharePageController extends AbstractController
{
    #[Route('', name: 'app_cq_share_index')]
    public function index(): Response
    {
        return $this->render('cq_share/index.html.twig');
    }
}
