<?php

namespace App\SSE;

use App\Service\SSEEventStorage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\Response;

class EventPublisher
{
    private const RETRY_TIMEOUT = 2000; // 2 seconds
    
    public function __construct(
        private readonly SSEEventStorage $storage
    ) {
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
        if ($id) {
            echo "id: $id\n";
        }
        
        if ($event) {
            echo "event: $event\n";
        }
        
        echo "data: " . str_replace("\n", "\ndata: ", $data) . "\n\n";
    }

    /**
     * Publish an event to all connected clients
     */
    public function publish(Event $event): void
    {
        $this->storage->storeEvent($event);
    }

    /**
     * Get and clear all pending events
     * @return Event[]
     */
    public function getAndClearEvents(): array
    {
        return $this->storage->getAndClearEvents();
    }
}
