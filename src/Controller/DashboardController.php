<?php

namespace App\Controller;

use App\Service\StorageService;
use App\Service\SettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly StorageService $storageService,
        private readonly SettingsService $settingsService
    ) {
    }


    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(): Response
    {
        $user = $this->getUser();
        
        // Get storage information
        $storageInfo = $this->storageService->getTotalUserStorageSize($user);

        $CQ_AI_GatewayUsername = $this->settingsService->getSettingValue('cqaigateway.username');
        if ($CQ_AI_GatewayUsername === null) {
            $CQ_AI_GatewayUsername = '[cqaigateway.username not set]';
        }

        // CQ AI Gateway Credits
        $credits = $this->settingsService->getSettingValue('cqaigateway.credits', 0);        
        
        return $this->render('dashboard/index.html.twig', [
            'user' => $user,
            'storageInfo' => $storageInfo,
            'CQ_AI_GatewayUsername' => $CQ_AI_GatewayUsername,
            'CQ_AI_GatewayCredits' => $credits,
        ]);
    }
}
