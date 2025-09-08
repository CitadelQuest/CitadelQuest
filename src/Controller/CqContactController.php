<?php

namespace App\Controller;

use App\Service\CqContactService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/cq-contact')]
#[IsGranted('ROLE_USER')]
class CqContactController extends AbstractController
{
    public function __construct(
        private readonly CqContactService $cqContactService
    ) {
    }

    #[Route('', name: 'app_cq_contact_index')]
    public function index(): Response
    {
        return $this->render('cq_contact/index.html.twig', [
            'page_title' => 'CitadelQuest Contacts'
        ]);
    }
}
