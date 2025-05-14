<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils, EntityManagerInterface $entityManager): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        // if no users in db -> redirect to registration
        $users = $entityManager->getRepository(User::class)->findAll();
        if (count($users) === 0) {
            return $this->redirectToRoute('app_register');
        }

        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        // This method can be empty - it will be intercepted by the logout key on your firewall
    }
}
