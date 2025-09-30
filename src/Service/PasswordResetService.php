<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Psr\Log\LoggerInterface;

class PasswordResetService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Generate a secure temporary password
     */
    public function generateTemporaryPassword(int $length = 16): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $password = '';
        $charsLength = strlen($chars);
        
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, $charsLength - 1)];
        }
        
        return $password;
    }

    /**
     * Reset user password with temporary password
     * 
     * @param User $user The user to reset password for
     * @param User $admin The admin performing the reset
     * @return string The temporary password (plain text, to be shown once)
     */
    public function resetUserPassword(User $user, User $admin): string
    {
        $temporaryPassword = $this->generateTemporaryPassword();
        
        // Hash and set the temporary password
        $hashedPassword = $this->passwordHasher->hashPassword($user, $temporaryPassword);
        $user->setPassword($hashedPassword);
        
        // Mark user as requiring password change
        $user->setRequirePasswordChange(true);
        
        // Save changes
        $this->entityManager->flush();
        
        // Log the action
        $this->logger->info('Password reset by admin', [
            'user_id' => $user->getId(),
            'username' => $user->getUsername(),
            'admin_id' => $admin->getId(),
            'admin_username' => $admin->getUsername(),
            'timestamp' => new \DateTime()
        ]);
        
        return $temporaryPassword;
    }

    /**
     * Change user password (used when user sets new password)
     * 
     * @param User $user
     * @param string $newPassword Plain text new password
     */
    public function changePassword(User $user, string $newPassword): void
    {
        $hashedPassword = $this->passwordHasher->hashPassword($user, $newPassword);
        $user->setPassword($hashedPassword);
        $user->setRequirePasswordChange(false);
        
        $this->entityManager->flush();
        
        $this->logger->info('User changed password', [
            'user_id' => $user->getId(),
            'username' => $user->getUsername(),
            'timestamp' => new \DateTime()
        ]);
    }
}
