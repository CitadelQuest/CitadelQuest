<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Controller for development preview functionality
 * This allows the browser preview tool to access the application without manual login
 * Only for `cascade` AI bro
 */
class DevPreviewController extends AbstractController
{
    #[Route('/dev-preview', name: 'app_dev_preview', methods: ['GET'])]
    public function preview(
        TokenStorageInterface $tokenStorage,
        EntityManagerInterface $entityManager,
        Request $request,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        // Only enable in dev environment
        if ($_SERVER['APP_ENV'] !== 'dev') {
            throw $this->createAccessDeniedException('This endpoint is only available in the dev environment');
        }

        // Check the username & password from URL parameters
        $username = $request->query->get('username');
        $password = $request->query->get('password');
        
        // Validate the username & password
        if (!$username || !$password) {
            return $this->render('dev_preview/error.html.twig', [
                'message' => 'Missing username or password'
            ]);
        }

        // Validate the username & password + only for `cascade` AI bro
        $user = $entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
        if (!$user || $username !== 'cascade') {
            return $this->render('dev_preview/error.html.twig', [
                'message' => 'User not found'
            ]);
        }
        
        // Validate the password
        if (!$passwordHasher->isPasswordValid($user, $password)) {
            return $this->render('dev_preview/error.html.twig', [
                'message' => 'Invalid password'
            ]);
        }
        
        // Create authentication token and set it in the token storage
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $tokenStorage->setToken($token);
        
        // Redirect to homepage
        return $this->redirectToRoute('app_home');
    }
}
