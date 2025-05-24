<?php

namespace App\SSE;

use App\Service\SSEEventStorage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;
use App\Entity\User;

class EventPublisher
{
    private const RETRY_TIMEOUT = 2000; // 2 seconds
    
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly SSEEventStorage $storage,
    ) {
    }

    public function init(User $user): void
    {
        $this->storage->init($user);
    }

    /**
     * Create SSE response with proper headers
     */
    public function createResponse(callable $callback): StreamedResponse
    {
        $response = new StreamedResponse();
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no'); // Disable nginx buffering
        $response->headers->set('Connection', 'keep-alive');

        $response->setCallback(function () use ($callback) {
            // Send retry interval
            echo "retry: " . self::RETRY_TIMEOUT . "\n\n";
            flush();
            
            // Call the event generator
            $callback();
        });

        return $response;
    }

    /**
     * Send a single event
     */
    public function sendEvent(string $data, ?string $event = null, ?string $id = null): void
    {
        $output = "";
        
        if ($id) {
            $output .= "id: $id\n";
        }
        
        if ($event) {
            $output .= "event: $event\n";
        }
        
        $output .= "data: " . str_replace("\n", "\ndata: ", $data) . "\n\n";

        echo $output;
    }

    /**
     * Publish an event to all connected clients
     */
    public function publish(Event $event, User $user): void
    {
        $request = $this->requestStack->getCurrentRequest();

        $windowId = $request->cookies->get('browserWindowId', '')??'';
        if (!$windowId) {
            return;
        }
        
        $event->setId($windowId);

        $this->storage->init($user);

        $this->storage->storeEvent($event);
    }

    /**
     * Connect client to SSE
     */
    public function connect(string $windowId): void
    {
        $this->storage->storeWindow($windowId);
    }

    /**
     * Disconnect client from SSE
     */
    public function disconnect(string $windowId): void
    {
        $this->storage->clearWindow($windowId);
    }
    /**
     * Is client connected to SSE
     */
    public function isConnected(string $windowId): bool
    {
        return $this->storage->isConnected($windowId);
    }
    public function connectionAborted(string $windowId): bool
    {
        return !$this->storage->isConnected($windowId);
    }

    /**
     * Get and clear all pending events
     * @return Event[]
     */
    public function getAndClearEvents(string $windowId): array
    {
        return $this->storage->getAndClearEvents($windowId);
    }

    /**
     * Health check
     */
    public function healthCheck(): array
    {
        return $this->storage->healthCheck();
    }

    /**
     * Clear database
     */
    public function clearDatabase(): void
    {
        $this->storage->clearDatabase();
    }

    public function getWindows(): array
    {
        return $this->storage->getWindows();
    }
}
