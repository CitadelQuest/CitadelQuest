<?php

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\SpiritChatTurnService;
use App\Service\SpiritConversationService;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ServiceSubscriberInterface;

/**
 * Background worker that processes a single Spirit Chat turn (user message ->
 * AI response -> full tool-execution loop) for a specific user.
 *
 * Spawned detached from the start-turn HTTP request so the long-running AI calls
 * run with no execution time limit and outside Cloudflare's 100s proxy window
 * (which causes HTTP 524). The browser polls the turn status separately.
 *
 * IMPORTANT: services are fetched lazily from the service-subscriber locator AFTER
 * the security token is set, so the whole dependency graph resolves the target user
 * correctly (many services read security->getUser() at construction time).
 */
#[AsCommand(
    name: 'app:spirit-chat-turn',
    description: 'Process a Spirit Chat turn in the background for a given user',
)]
class SpiritChatTurnCommand extends Command implements ServiceSubscriberInterface
{
    public function __construct(
        private readonly ContainerInterface $container
    ) {
        parent::__construct();
    }

    public static function getSubscribedServices(): array
    {
        return [
            TokenStorageInterface::class,
            UserRepository::class,
            SpiritChatTurnService::class,
            SpiritConversationService::class,
        ];
    }

    protected function configure(): void
    {
        $this
            ->addArgument('userId', InputArgument::REQUIRED, 'Target user id (UUID)')
            ->addArgument('turnJobId', InputArgument::REQUIRED, 'Spirit chat turn job id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // No time limit — this is exactly why the turn runs here and not in a web request.
        @set_time_limit(0);

        $userId = (string) $input->getArgument('userId');
        $turnJobId = (string) $input->getArgument('turnJobId');

        $output->writeln(sprintf('[%s] app:spirit-chat-turn START user=%s job=%s sapi=%s', date('c'), $userId, $turnJobId, PHP_SAPI));

        // 1. Resolve and authenticate the target user BEFORE touching user-scoped services
        $userRepository = $this->container->get(UserRepository::class);
        try {
            $user = $userRepository->find(Uuid::fromString($userId));
        } catch (\Throwable $e) {
            $output->writeln('<error>Invalid user id: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if (!$user) {
            $output->writeln('<error>User not found: ' . $userId . '</error>');
            return Command::FAILURE;
        }

        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $this->container->get(TokenStorageInterface::class)->setToken($token);

        // 2. Now fetch user-scoped services (constructed after auth is set)
        /** @var SpiritChatTurnService $turnService */
        $turnService = $this->container->get(SpiritChatTurnService::class);
        /** @var SpiritConversationService $conversationService */
        $conversationService = $this->container->get(SpiritConversationService::class);

        $turn = $turnService->find($turnJobId);
        if (!$turn) {
            $output->writeln('<error>Turn job not found: ' . $turnJobId . '</error>');
            return Command::FAILURE;
        }

        if ($turn['status'] !== SpiritChatTurnService::STATUS_PENDING) {
            $output->writeln('<comment>Turn job already handled (status: ' . $turn['status'] . ')</comment>');
            return Command::SUCCESS;
        }

        $payload = json_decode($turn['payload'] ?? '{}', true) ?: [];

        // Restore the originating request host/scheme so system-info / prompt building
        // and webhook URL generation match the web context (there is no $_SERVER in CLI).
        if (!empty($payload['host'])) {
            $_SERVER['SERVER_NAME'] = $payload['host'];
            $_SERVER['HTTP_HOST'] = $payload['host'];
        }
        if (!empty($payload['scheme']) && $payload['scheme'] === 'https') {
            $_SERVER['HTTPS'] = 'on';
        }
        if (!empty($payload['port'])) {
            $_SERVER['SERVER_PORT'] = $payload['port'];
        }

        $turnService->markProcessing($turnJobId);

        try {
            $conversationService->runFullTurn(
                $turn['conversation_id'],
                $turn['user_message_id'],
                $payload['lang'] ?? 'English',
                (int) ($payload['maxOutput'] ?? 500),
                (float) ($payload['temperature'] ?? 0.7),
                $payload['cachedSystemPrompt'] ?? null,
                fn (): bool => $turnService->isStopRequested($turnJobId),
                (float) ($payload['toolTemperature'] ?? 0.5),
                $payload['preSendData'] ?? []
            );

            if ($turnService->isStopRequested($turnJobId)) {
                $turnService->markStopped($turnJobId);
            } else {
                $turnService->markCompleted($turnJobId);
            }

            $output->writeln('<info>Turn ' . $turnJobId . ' completed.</info>');
            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $turnService->markFailed($turnJobId, $e->getMessage());
            $output->writeln('<error>Turn ' . $turnJobId . ' failed: ' . $e->getMessage() . '</error>');
            $output->writeln('<error>  at ' . $e->getFile() . ':' . $e->getLine() . '</error>');
            $output->writeln($e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}
