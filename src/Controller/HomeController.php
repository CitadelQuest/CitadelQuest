<?php

namespace App\Controller;

use App\Service\SettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Service\SpiritConversationService;
use App\Service\SpiritService;
use App\Service\AiGatewayService;

class HomeController extends AbstractController
{
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly SpiritConversationService $spiritConversationService,
        private readonly SpiritService $spiritService,
        private readonly AiGatewayService $aiGatewayService
    ) {}
    
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        // If user is logged in and hasn't completed onboarding, redirect to welcome page
        if ($this->getUser() && !$this->settingsService->getSettingValue('onboarding.completed', false)) {
            return $this->redirectToRoute('app_welcome_onboarding');
        }
        
        return $this->render('home/index.html.twig', [
            'controller_name' => 'HomeController',
        ]);
    }

    #[Route('/test-system-prompt', name: 'app_test_system_prompt')]
    public function testSystemPrompt(): Response
    {
        $spirit = $this->spiritService->getUserSpirit();

        $response = $this->spiritConversationService->prepareMessagesForAiRequest([], $spirit, 'en');

        $tools = $this->aiGatewayService->getAvailableTools($this->aiGatewayService->getPrimaryAiServiceModel()->getAiGatewayId());
        
        return new Response(json_encode($tools));
    }

    #[Route('/test-prompt-filter', name: 'app_test_prompt_filter')]
    public function testPromptFilter(): Response
    {
        $response = $this->spiritConversationService->findById('8b9b3e10-3ca4-4265-9c4b-e865d6ee6f0f');

        $messages = $response->getMessages();

        $messages = $this->aiGatewayService->filterInjectedSystemData($messages);
        
        return new Response(json_encode($messages));
    }
}
