<?php

namespace App\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ServiceSubscriberInterface;

/**
 * Spirit-to-Spirit Chat tools: `spiritCall` and `spiritManage`.
 *
 * `spiritCall` lets one Spirit consult a fellow Spirit (owned by the same user)
 * for help with a task that benefits from the fellow Spirit's own model, memory
 * and tools.
 *
 * `spiritManage` provides read-only management operations:
 *   - listSpirits:      list fellow Spirits available for consultation
 *   - listConversations: list S2S conversations for a given Spirit
 *   - getConversation:   retrieve a specific S2S conversation (full or compact)
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
    /**
     * Fallback max output tokens for the callee's turn when its model does not
     * expose a max-output capacity. The callee always runs at its model's full
     * output capacity so its answer is never artificially truncated.
     */
    private const CALLEE_MAX_OUTPUT_FALLBACK = 8192;
    private const CALLEE_TEMPERATURE = 0.7;
    private const CALLEE_TOOL_TEMPERATURE = 0.5;

    public function __construct(
        private readonly ContainerInterface $locator,
        private readonly SpiritService $spiritService,
        private readonly AiToolService $aiToolService,
        private readonly AiToolSettingsService $aiToolSettingsService,
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
     * Spirit management tool with operations pattern.
     * Operations: listSpirits, listConversations, getConversation
     */
    public function spiritManage(array $arguments): array
    {
        $operation = $arguments['operation'] ?? null;
        if (!$operation) {
            return ['success' => false, 'error' => 'Missing required parameter: operation'];
        }

        return match ($operation) {
            'listSpirits' => $this->handleListSpirits($arguments),
            'listConversations' => $this->handleListConversations($arguments),
            'getConversation' => $this->handleGetConversation($arguments),
            default => ['success' => false, 'error' => "Unknown spiritManage operation: {$operation}"],
        };
    }

    /**
     * Operation: listSpirits — list fellow Spirits the caller may consult.
     */
    private function handleListSpirits(array $arguments): array
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
     * Operation: listConversations — list S2S conversations for the caller Spirit.
     * With filterSpiritId/filterSpiritName, returns only conversations between
     * the caller and that specific other Spirit.
     * Returns title, date, and first/last 250 chars of conversation content.
     * Paginated: 10 results per page, newest first.
     */
    private function handleListConversations(array $arguments): array
    {
        $callerId = $arguments['spiritId'] ?? null;
        if (!$callerId) {
            return ['success' => false, 'error' => 'Caller Spirit context missing.'];
        }

        $caller = $this->spiritService->getSpirit($callerId);
        if (!$caller) {
            return ['success' => false, 'error' => 'Caller Spirit not found.'];
        }

        $filterSpiritId = $arguments['filterSpiritId'] ?? null;
        $filterSpiritName = isset($arguments['filterSpiritName']) ? trim((string) $arguments['filterSpiritName']) : null;

        // Resolve filter spirit if provided
        $filterSpirit = null;
        if ($filterSpiritId || $filterSpiritName) {
            if ($filterSpiritId) {
                $filterSpirit = $this->spiritService->getSpirit($filterSpiritId);
            } else {
                foreach ($this->spiritService->findAll() as $s) {
                    if (mb_strtolower($s->getName()) === mb_strtolower($filterSpiritName)) {
                        $filterSpirit = $s;
                        break;
                    }
                }
            }
            if (!$filterSpirit) {
                return ['success' => false, 'error' => 'Filter Spirit not found.'];
            }
        }

        // Get both initiated and received S2S conversations for the caller
        $initiated = $this->conversationService()->getS2sConversationsInitiatedBySpirit($callerId);
        $received = $this->conversationService()->getS2sConversationsReceivedBySpirit($callerId);

        $conversations = [];
        $messageService = $this->messageService();

        foreach (array_merge($initiated, $received) as $conv) {
            // If filtering by a specific other Spirit, skip conversations not involving that Spirit
            if ($filterSpirit) {
                $filterId = $filterSpirit->getId();
                $convInitiator = $conv['initiatorSpiritId'] ?? null;
                $convSpiritId = $conv['spiritId'] ?? null;
                // The conversation must involve both the caller and the filter spirit
                $involvesCaller = ($convInitiator === $callerId) || ($convSpiritId === $callerId);
                $involvesFilter = ($convInitiator === $filterId) || ($convSpiritId === $filterId);
                if (!$involvesCaller || !$involvesFilter) {
                    continue;
                }
            }

            $messages = $messageService->getMessagesByConversation($conv['id']);

            // Extract text content from messages
            $allText = [];
            foreach ($messages as $msg) {
                $text = $this->extractTextFromContent($msg->getContent());
                if ($text !== '') {
                    $allText[] = $text;
                }
            }

            $firstSnippet = mb_substr($allText[0] ?? '(no content)', 0, 250);
            $lastSnippet = mb_substr($allText[count($allText) - 1] ?? '(no content)', 0, 250);

            $conversations[] = [
                'conversationId' => $conv['id'],
                'title' => $conv['title'],
                'date' => $conv['lastInteraction'] ?? $conv['createdAt'],
                'messagesCount' => $conv['messagesCount'] ?? count($messages),
                'firstSnippet' => $firstSnippet,
                'lastSnippet' => $lastSnippet,
            ];
        }

        // Sort by date descending (newest first)
        usort($conversations, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));

        // Pagination: 10 per page, default page 1
        $perPage = 10;
 $page = max(1, (int) ($arguments['page'] ?? 1));
        $totalCount = count($conversations);
        $totalPages = (int) ceil($totalCount / $perPage);
        $offset = ($page - 1) * $perPage;
        $pagedConversations = array_slice($conversations, $offset, $perPage);

        $badgeLabel = $filterSpirit
            ? $caller->getName() . ' ↔ ' . $filterSpirit->getName()
            : $caller->getName() . ' (all)';

        return [
            'success' => true,
            'spiritId' => $callerId,
            'spiritName' => $caller->getName(),
            'filterSpiritId' => $filterSpirit?->getId(),
            'filterSpiritName' => $filterSpirit?->getName(),
            'conversations' => $pagedConversations,
            'count' => count($pagedConversations),
            'totalCount' => $totalCount,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
            '_frontendData' => $this->buildListConversationsBadge($badgeLabel, $pagedConversations),
        ];
    }

    /**
     * Operation: getConversation — retrieve a specific S2S conversation.
     * resultType: 'full' (all messages) or 'compact' (first and last message only).
     */
    private function handleGetConversation(array $arguments): array
    {
        $conversationId = $arguments['conversationId'] ?? null;
        $resultType = $arguments['resultType'] ?? 'compact';

        if (!$conversationId) {
            return ['success' => false, 'error' => 'Missing required parameter: conversationId'];
        }

        $conversation = $this->conversationService()->getConversation($conversationId);
        if (!$conversation) {
            return ['success' => false, 'error' => 'Conversation not found.'];
        }

        if (!$conversation->isSpiritToSpirit()) {
            return ['success' => false, 'error' => 'This conversation is not a Spirit-to-Spirit conversation.'];
        }

        $messageService = $this->messageService();
        $messages = $messageService->getMessagesByConversation($conversationId);

        if ($resultType === 'compact') {
            // Only first and last message
            $compactMessages = [];
            if (count($messages) > 0) {
                $compactMessages[] = $messages[0];
                if (count($messages) > 1) {
                    $compactMessages[] = $messages[count($messages) - 1];
                }
            }
            $messages = $compactMessages;
        }

        $messagesData = [];
        foreach ($messages as $msg) {
            $messagesData[] = [
                'id' => $msg->getId(),
                'role' => $msg->getRole(),
                'type' => $msg->getType(),
                'content' => $this->sanitizeMessageContentForToolOutput($msg->getType(), $msg->getContent()),
                'createdAt' => $msg->getCreatedAt()->format('c'),
            ];
        }

        $callerId = $conversation->getInitiatorSpiritId();
        $calleeId = $conversation->getSpiritId();
        $callerName = $this->spiritService->getSpirit($callerId)?->getName() ?? 'Spirit';
        $calleeName = $this->spiritService->getSpirit($calleeId)?->getName() ?? 'Spirit';
        $callerColor = $this->spiritService->getSpiritColor($callerId);
        $calleeColor = $this->spiritService->getSpiritColor($calleeId);

        return [
            'success' => true,
            'conversationId' => $conversationId,
            'title' => $conversation->getTitle(),
            'caller' => ['spiritId' => $callerId, 'name' => $callerName],
            'callee' => ['spiritId' => $calleeId, 'name' => $calleeName],
            'resultType' => $resultType,
            'messages' => $messagesData,
            'messagesCount' => $messageService->countMessagesByConversation($conversationId),
            'createdAt' => $conversation->getCreatedAt()->format('c'),
            'lastInteraction' => $conversation->getLastInteraction()->format('c'),
            '_frontendData' => $this->buildGetConversationBadge(
                $conversation->getTitle(),
                $callerName,
                $callerColor,
                $calleeName,
                $calleeColor,
                $resultType,
                $messagesData
            ),
        ];
    }

    /**
     * Consult a fellow Spirit and return its answer.
     */
    public function spiritCall(array $arguments): array
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
        // spiritCall tool per-Spirit is itself the explicit opt-in. Set s2s.enabled='0'
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

        // Safeguards: depth / cycle / budget. Caps are user-configurable via the
        // spiritCall AI Tool Settings; invalid/empty values fall back to defaults.
        $this->spiritCallContext->configure(
            $this->getIntSetting('s2s.maxCallsPerTurn', SpiritCallContext::MAX_CALLS_PER_TURN),
            $this->getIntSetting('s2s.maxDepth', SpiritCallContext::MAX_DEPTH)
        );
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

            // Run the callee at its model's full output capacity so its answer
            // is never artificially truncated (S2S always returns the full answer).
            $calleeMaxOutput = $this->getIntSetting('s2s.calleeMaxOutputFallback', self::CALLEE_MAX_OUTPUT_FALLBACK);
            try {
                $calleeModelMax = $this->spiritService->getSpiritAiModel($calleeId)?->getMaxOutput();
                if ($calleeModelMax !== null && $calleeModelMax > 0) {
                    $calleeMaxOutput = $calleeModelMax;
                }
            } catch (\Throwable $e) {
                // No model configured / lookup failed — keep the generous fallback.
            }

            $this->spiritCallContext->enter($calleeId);
            try {
                $result = $this->conversationService()->runTurnSync(
                    $conversation->getId(),
                    $callerMessage->getId(),
                    $lang,
                    $calleeMaxOutput,
                    $this->getFloatSetting('s2s.calleeTemperature', self::CALLEE_TEMPERATURE),
                    $this->getFloatSetting('s2s.calleeToolTemperature', self::CALLEE_TOOL_TEMPERATURE)
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
            $this->logger->error('spiritCall failed: {error}', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'The consultation failed: ' . $e->getMessage()];
        }
    }

    /**
     * Read a user-configured S2S setting from the spiritCall AI Tool Settings.
     */
    private function getS2sSetting(string $key): ?string
    {
        $tool = $this->aiToolService->findByName('spiritCall');
        if (!$tool) {
            return null;
        }
        return $this->aiToolSettingsService->getSettingValue($tool->getId(), $key);
    }

    private function getIntSetting(string $key, int $default): int
    {
        $value = $this->getS2sSetting($key);
        $int = ($value !== null && $value !== '' && is_numeric($value)) ? (int) $value : 0;
        return $int > 0 ? $int : $default;
    }

    private function getFloatSetting(string $key, float $default): float
    {
        $value = $this->getS2sSetting($key);
        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }
        return (float) $value;
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
                return ['ok' => false, 'error' => 'No Spirit named "' . $targetName . '" found. Use spiritManage (op: listSpirits) to see who you can consult.'];
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

        return ['ok' => false, 'error' => 'Provide targetSpiritId or targetSpiritName. Use spiritManage (op: listSpirits) to discover fellow Spirits.'];
    }

    /**
     * Expandable badge listing the fellow Spirits discovered by spiritManage (op: listSpirits).
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
     * Extract plain text from a message content array.
     * Content is typically [{type: 'text', text: '...'}, ...].
     */
    private function extractTextFromContent(array $content): string
    {
        $parts = [];
        foreach ($content as $item) {
            if (is_array($item) && ($item['type'] ?? '') === 'text' && isset($item['text'])) {
                $parts[] = (string) $item['text'];
            }
        }
        return implode(' ', $parts);
    }

    /**
     * Frontend badge for listConversations operation.
     */
    private function buildListConversationsBadge(string $spiritName, array $conversations): string
    {
        if ($conversations === []) {
            return '<div class="s2s-list-badge card border-0 bg-dark my-2"><div class="card-body p-2">'
                . '<div class="small text-cyber fw-bold mb-1"><i class="mdi mdi-forum-outline me-1"></i>No S2S conversations for ' . htmlspecialchars($spiritName) . '</div>'
                . '</div></div>';
        }

        $items = '';
        foreach (array_slice($conversations, 0, 10) as $conv) {
            $title = htmlspecialchars($conv['title']);
            $date = htmlspecialchars($conv['date'] ?? '');
            $first = htmlspecialchars(mb_substr($conv['firstSnippet'], 0, 200));
            $last = htmlspecialchars(mb_substr($conv['lastSnippet'], 0, 200));
            $items .= '<div class="small text-muted border-bottom border-secondary py-1">'
                . '<div class="fw-bold text-light">' . $title . ' <span class="opacity-50">(' . $date . ')</span></div>'
                . '<div class="opacity-75">First: ' . $first . '...</div>'
                . '<div class="opacity-75">Last: ' . $last . '...</div>'
                . '</div>';
        }
        $more = count($conversations) > 10 ? '<div class="small text-muted mt-1">… and ' . (count($conversations) - 10) . ' more</div>' : '';

        return '<div class="s2s-list-badge card border-0 bg-dark my-2">'
            . '<div class="card-body p-2">'
            . '<div class="small text-cyber fw-bold mb-1"><i class="mdi mdi-forum-outline me-1"></i>S2S Conversations: ' . htmlspecialchars($spiritName) . ' (' . count($conversations) . ')</div>'
            . '<details><summary class="small opacity-75" style="cursor:pointer;">View conversations</summary>'
            . '<div class="mt-2">' . $items . $more . '</div>'
            . '</details>'
            . '</div></div>';
    }

    /**
     * Frontend badge for getConversation operation — collapsible conversation content.
     */
    private function buildGetConversationBadge(
        string $title,
        string $callerName,
        string $callerColor,
        string $calleeName,
        string $calleeColor,
        string $resultType,
        array $messagesData
    ): string {
        $callerNameHtml = htmlspecialchars($callerName, ENT_QUOTES);
        $calleeNameHtml = htmlspecialchars($calleeName, ENT_QUOTES);
        $callerColorHtml = htmlspecialchars($callerColor, ENT_QUOTES);
        $calleeColorHtml = htmlspecialchars($calleeColor, ENT_QUOTES);
        $titleHtml = htmlspecialchars($title, ENT_QUOTES);

        $callerIcon = '<i class="mdi mdi-ghost me-1" style="color:' . $callerColorHtml . ';"></i>';
        $calleeIcon = '<i class="mdi mdi-ghost me-1" style="color:' . $calleeColorHtml . ';"></i>';

        // Build message rows
        $messageRows = '';
        foreach ($messagesData as $msg) {
            $role = $msg['role'];
            $text = $this->extractTextFromContent($msg['content']);
            $textHtml = nl2br(htmlspecialchars($text, ENT_QUOTES));

            if ($role === 'user') {
                $messageRows .= '<div class="mt-2 small">' . $callerIcon . '<strong>' . $callerNameHtml . ':</strong><br>' . $textHtml . '</div>';
            } elseif ($role === 'assistant') {
                $messageRows .= '<div class="mt-2 small">' . $calleeIcon . '<strong>' . $calleeNameHtml . ':</strong><br>' . $textHtml . '</div>';
            } else {
                $messageRows .= '<div class="mt-2 small opacity-50"><strong>' . htmlspecialchars(ucfirst($role), ENT_QUOTES) . ':</strong><br>' . $textHtml . '</div>';
            }
        }

        if ($messageRows === '') {
            $messageRows = '<div class="mt-2 small opacity-50">(no messages)</div>';
        }

        return '<div class="s2s-consult-badge card border-0 bg-dark my-2">'
            . '<div class="card-body p-2">'
            . '<div class="small text-cyber fw-bold mb-1"><i class="mdi mdi-message-text-outline me-1"></i>' . $titleHtml . '</div>'
            . '<div class="small opacity-75 mb-1">' . $callerNameHtml . ' → ' . $calleeNameHtml . ' · ' . $resultType . ' · ' . count($messagesData) . ' message(s)</div>'
            . '<details>'
            . '<summary class="small opacity-75" style="cursor:pointer;">View conversation</summary>'
            . '<div class="mt-2">' . $messageRows . '</div>'
            . '</details>'
            . '</div></div>';
    }

    /**
     * Strip token-heavy fields from message content before returning it
     * as part of a getConversation tool result.
     *
     * - tool_use:     removes reasoning, reasoning_details, and
     *                 function.arguments from each tool_call
     * - tool_result:  replaces each result's content with a placeholder
     *                 and removes frontendData
     * - other types:  returned as-is
     */
    private function sanitizeMessageContentForToolOutput(string $type, array $content): array
    {
        if ($type === 'tool_use') {
            unset($content['reasoning'], $content['reasoning_details']);
            if (isset($content['tool_calls']) && is_array($content['tool_calls'])) {
                foreach ($content['tool_calls'] as &$tc) {
                    if (isset($tc['function']['arguments'])) {
                        unset($tc['function']['arguments']);
                    }
                }
                unset($tc);
            }
            return $content;
        }

        if ($type === 'tool_result') {
            foreach ($content as &$result) {
                if (is_array($result)) {
                    $result['content'] = '<content-not-included/>';
                    unset($result['frontendData']);
                }
            }
            unset($result);
            return $content;
        }

        return $content;
    }

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
