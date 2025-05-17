<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Service\SettingsService;

class LanguageController extends AbstractController
{
    private const SUPPORTED_LOCALES = ['en', 'cs', 'sk'];

    #[Route('/language/{locale}', name: 'app_language_switch')]
    public function switch(Request $request, string $locale, SettingsService $settingsService): Response
    {
        // Validate locale
        if (!in_array($locale, self::SUPPORTED_LOCALES)) {
            throw $this->createNotFoundException('Unsupported locale');
        }

        // Store the locale in session
        $request->getSession()->set('_locale', $locale);
        
        // Store the locale in settings
        $settingsService->setSetting('_locale', $locale);
        
        // Create response
        if ($request->isXmlHttpRequest()) {
            $response = new JsonResponse(['status' => 'success', 'locale' => $locale]);
        } else {
            $response = $this->redirect($request->headers->get('referer') ?: '/');
        }

        // Set cookie for consistent language handling between authenticated and non-authenticated users
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
        
        return $response;
    }
}
