<?php

namespace App\Controller;

use App\Service\PasswordResetService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
class ChangePasswordController extends AbstractController
{
    public function __construct(
        private readonly PasswordResetService $passwordResetService,
        private readonly TranslatorInterface $translator
    ) {}

    #[Route('/change-password', name: 'app_change_password')]
    public function changePassword(Request $request): Response
    {
        $user = $this->getUser();
        
        // If user doesn't need to change password, redirect to home
        if (!$user->isRequirePasswordChange()) {
            return $this->redirectToRoute('app_home');
        }

        if ($request->isMethod('POST')) {
            $newPassword = $request->request->get('new_password');
            $confirmPassword = $request->request->get('confirm_password');

            // Validate passwords
            if (empty($newPassword) || empty($confirmPassword)) {
                $this->addFlash('error', $this->translator->trans('password_change.error.empty'));
                return $this->render('security/change_password.html.twig');
            }

            if ($newPassword !== $confirmPassword) {
                $this->addFlash('error', $this->translator->trans('password_change.error.mismatch'));
                return $this->render('security/change_password.html.twig');
            }

            if (strlen($newPassword) < 8) {
                $this->addFlash('error', $this->translator->trans('password_change.error.too_short'));
                return $this->render('security/change_password.html.twig');
            }

            // Password strength validation
            if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/', $newPassword)) {
                $this->addFlash('error', $this->translator->trans('password_change.error.weak'));
                return $this->render('security/change_password.html.twig');
            }

            // Change the password
            $this->passwordResetService->changePassword($user, $newPassword);

            $this->addFlash('success', $this->translator->trans('password_change.success'));
            return $this->redirectToRoute('app_home');
        }

        return $this->render('security/change_password.html.twig');
    }
}
