<?php

namespace App\Controller;

use App\Service\NotificationService;
use App\SSE\Event;
use App\SSE\EventPublisher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/notifications')]
#[IsGranted('ROLE_USER')]
class NotificationController extends AbstractController
{
    public function __construct(
        private readonly NotificationService $notificationService
    ) {
    }

    #[Route('', name: 'app_notifications')]
    public function list(): Response
    {
        return $this->render('components/_notifications.html.twig');
    }

    #[Route('/test', name: 'app_notifications_test')]
    public function test(EventPublisher $eventPublisher): Response
    {
        $user = $this->getUser();
        
        // Create a test notification
        $notification = $this->notificationService->createNotification(
            user: $user,
            title: 'Test Notification',
            message: 'This is a test notification sent at ' . date('H:i:s'),
            type: array_rand(array_flip(['info', 'success', 'warning', 'error']))
        );

        // Emit SSE event with the notification data
        $eventPublisher->publish(new Event(
            'notification',
            ['notification' => $notification->toArray()]
        ));

        return $this->json([
            'status' => 'success',
            'message' => 'Test notification created',
            'notification' => $notification->toArray()
        ]);
    }

    #[Route('/mark-all-read', name: 'app_notifications_mark_all_read', methods: ['POST'])]
    public function markAllRead(): Response
    {
        $user = $this->getUser();
        $this->notificationService->markAllAsRead($user);

        return $this->json(['status' => 'success']);
    }

    #[Route('/{id}/mark-read', name: 'app_notifications_mark_read', methods: ['POST'])]
    public function markAsRead(int $id): Response
    {
        $user = $this->getUser();
        $this->notificationService->markAsRead($user, $id);

        return $this->json(['status' => 'success']);
    }
}
