<?php

namespace App\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ServiceSubscriberInterface;

/**
 * Spirit-to-Spirit Chat tools: `callSpirit` and `listSpirits`.
 *
 * Lets one Spirit consult a fellow Spirit (owned by the same user) for help with
 * a task that benefits from the fellow Spirit's own model, memory and tools.
 *
 * The consultation runs SYNCHRONOUSLY inside the already-running turn worker
 * (no HTTP request held open) via SpiritConversationService::runTurnSync(), and
 * the callee's final answer is returned as the tool result to the caller.
 *
 * SpiritConversationService is fetched lazily through a service-subscriber
 * locator to break the constructor cycle
 * (AIToolCallService -> AIToolSpiritService -> SpiritConversationService -> AIToolCallService).
 *
 * @see /docs/features/Spirit2SpiritChat.md
 */
class AIToolSpiritService implements ServiceSubscriberInterface
{
    private const CALLEE_MAX_OUTPUT = 2000;
    private const CALLEE_TEMPERATURE = 0.7;
    private const CALLEE_TOOL_TEMPERATURE = 0.5;

    public function __construct(
        private readonly ContainerInterface $locator,
        private readonly SpiritService $spiritService,
        private readonly SpiritCallContext $spiritCallContext,
        private readonly LoggerInterface $logger
    ) {
    }

    public static function getSubscribedServices(): array
    {
        return [
            SpiritConversationService::class,
            SpiritConversationMessageService::class,
        ];
    }

    private function conversationService(): SpiritConversationService
    {
        return $this->locator->get(SpiritConversationService::class);
    }

    private function messageService(): SpiritConversationMessageService
    {
        return $this->locator->get(SpiritConversationMessageService::class);
    }

    /**
     * List fellow Spirits the caller may consult.
     */
    public function listSpirits(array $arguments): array
    {
        $callerId = $arguments['spiritId'] ?? null;
        if (!$callerId) {
            return ['success' => false, 'error' => 'Caller Spirit context missing.'];
        }

        $allowed = $this->getAllowedSpiritIds($callerId);

        $spirits = [];
        foreach ($this->spiritService->findAll() as $spirit) {
            $id = $spirit->getId();
            if ($id === $callerId) {
                continue; // never list self
            }
            if ($this->spiritService->getSpiritSetting($id, 's2s.callable', '1') !== '1') {
                continue; // opted out of being consulted
            }
            if ($allowed !== null && !in_array($id, $allowed, true)) {
                continue; // not in caller's allow-list
            }
            $spirits[] = [
                'spiritId' => $id,
                'name' => $spirit->getName(),
                'specialty' => $this->spiritService->getSpiritSetting($id, 's2s.specialty') ?: '',
                'color' => $this->spiritService->getSpiritColor($id),
            ];
        }

        return [
            'success' => true,
            'spirits' => $spirits,
            '_frontendData' => $this->buildListSpiritsBadge($spirits),
        ];
    }

    /**
     * Consult a fellow Spirit and return its answer.
     */
    public function callSpirit(array $arguments): array
    {
        $callerId = $arguments['spiritId'] ?? null;
        $message = trim((string) ($arguments['message'] ?? ''));
        $lang = $arguments['lang'] ?? 'English';
        $conversationId = $arguments['conversationId'] ?? null;

        if (!$callerId) {
            return ['success' => false, 'error' => 'Caller Spirit context missing.'];
        }
        if ($message === '') {
            return ['success' => false, 'error' => 'A message for the fellow Spirit is required.'];
        }

        // Master permission gate for the caller. Defaults to enabled: activating the
        // callSpirit tool per-Spirit is itself the explicit opt-in. Set s2s.enabled='0'
        // to hard-disable consultations for a Spirit even if the tool is active.
        if ($this->spiritService->getSpiritSetting($callerId, 's2s.enabled', '1') !== '1') {
            return ['success' => false, 'error' => 'You are not permitted to consult other Spirits.'];
        }

        // Resolve the target Spirit.
        $callee = $this->resolveTarget($arguments, $callerId);
        if (!$callee['ok']) {
            return ['success' => false, 'error' => $callee['error']];
        }
        $calleeId = $callee['id'];
        $calleeName = $callee['name'];
        $calleeColor = $this->spiritService->getSpiritColor($calleeId);
        $callerName = $this->spiritService->getSpirit($callerId)?->getName() ?? 'a fellow Spirit';
        $callerColor = $this->spiritService->getSpiritColor($callerId);

        // Permission: callee must allow being consulted + be in caller's allow-list.
        if ($this->spiritService->getSpiritSetting($calleeId, 's2s.callable', '1') !== '1') {
            return ['success' => false, 'error' => 'This Spirit is not available for consultation.'];
        }
        $allowed = $this->getAllowedSpiritIds($callerId);
        if ($allowed !== null && !in_array($calleeId, $allowed, true)) {
            return ['success' => false, 'error' => 'You are not allowed to consult this Spirit.'];
        }

        // Safeguards: depth / cycle / budget.
        $guardError = $this->spiritCallContext->validateCall($calleeId);
        if ($guardError !== null) {
            return ['success' => false, 'error' => $guardError];
        }

        try {
            $conversation = $this->conversationService()->getOrCreateS2SConversation(
                $callerId,
                $calleeId,
                $conversationId
            );

            // Write the caller's request as a 'user' message in the callee's thread.
            $callerMessage = $this->messageService()->createMessage(
                $conversation->getId(),
                'user',
                'text',
                [['type' => 'text', 'text' => $message]]
            );

            $this->spiritCallContext->enter($calleeId);
            try {
                $result = $this->conversationService()->runTurnSync(
                    $conversation->getId(),
                    $callerMessage->getId(),
                    $lang,
                    self::CALLEE_MAX_OUTPUT,
                    self::CALLEE_TEMPERATURE,
                    self::CALLEE_TOOL_TEMPERATURE
                );
            } finally {
                $this->spiritCallContext->leave();
            }

            $answer = $result['answer'] ?? '';
            if ($answer === '') {
                $answer = '(The fellow Spirit did not return a textual answer.)';
            }

            $cost = $this->conversationService()->getConversationPrice($conversation->getId());

            return [
                'success' => true,
                'spirit' => [
                    'spiritId' => $calleeId,
                    'name' => $calleeName,
                    'color' => $calleeColor,
                ],
                'caller' => [
                    'spiritId' => $callerId,
                    'name' => $callerName,
                    'color' => $callerColor,
                ],
                'conversationId' => $conversation->getId(),
                'answer' => $answer,
                'cost' => $cost,
                '_frontendData' => $this->buildBadge(
                    $calleeName,
                    $calleeColor,
                    $callerName,
                    $callerColor,
                    $message,
                    $answer,
                    $conversation->getId(),
                    $cost['total_price_formatted'] ?? '0.00'
                ),
            ];
        } catch (\Throwable $e) {
            $this->logger->error('callSpirit failed: {error}', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'The consultation failed: ' . $e->getMessage()];
        }
    }

    /**
     * Resolve the target Spirit by id or (case-insensitive) name.
     *
     * @return array{ok:bool, id?:string, name?:string, error?:string}
     */
    private function resolveTarget(array $arguments, string $callerId): array
    {
        $targetId = $arguments['targetSpiritId'] ?? null;
        $targetName = isset($arguments['targetSpiritName']) ? trim((string) $arguments['targetSpiritName']) : null;

        if ($targetId) {
            $spirit = $this->spiritService->getSpirit($targetId);
            if (!$spirit) {
                return ['ok' => false, 'error' => 'Spirit not found: ' . $targetId];
            }
            if ($spirit->getId() === $callerId) {
                return ['ok' => false, 'error' => 'A Spirit cannot consult itself.'];
            }
            return ['ok' => true, 'id' => $spirit->getId(), 'name' => $spirit->getName()];
        }

        if ($targetName) {
            $matches = [];
            foreach ($this->spiritService->findAll() as $spirit) {
                if (mb_strtolower($spirit->getName()) === mb_strtolower($targetName)) {
                    $matches[] = $spirit;
                }
            }
            if (count($matches) === 0) {
                return ['ok' => false, 'error' => 'No Spirit named "' . $targetName . '" found. Use listSpirits to see who you can consult.'];
            }
            if (count($matches) > 1) {
                return ['ok' => false, 'error' => 'Multiple Spirits named "' . $targetName . '"; specify targetSpiritId instead.'];
            }
            $spirit = $matches[0];
            if ($spirit->getId() === $callerId) {
                return ['ok' => false, 'error' => 'A Spirit cannot consult itself.'];
            }
            return ['ok' => true, 'id' => $spirit->getId(), 'name' => $spirit->getName()];
        }

        return ['ok' => false, 'error' => 'Provide targetSpiritId or targetSpiritName. Use listSpirits to discover fellow Spirits.'];
    }

    /**
     * Expandable badge listing the fellow Spirits discovered by listSpirits.
     */
    private function buildListSpiritsBadge(array $spirits): string
    {
        if ($spirits === []) {
            return '<div class="s2s-list-badge card border-0 bg-dark my-2"><div class="card-body p-2">'
                . '<div class="small text-cyber fw-bold mb-1"><i class="mdi mdi-account-group-outline me-1"></i>No fellow Spirits available</div>'
                . '</div></div>';
        }

        $items = '';
        foreach ($spirits as $spirit) {
            $name = htmlspecialchars($spirit['name'], ENT_QUOTES);
            $specialty = htmlspecialchars($spirit['specialty'], ENT_QUOTES);
            $color = htmlspecialchars($spirit['color'] ?? '#95ec86', ENT_QUOTES);
            $specialtyHtml = $specialty !== '' ? '<div class="small opacity-75">' . $specialty . '</div>' : '';
            $items .= '<li class="list-group-item bg-dark text-light border-secondary py-1 px-2 d-flex align-items-center gap-2">'
                . '<i class="mdi mdi-ghost" style="color:' . $color . ';"></i>'
                . '<div><div class="fw-bold small">' . $name . '</div>' . $specialtyHtml . '</div>'
                . '</li>';
        }

        return '<div class="s2s-list-badge card border-0 bg-dark my-2">'
            . '<div class="card-body p-2">'
            . '<div class="small text-cyber fw-bold mb-1"><i class="mdi mdi-account-group-outline me-1"></i>Fellow Spirits available</div>'
            . '<details><summary class="small opacity-75" style="cursor:pointer;">View ' . count($spirits) . ' Spirit' . (count($spirits) === 1 ? '' : 's') . '</summary>'
            . '<ul class="list-group list-group-flush mt-2">' . $items . '</ul>'
            . '</details>'
            . '</div></div>';
    }

    /**
     * The caller's allow-list of consultable spirit ids, or null when unrestricted.
     */
    private function getAllowedSpiritIds(string $callerId): ?array
    {
        $json = $this->spiritService->getSpiritSetting($callerId, 's2s.allowedSpirits');
        if ($json === null || $json === '') {
            return null;
        }
        $ids = json_decode($json, true);
        return is_array($ids) ? $ids : null;
    }

    /**
     * Expandable "consulted Spirit" badge for the caller's chat UI.
     */
    private function buildBadge(
        string $calleeName,
        string $calleeColor,
        string $callerName,
        string $callerColor,
        string $message,
        string $answer,
        string $conversationId,
        string $totalPriceFormatted
    ): string {
        $calleeNameHtml = htmlspecialchars($calleeName, ENT_QUOTES);
        $callerNameHtml = htmlspecialchars($callerName, ENT_QUOTES);
        $calleeColorHtml = htmlspecialchars($calleeColor, ENT_QUOTES);
        $callerColorHtml = htmlspecialchars($callerColor, ENT_QUOTES);
        $q = nl2br(htmlspecialchars($message, ENT_QUOTES));
        $a = nl2br(htmlspecialchars($answer, ENT_QUOTES));
        $cid = htmlspecialchars($conversationId, ENT_QUOTES);

        $calleeIcon = '<i class="mdi mdi-ghost me-1" style="color:' . $calleeColorHtml . ';"></i>';
        $callerIcon = '<i class="mdi mdi-ghost me-1" style="color:' . $callerColorHtml . ';"></i>';

        return '<div class="s2s-consult-badge card border-0 bg-dark my-2" data-conversation-id="' . $cid . '">'
            . '<div class="card-body p-2">'
            . '<div class="small mb-1">Consulted fellow Spirit: ' . $calleeIcon . ' <span class=" text-cyber fw-bold">' . $calleeNameHtml . '</span></div>'
            . '<details>'
            . '<summary class="small opacity-75" style="cursor:pointer;">View consultation</summary>'
            . '<div class="mt-2 small">' . $callerIcon . '<strong>' . $callerNameHtml . ' asked:</strong><br>' . $q . '</div>'
            . '<div class="mt-2 small">' . $calleeIcon . '<strong>' . $calleeNameHtml . ' answered:</strong><br>' . $a . '</div>'
            . '</details>'
            . '<div class="mt-2 small opacity-75"><i class="mdi mdi-circle-multiple-outline me-1 text-cyber opacity-50" title="Credits"></i>' . $totalPriceFormatted . '</div>'
            . '</div></div>';
    }
}
