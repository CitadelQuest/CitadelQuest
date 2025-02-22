<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

class ToastService
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly TranslatorInterface $translator
    ) {
    }
    
    public function success(string $message, array $parameters = []): void
    {
        $this->addFlash('success', $message, $parameters);
    }
    
    public function error(string $message, array $parameters = []): void
    {
        $this->addFlash('danger', $message, $parameters);
    }
    
    public function warning(string $message, array $parameters = []): void
    {
        $this->addFlash('warning', $message, $parameters);
    }
    
    public function info(string $message, array $parameters = []): void
    {
        $this->addFlash('info', $message, $parameters);
    }
    
    private function addFlash(string $type, string $message, array $parameters = []): void
    {
        $session = $this->requestStack->getSession();
        $translatedMessage = $this->translator->trans($message, $parameters);
        $session->getFlashBag()->add($type, $translatedMessage);
    }
}
