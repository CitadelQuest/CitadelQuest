<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/profile')]
class ProfileController extends AbstractController
{
    #[Route('', name: 'app_profile')]
    public function index(): Response
    {
        return $this->render('profile/index.html.twig', [
            'user' => $this->getUser()
        ]);
    }

    #[Route('/email', name: 'app_profile_email', methods: ['POST'])]
    public function updateEmail(
        Request $request,
        EntityManagerInterface $entityManager,
        NotificationService $notificationService,
        TranslatorInterface $translator
    ): JsonResponse {
        if (!$request->isXmlHttpRequest()) {
            throw $this->createAccessDeniedException();
        }

        $user = $this->getUser();
        $email = $request->request->get('email');
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse([
                'message' => $translator->trans('profile.email.invalid')
            ], Response::HTTP_BAD_REQUEST);
        }

        // Check if email is already used
        $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existingUser && $existingUser !== $user) {
            return new JsonResponse([
                'message' => $translator->trans('auth.register.error.email_already_used')
            ], Response::HTTP_BAD_REQUEST);
        }

        $user->setEmail($email);
        $entityManager->flush();
        
        $notificationService->createNotification(
            $user,
            $translator->trans('profile.email.updated.title'),
            $translator->trans('profile.email.updated.message'),
            'success'
        );

        return new JsonResponse([
            'message' => $translator->trans('profile.email.updated.title')
        ]);
    }

    #[Route('/password', name: 'app_profile_password', methods: ['POST'])]
    public function updatePassword(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        NotificationService $notificationService,
        TranslatorInterface $translator
    ): JsonResponse {
        if (!$request->isXmlHttpRequest()) {
            throw $this->createAccessDeniedException();
        }

        $user = $this->getUser();
        $currentPassword = $request->request->get('current_password');
        $newPassword = $request->request->get('new_password');
        $confirmPassword = $request->request->get('confirm_password');

        if (!$passwordHasher->isPasswordValid($user, $currentPassword)) {
            return new JsonResponse([
                'message' => $translator->trans('profile.password.current_invalid')
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($newPassword !== $confirmPassword) {
            return new JsonResponse([
                'message' => $translator->trans('profile.password.mismatch')
            ], Response::HTTP_BAD_REQUEST);
        }

        if (strlen($newPassword) < 8) {
            return new JsonResponse([
                'message' => $translator->trans('profile.password.too_short')
            ], Response::HTTP_BAD_REQUEST);
        }

        $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
        $entityManager->flush();

        $notificationService->createNotification(
            $user,
            $translator->trans('profile.password.updated.title'),
            $translator->trans('profile.password.updated.message'),
            'success'
        );

        return new JsonResponse([
            'message' => $translator->trans('profile.password.updated.title')
        ]);
    }
}
