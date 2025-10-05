<?php

namespace App\Controller;

use App\Service\CqChatService;
use App\Service\CqContactService;
use App\Service\SettingsService;
use App\Service\CqChatMsgService;
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
        private readonly CqContactService $cqContactService,
        private readonly SettingsService $settingsService,
        private readonly CqChatMsgService $cqChatMsgService
    ) {
    }

    #[Route('', name: 'app_cq_chat_index')]
    public function index(): Response
    {
        return $this->render('cq_chat/index.html.twig', [
            'page_title' => 'CitadelQuest Chat'
        ]);
    }

    // Old routes removed - now using modal-based chat system
    // All chat interactions happen through the CQ Chat modal
}
