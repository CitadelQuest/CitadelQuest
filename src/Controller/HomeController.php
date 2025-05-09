<?php

namespace App\Controller;

use App\Service\SettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class HomeController extends AbstractController
{
    public function __construct(
        private readonly SettingsService $settingsService
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
}
