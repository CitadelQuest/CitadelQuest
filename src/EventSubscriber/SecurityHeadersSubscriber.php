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
        
        foreach ($this->securityHeaders as $header => $value) {
            $response->headers->set($header, $value);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
        ];
    }
}
