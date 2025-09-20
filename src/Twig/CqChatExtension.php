<?php

namespace App\Twig;

use App\Service\CqChatMsgService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class CqChatExtension extends AbstractExtension
{
    public function __construct(
        private readonly CqChatMsgService $cqChatMsgService,
        private readonly Security $security
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('get_cq_chat_unseen_count', [$this, 'getUnseenCount']),
        ];
    }

    public function getUnseenCount(): int
    {
        if (!$this->security->getUser()) {
            return 0;
        }

        try {
            return $this->cqChatMsgService->countUnseenMessages();
        } catch (\Exception $e) {
            return 0;
        }
    }
}
