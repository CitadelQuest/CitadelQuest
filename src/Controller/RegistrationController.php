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
use Symfony\Contracts\Translation\TranslatorInterface;

class RegistrationController extends AbstractController
{
    public function __construct(
        private TranslatorInterface $translator
    ) {}
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

            // Check if this is the first user
            $connection = $entityManager->getConnection();
            $userCount = $connection->executeQuery('SELECT COUNT(*) FROM user')->fetchOne();
            
            // If this is the first user, make them an admin
            if ($userCount === 0) {
                $user->setRoles(['ROLE_USER', 'ROLE_ADMIN']);
            } else {
                $user->setRoles(['ROLE_USER']);
            }

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
                $databaseManager->deleteUserDatabase($user);
                throw $e;
            }

            // Add flash message
            $this->addFlash('success', $this->translator->trans('auth.register.success'));

            return $this->redirectToRoute('app_login');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form->createView(),
        ]);
    }
}
