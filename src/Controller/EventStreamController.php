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
            $this->eventPublisher->sendEvent(
                data: json_encode(['message' => 'SSE connection established']),
                event: 'debug'
            );

            while (true) {
                // First check if client is still connected
                if (connection_aborted()) {
                    $this->eventPublisher->sendEvent(
                        data: json_encode(['message' => 'Client disconnected']),
                        event: 'debug'
                    );
                    // Ensure output is flushed
                    while (ob_get_level() > 0) {
                        ob_end_flush();
                    }
                    flush();
                    break;
                }

                // Get and send any pending events
                $events = $this->eventPublisher->getAndClearEvents();
                if (!empty($events)) {
                    $this->eventPublisher->sendEvent(
                        data: json_encode(['count' => count($events)]),
                        event: 'debug'
                    );
                }

                foreach ($events as $event) {
                    $this->eventPublisher->sendEvent(
                        data: json_encode($event->getData()),
                        event: $event->getType(),
                        id: $event->getId()
                    );
                }
                
                // Send a heartbeat
                $this->eventPublisher->sendEvent(
                    data: json_encode(['time' => time()]),
                    event: 'heartbeat'
                );

                // Ensure output is flushed
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
                flush();

                // Sleep to prevent CPU overload
                usleep(3000000); // 3 seconds
            }
        });
    }
}
