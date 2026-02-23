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

    #[Route('/{id}', name: 'app_cq_contact_detail')]
    public function detail(string $id): Response
    {
        $contact = $this->cqContactService->findById($id);
        if (!$contact) {
            throw $this->createNotFoundException('Contact not found');
        }

        // Mask API key before passing to template (serialized to JSON in frontend)
        $contact->setCqContactApiKey('***');

        return $this->render('cq_contact/detail.html.twig', [
            'contact' => $contact,
        ]);
    }
}
