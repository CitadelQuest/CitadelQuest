<?php

namespace App\EventListener;

use App\Service\SystemSettingsService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class HomepageRedirectListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly SystemSettingsService $systemSettingsService
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Only intercept exact homepage path
        if ($request->getPathInfo() !== '/') {
            return;
        }

        // Skip if user has an active session (authenticated) — let DashboardController handle it
        // We check the session directly because the firewall (priority 8) hasn't populated
        // the token storage yet at this priority level
        if ($request->hasPreviousSession()) {
            $session = $request->getSession();
            if ($session->has('_security_main')) {
                return;
            }
        }

        // Check if homepage redirect is enabled
        if (!$this->systemSettingsService->getBooleanValue('cq_homepage_redirect', false)) {
            return;
        }

        // Get target username
        $username = $this->systemSettingsService->getSettingValue('cq_homepage_redirect_username');
        if (empty($username)) {
            return;
        }

        // Redirect to the user's public CQ Profile page
        $event->setResponse(new RedirectResponse('/' . $username, 302));
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Priority 9: must fire BEFORE the security firewall (priority 8)
            // so unauthenticated visitors get redirected instead of sent to /login
            KernelEvents::REQUEST => [['onKernelRequest', 9]],
        ];
    }
}
