<?php

namespace App\SSE;

use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\Response;

class EventPublisher
{
    private const RETRY_TIMEOUT = 2000; // 2 seconds

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
            
            while (true) {
                // Clear output buffer to prevent memory issues
                if (ob_get_level() > 0) {
                    ob_end_flush();
                }
                
                // Call the event generator
                $callback();
                
                // Flush output
                flush();
                
                // Check if client is still connected
                if (connection_aborted()) {
                    return;
                }
                
                // Sleep to prevent CPU overload
                usleep(100000); // 100ms
            }
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
}
