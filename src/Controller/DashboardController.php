<?php

namespace App\Controller;

use App\Service\StorageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly StorageService $storageService
    ) {
    }

    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(): Response
    {
        $user = $this->getUser();
        
        // Get storage information
        $storageInfo = $this->storageService->getTotalUserStorageSize($user);
        
        return $this->render('dashboard/index.html.twig', [
            'user' => $user,
            'storageInfo' => $storageInfo,
        ]);
    }
}
