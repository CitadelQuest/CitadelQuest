<?php

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\MaintenanceJobService;
use App\Service\UserDatabaseOptimizer;
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
 * Background worker that optimizes one user database (trims stored AI payloads,
 * drops orphaned spirit messages, runs VACUUM) for a specific user.
 *
 * Spawned detached from the /api/database/vacuum and
 * /administration/user/{id}/optimize-database HTTP requests so the long VACUUM
 * runs with no execution time limit and outside Cloudflare's 100s proxy window
 * (which causes HTTP 524). The browser polls the maintenance job status separately.
 *
 * IMPORTANT: services are fetched lazily from the service-subscriber locator AFTER
 * the security token is set, so the whole dependency graph resolves the target user
 * correctly (many services read security->getUser() at construction time).
 */
#[AsCommand(
    name: 'app:db-optimize',
    description: 'Optimize a user database in the background for a given user',
)]
class DbOptimizeCommand extends Command implements ServiceSubscriberInterface
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
            MaintenanceJobService::class,
            UserDatabaseOptimizer::class,
        ];
    }

    protected function configure(): void
    {
        $this
            ->addArgument('userId', InputArgument::REQUIRED, 'Target user id (UUID)')
            ->addArgument('jobId', InputArgument::REQUIRED, 'Maintenance job id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // No time limit — this is exactly why the optimization runs here and not in a web request.
        @set_time_limit(0);

        $userId = (string) $input->getArgument('userId');
        $jobId = (string) $input->getArgument('jobId');

        $output->writeln(sprintf('[%s] app:db-optimize START user=%s job=%s sapi=%s', date('c'), $userId, $jobId, PHP_SAPI));

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
        /** @var MaintenanceJobService $jobService */
        $jobService = $this->container->get(MaintenanceJobService::class);
        /** @var UserDatabaseOptimizer $optimizer */
        $optimizer = $this->container->get(UserDatabaseOptimizer::class);

        $job = $jobService->find($jobId, $user);
        if (!$job) {
            $output->writeln('<error>Maintenance job not found: ' . $jobId . '</error>');
            return Command::FAILURE;
        }

        if ($job['type'] !== MaintenanceJobService::TYPE_DB_OPTIMIZE) {
            $output->writeln('<error>Unexpected job type: ' . $job['type'] . '</error>');
            return Command::FAILURE;
        }

        if ($job['status'] !== MaintenanceJobService::STATUS_PENDING) {
            $output->writeln('<comment>Job already handled (status: ' . $job['status'] . ')</comment>');
            return Command::SUCCESS;
        }

        $jobService->markProcessing($jobId, $user);

        try {
            $stats = $optimizer->optimize($user);
            $jobService->markCompleted($jobId, $stats, $user);

            $output->writeln('<info>Optimize ' . $jobId . ' completed: saved ' . $stats['space_saved'] . '</info>');
            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $jobService->markFailed($jobId, $e->getMessage(), $user);
            $output->writeln('<error>Optimize ' . $jobId . ' failed: ' . $e->getMessage() . '</error>');
            $output->writeln('<error>  at ' . $e->getFile() . ':' . $e->getLine() . '</error>');
            $output->writeln($e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}
