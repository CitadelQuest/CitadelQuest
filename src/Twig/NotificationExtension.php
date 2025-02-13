<?php

namespace App\Twig;

use App\Service\NotificationService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class NotificationExtension extends AbstractExtension
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly Security $security
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('get_notifications', [$this, 'getNotifications']),
            new TwigFunction('get_unread_count', [$this, 'getUnreadCount']),
            new TwigFunction('get_unread_notifications', [$this, 'getUnreadNotifications']),
        ];
    }

    public function getNotifications(): array
    {
        $user = $this->security->getUser();
        if (!$user) {
            return [];
        }

        return $this->notificationService->getAllNotifications($user);
    }

    public function getUnreadNotifications(): array
    {
        $user = $this->security->getUser();
        if (!$user) {
            return [];
        }

        return $this->notificationService->getUnreadNotifications($user);
    }

    public function getUnreadCount(): int
    {
        $user = $this->security->getUser();
        if (!$user) {
            return 0;
        }

        return count($this->notificationService->getUnreadNotifications($user));
    }
}
