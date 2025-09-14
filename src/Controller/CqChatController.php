<?php

namespace App\Controller;

use App\Service\CqChatService;
use App\Service\CqContactService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/cq-chat')]
#[IsGranted('ROLE_USER')]
class CqChatController extends AbstractController
{
    public function __construct(
        private readonly CqChatService $cqChatService,
        private readonly CqContactService $cqContactService
    ) {
    }

    #[Route('', name: 'app_cq_chat_index')]
    public function index(): Response
    {
        return $this->render('cq_chat/index.html.twig', [
            'page_title' => 'CitadelQuest Chat'
        ]);
    }

    #[Route('/contact/{contactId}', name: 'app_cq_chat_contact')]
    public function contactChat(string $contactId): Response
    {
        // Find the contact
        $contact = $this->cqContactService->findById($contactId);
        if (!$contact) {
            $this->addFlash('error', 'Contact not found');
            return $this->redirectToRoute('app_cq_chat_index');
        }
        
        // Check if a chat already exists with this contact
        $existingChats = $this->cqChatService->findByContactId($contactId);
        
        if (!empty($existingChats)) {
            // Use the first active chat
            $chat = $existingChats[0];
        } else {
            // Create a new chat with the contact
            $title = $contact->getCqContactUsername() . '@' . $contact->getCqContactDomain();
            $chat = $this->cqChatService->createChat(
                $contactId,
                $title,
                'Chat with ' . $title,
                false,  // isStar
                false,  // isPin
                false,  // isMute
                true    // isActive
            );
        }
        
        // Redirect to the chat view
        return $this->redirectToRoute('app_cq_chat_view', ['id' => $chat->getId()]);
    }
    
    #[Route('/{id}', name: 'app_cq_chat_view')]
    public function view(string $id): Response
    {
        $chat = $this->cqChatService->findById($id);
        
        if (!$chat) {
            $this->addFlash('error', 'Chat not found');
            return $this->redirectToRoute('app_cq_chat_index');
        }
        
        // Get contact info if available
        $contact = null;
        if ($chat->getCqContactId()) {
            $contact = $this->cqContactService->findById($chat->getCqContactId());
        }
        
        return $this->render('cq_chat/view.html.twig', [
            'chat' => $chat,
            'contact' => $contact,
            'page_title' => $chat->getTitle()
        ]);
    }
}
