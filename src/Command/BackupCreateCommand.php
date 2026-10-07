<?php

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\BackupJobService;
use App\Service\BackupManager;
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
 * Background worker that creates one .citadel backup for a specific user.
 *
 * Spawned detached from the /backup/create HTTP request so the long zip/verify
 * work runs with no execution time limit and outside Cloudflare's 100s proxy
 * window (which causes HTTP 524). The browser polls the backup job status
 * separately.
 *
 * IMPORTANT: services are fetched lazily from the service-subscriber locator AFTER
 * the security token is set, so the whole dependency graph resolves the target user
 * correctly (many services read security->getUser() at construction time).
 */
#[AsCommand(
    name: 'app:backup-create',
    description: 'Create a .citadel backup in the background for a given user',
)]
class BackupCreateCommand extends Command implements ServiceSubscriberInterface
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
            BackupJobService::class,
            BackupManager::class,
        ];
    }

    protected function configure(): void
    {
        $this
            ->addArgument('userId', InputArgument::REQUIRED, 'Target user id (UUID)')
            ->addArgument('jobId', InputArgument::REQUIRED, 'Backup job id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // No time limit — this is exactly why the backup runs here and not in a web request.
        @set_time_limit(0);

        $userId = (string) $input->getArgument('userId');
        $jobId = (string) $input->getArgument('jobId');

        $output->writeln(sprintf('[%s] app:backup-create START user=%s job=%s sapi=%s', date('c'), $userId, $jobId, PHP_SAPI));

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
        /** @var BackupJobService $jobService */
        $jobService = $this->container->get(BackupJobService::class);
        /** @var BackupManager $backupManager */
        $backupManager = $this->container->get(BackupManager::class);

        $job = $jobService->find($jobId);
        if (!$job) {
            $output->writeln('<error>Backup job not found: ' . $jobId . '</error>');
            return Command::FAILURE;
        }

        if ($job['status'] !== BackupJobService::STATUS_PENDING) {
            $output->writeln('<comment>Backup job already handled (status: ' . $job['status'] . ')</comment>');
            return Command::SUCCESS;
        }

        $jobService->markProcessing($jobId);

        try {
            $backupPath = $backupManager->createBackup($user);
            $jobService->markCompleted($jobId, basename($backupPath), (int) filesize($backupPath));

            $output->writeln('<info>Backup ' . $jobId . ' completed: ' . basename($backupPath) . '</info>');
            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $jobService->markFailed($jobId, $e->getMessage());
            $output->writeln('<error>Backup ' . $jobId . ' failed: ' . $e->getMessage() . '</error>');
            $output->writeln('<error>  at ' . $e->getFile() . ':' . $e->getLine() . '</error>');
            $output->writeln($e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}
