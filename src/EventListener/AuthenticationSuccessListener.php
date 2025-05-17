<?php

namespace App\EventListener;

use App\Service\SettingsService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\HttpFoundation\Cookie;

class AuthenticationSuccessListener implements EventSubscriberInterface
{
    private const SUPPORTED_LOCALES = ['en', 'cs', 'sk'];
    
    public function __construct(
        private readonly SettingsService $settingsService
    ) {}
    
    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }
    
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        // Get the user's locale preference from settings
        $locale = $this->settingsService->getSettingValue('_locale');
        
        // If no locale is set or it's not supported, don't do anything
        if (!$locale || !in_array($locale, self::SUPPORTED_LOCALES)) {
            return;
        }
        
        // Get the request and response
        $request = $event->getRequest();
        $response = $event->getResponse();
        
        // Set the locale in the session
        if ($request->hasSession()) {
            $request->getSession()->set('_locale', $locale);
        }
        
        // Set the locale in the request (for the current request)
        $request->setLocale($locale);
        
        // Set a cookie for consistent language handling
        $response->headers->setCookie(new Cookie(
            'citadel_locale',     // name
            $locale,              // value
            new \DateTime('+1 year'), // expires
            '/',                 // path
            null,                // domain
            true,                // secure
            true,                // httpOnly
            true,                // raw
            Cookie::SAMESITE_LAX // sameSite
        ));
    }
}
