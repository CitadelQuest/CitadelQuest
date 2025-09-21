<?php

namespace App\EventListener;

use App\Service\SettingsService;
use App\Service\AiModelsSyncService;
use App\SSE\EventPublisher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Psr\Log\LoggerInterface;

class AuthenticationSuccessListener implements EventSubscriberInterface
{
    private const SUPPORTED_LOCALES = ['en', 'cs', 'sk'];
    
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly AiModelsSyncService $aiModelsSyncService,
        private readonly EventPublisher $eventPublisher,
        private readonly RouterInterface $router,
        private readonly LoggerInterface $logger
    ) {}
    
    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
        ];
    }
    
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $this->logger->info('AuthenticationSuccessListener: User login successful');
        
        // Get the user's locale preference from settings
        $locale = $this->settingsService->getSettingValue('_locale');
        
        // Set locale if configured
        if ($locale && in_array($locale, self::SUPPORTED_LOCALES)) {
            $request = $event->getRequest();
            
            // Set the locale in the session
            if ($request->hasSession()) {
                $request->getSession()->set('_locale', $locale);
            }
            
            // Set the locale in the request (for the current request)
            $request->setLocale($locale);
            
            $this->logger->info('AuthenticationSuccessListener: Set user locale', ['locale' => $locale]);
        }

        // Check if AI models need updating (instead of redirect workaround), only if setting `onboarding.completed` is set and 1
        $onboardingCompleted = (int)($this->settingsService->getSettingValue('onboarding.completed', '0'));

        if ($onboardingCompleted === 1 && $this->aiModelsSyncService->shouldUpdateModels()) {
            $this->logger->info('AuthenticationSuccessListener: AI models need updating, starting background sync');
            
            try {
                // Attempt to sync models in background
                $result = $this->aiModelsSyncService->syncModels();
                
                if ($result['success']) {
                    $this->logger->info('AuthenticationSuccessListener: AI models updated successfully', [
                        'models_count' => count($result['models'] ?? [])
                    ]);
                } else {
                    $this->logger->warning('AuthenticationSuccessListener: AI models sync failed', [
                        'error' => $result['message'],
                        'error_code' => $result['error_code'] ?? 'UNKNOWN'
                    ]);
                }
            } catch (\Exception $e) {
                $this->logger->error('AuthenticationSuccessListener: Exception during AI models sync', [
                    'error' => $e->getMessage()
                ]);
            }
        } else {
            $this->logger->info('AuthenticationSuccessListener: AI models are up to date, no sync needed');
        }
        
        // No redirect needed - let normal login flow continue
    }
}
