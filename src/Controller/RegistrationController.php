<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationType;
use App\Service\UserDatabaseManager;
use App\Service\UserKeyManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        EntityManagerInterface $entityManager,
        UserDatabaseManager $databaseManager,
        UserKeyManager $keyManager
    ): Response {
        $user = new User();
        $form = $this->createForm(RegistrationType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Hash the password
            $user->setPassword(
                $userPasswordHasher->hashPassword(
                    $user,
                    $form->get('password')->getData()
                )
            );

            // Create user's personal database first
            $databaseManager->createUserDatabase($user);

            // Store the keys in user's personal database
            $keyManager->storeKeys(
                $user,
                $form->get('publicKey')->getData(),
                $form->get('encryptedPrivateKey')->getData(),
                $form->get('keySalt')->getData()
            );

            try {
                // Now save the user with the database path
                $entityManager->persist($user);
                $entityManager->flush();
            } catch (\Exception $e) {
                // If saving fails, we should clean up the created database
                if (file_exists($user->getDatabasePath())) {
                    unlink($user->getDatabasePath());
                }
                throw $e;
            }

            // Add flash message
            $this->addFlash('success', 'Registration successful! You can now log in.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form->createView(),
        ]);
    }
}
