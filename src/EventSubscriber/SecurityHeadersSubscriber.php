<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    private array $securityHeaders;

    public function __construct(ParameterBagInterface $params)
    {
        $this->securityHeaders = $params->get('security_headers');
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        
        // Allow cross-origin framing for inline public share responses (e.g. PDF preview in CQ Explorer)
        $allowFrame = $response->headers->has('X-CQ-Allow-Frame');

        foreach ($this->securityHeaders as $header => $value) {
            if ($allowFrame && $header === 'X-Frame-Options') {
                continue;
            }
            $response->headers->set($header, $value);
        }

        if ($allowFrame) {
            $response->headers->remove('X-CQ-Allow-Frame');
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
        ];
    }
}
