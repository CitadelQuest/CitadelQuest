<?php

namespace App\Controller;

use App\Service\CqContactService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class CqContactController extends AbstractController
{
    public function __construct(
        private readonly CqContactService $cqContactService
    ) {
    }

    #[Route('/cq-contacts', name: 'app_cq_contact_index')]
    public function index(): Response
    {
        return $this->render('cq_contact/index.html.twig', [
            'page_title' => 'CitadelQuest Contacts'
        ]);
    }

    #[Route('/cq-contact', name: 'app_cq_contact_index_old')]
    public function indexOldRedirect(): Response
    {
        return $this->redirectToRoute('app_cq_contact_index', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/cq-contact/{id}', name: 'app_cq_contact_detail')]
    public function detail(string $id): Response
    {
        $contact = $this->cqContactService->findById($id);
        if (!$contact) {
            throw $this->createNotFoundException('Contact not found');
        }

        // Redirect to Explorer with contact URL
        return $this->redirect(
            $this->generateUrl('app_cq_contact_index') . '?url=' . urlencode($contact->getCqContactUrl()),
            Response::HTTP_MOVED_PERMANENTLY
        );
    }
}
