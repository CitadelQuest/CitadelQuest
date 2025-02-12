<?php

namespace App\Controller;

use App\SSE\Event;
use App\SSE\EventPublisher;
use App\Service\NotificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class EventStreamController extends AbstractController
{
    public function __construct(
        private readonly EventPublisher $eventPublisher,
        private readonly NotificationService $notificationService
    ) {
    }

    #[Route('/events', name: 'app_events')]
    #[IsGranted('ROLE_USER')]
    public function streamEvents(): StreamedResponse
    {
        return $this->eventPublisher->createResponse(function () {
            $user = $this->getUser();
            
            // Get unread notifications
            $notifications = $this->notificationService->getUnreadNotifications($user);
            
            foreach ($notifications as $notification) {
                $event = $this->notificationService->createNotificationEvent($notification);
                
                $this->eventPublisher->sendEvent(
                    data: $event->toJson(),
                    event: $event->getType(),
                    id: $event->getId()
                );
            }
        });
    }
}
