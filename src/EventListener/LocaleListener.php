<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class LocaleListener implements EventSubscriberInterface
{
    private const SUPPORTED_LOCALES = ['en', 'cs', 'sk'];
    private string $defaultLocale;

    public function __construct(string $defaultLocale = 'en')
    {
        $this->defaultLocale = $defaultLocale;
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $locale = null;
        
        // First try cookie for consistency between authenticated and non-authenticated users
        if ($request->cookies->has('citadel_locale')) {
            $locale = $request->cookies->get('citadel_locale');
        }
        
        // If no cookie locale, try session (for authenticated users)
        if (!$locale && $request->hasSession()) {
            $locale = $request->getSession()->get('_locale');
        }

        // Validate locale
        if ($locale && !in_array($locale, self::SUPPORTED_LOCALES)) {
            $locale = null;
        }

        // Set the locale (either from cookie, session, or default)
        $request->setLocale($locale ?: $this->defaultLocale);
        
        // If we have a valid locale and session, store it
        if ($locale && $request->hasSession()) {
            $request->getSession()->set('_locale', $locale);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Must be registered before the default Locale listener
            KernelEvents::REQUEST => [['onKernelRequest', 20]],
        ];
    }
}
