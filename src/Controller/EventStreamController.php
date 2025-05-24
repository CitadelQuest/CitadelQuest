<?php

namespace App\Controller;

use App\SSE\Event;
use App\SSE\EventPublisher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class EventStreamController extends AbstractController
{
    public function __construct(
        private readonly EventPublisher $eventPublisher
    ) {
    }

    #[Route('/events-{windowId}', name: 'app_events')]
    #[IsGranted('ROLE_USER')]
    public function streamEvents(string $windowId): StreamedResponse
    {
        $user = $this->getUser();
        
        return $this->eventPublisher->createResponse(function () use ($windowId, $user) {
            
            $this->eventPublisher->init($user);

            if (!$windowId) {
                $this->eventPublisher->sendEvent(
                    data: json_encode(['message' => 'No window ID found']),
                    event: 'error'
                );
                // flush and close
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
                flush();
                return;
            }

            $this->eventPublisher->connect($windowId);
            $this->eventPublisher->sendEvent(
                data: json_encode(['message' => 'SSE connection established ' . $windowId]),
                event: 'debug'
            );

            while (true) {
                // First check if client is still connected
                if (connection_aborted() || $this->eventPublisher->connectionAborted($windowId)) {
                    // Ensure output is flushed
                    while (ob_get_level() > 0) {
                        ob_end_flush();
                    }
                    flush();
                    break;
                }

                // Get and send any pending events
                $events = $this->eventPublisher->getAndClearEvents($windowId);
                if (!empty($events)) {
                    $this->eventPublisher->sendEvent(
                        data: json_encode(['count' => count($events)]),
                        event: 'debug'
                    );

                    foreach ($events as $event) {
                        $this->eventPublisher->sendEvent(
                            data: json_encode($event->getData()),
                            event: $event->getType(),
                            id: $event->getId()
                        );
                    }
                }
                
                // Send a heartbeat
                /* $this->eventPublisher->sendEvent(
                    data: json_encode(['time' => time()]),
                    event: 'heartbeat'
                ); */

                // Ensure output is flushed
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
                flush();

                // Sleep to prevent CPU overload
                usleep(3000000); // 3 seconds: 3 * 1000 * 1000
            }
        });
    }

    #[Route('/events/health', name: 'app_events_health')]
    #[IsGranted('ROLE_USER')]
    public function healthCheck(): JsonResponse
    {
        try {
            
            $this->eventPublisher->init($this->getUser());

            $status = $this->eventPublisher->healthCheck();

        } catch (\Exception $e) {
            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
        
        return $this->json($status, 200);
    }

    #[Route('/events/disconnect/{windowId}', name: 'app_events_disconnect')]
    #[IsGranted('ROLE_USER')]
    public function disconnect(string $windowId): JsonResponse
    {
        if (!$windowId) {
            return $this->json([
                'status' => 'error',
                'message' => 'No window ID found'
            ], 400);
        }

        $this->eventPublisher->init($this->getUser());

        $this->eventPublisher->disconnect($windowId);

        return $this->json([
            'status' => 'success',
            'message' => 'Disconnected from SSE client ' . $windowId
        ], 200);
    }

    #[Route('/events/windows', name: 'app_events_windows')]
    #[IsGranted('ROLE_USER')]
    public function windows(): Response
    {
        try {
            $this->eventPublisher->init($this->getUser());

            $windows = $this->eventPublisher->getWindows();

        } catch (\Exception $e) {
            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }

        $return = "<html><head><title>SSE Windows</title></head><style>body { font-family: monospace; font-size: 12px; } span { display: inline-block; width: 200px; }</style><body><div class='notification-windows'>";

        $return .= "<div>" . count($windows) . " windows [<a href='/events/windows'>refresh</a>]</div>";
        foreach ($windows as $window) {
            $return .= "<div><span>[<a href='/events/disconnect/" . $window['window_id'] . "' target='_blank'>disconnect</a>] <strong>" . $window['window_id'] . "</strong></span><span>" . $window['updated_at'] . "</span></div>";
        }

        $return .= "</div></body></html>";

        return new Response($return, 200);
    }
}
