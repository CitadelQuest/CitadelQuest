<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Controller for development preview functionality
 * This allows the browser preview tool to access the application without manual login
 */
class DevPreviewController extends AbstractController
{
    #[Route('/dev-preview', name: 'app_dev_preview')]
    public function preview(
        TokenStorageInterface $tokenStorage,
        EntityManagerInterface $entityManager
    ): Response {
        // Only enable in dev environment
        if ($_SERVER['APP_ENV'] !== 'dev') {
            throw $this->createAccessDeniedException('This endpoint is only available in the dev environment');
        }
        
        // Find the cascade user
        $user = $entityManager->getRepository(User::class)->findOneBy(['username' => 'cascade']);
        
        if (!$user) {
            return $this->render('dev_preview/error.html.twig', [
                'message' => 'Cascade user not found. Please create a user with username "cascade" first.'
            ]);
        }
        
        // Create authentication token and set it in the token storage
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $tokenStorage->setToken($token);
        
        // Redirect to homepage
        return $this->redirectToRoute('app_home');
    }
}
