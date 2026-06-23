<?php

namespace App\Command;

use App\Entity\MemoryJob;
use App\Repository\UserRepository;
use App\Service\AIToolMemoryService;
use App\Service\CQMemoryPackService;
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
 * Background worker that processes a single Memory Job (extraction or
 * relationship analysis) for a specific user.
 *
 * Spawned detached from the HTTP request that creates the job, so the
 * long-running AI calls run with no execution time limit and outside
 * Cloudflare's 100s proxy window (which causes HTTP 524).
 *
 * The browser polls /api/updates for job status and graph deltas.
 */
#[AsCommand(
    name: 'app:memory-job-worker',
    description: 'Process a Memory Job (extraction or relationship analysis) in the background for a given user',
)]
class MemoryJobWorkerCommand extends Command implements ServiceSubscriberInterface
{
    private const STEP_DELAY_MICROSECONDS = 100_000;

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
            AIToolMemoryService::class,
            CQMemoryPackService::class,
        ];
    }

    protected function configure(): void
    {
        $this
            ->addArgument('userId', InputArgument::REQUIRED, 'Target user id (UUID)')
            ->addArgument('jobId', InputArgument::REQUIRED, 'Memory job id')
            ->addArgument('packProjectId', InputArgument::REQUIRED, 'Pack project id')
            ->addArgument('packPath', InputArgument::REQUIRED, 'Pack path')
            ->addArgument('packName', InputArgument::REQUIRED, 'Pack name')
            ->addArgument('host', InputArgument::OPTIONAL, 'Host for webhook URL construction (CLI context)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        @set_time_limit(0);

        $userId = (string) $input->getArgument('userId');
        $jobId = (string) $input->getArgument('jobId');
        $packProjectId = (string) $input->getArgument('packProjectId');
        $packPath = (string) $input->getArgument('packPath');
        $packName = (string) $input->getArgument('packName');
        $host = (string) $input->getArgument('host');

        // Restore the originating request host so webhook URL construction works
        // in CLI context (there is no $_SERVER['SERVER_NAME'] in CLI).
        if ($host) {
            $_SERVER['SERVER_NAME'] = $host;
            $_SERVER['HTTP_HOST'] = $host;
        }

        $output->writeln(sprintf(
            '[%s] app:memory-job-worker START user=%s job=%s pack=%s/%s sapi=%s',
            date('c'),
            $userId,
            $jobId,
            $packPath,
            $packName,
            PHP_SAPI
        ));

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
        /** @var AIToolMemoryService $aiToolMemoryService */
        $aiToolMemoryService = $this->container->get(AIToolMemoryService::class);
        /** @var CQMemoryPackService $packService */
        $packService = $this->container->get(CQMemoryPackService::class);

        $targetPack = [
            'projectId' => $packProjectId,
            'path' => $packPath,
            'name' => $packName,
        ];

        // 3. Verify the job exists and is in a processable state
        try {
            $packService->open($packProjectId, $packPath, $packName);
        } catch (\Throwable $e) {
            $output->writeln('<error>Cannot open pack: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $job = $packService->findJobById($jobId);
        if (!$job) {
            $packService->close();
            $output->writeln('<error>Job not found: ' . $jobId . '</error>');
            return Command::FAILURE;
        }

        if (in_array($job->getStatus(), [MemoryJob::STATUS_COMPLETED, MemoryJob::STATUS_FAILED, MemoryJob::STATUS_CANCELLED], true)) {
            $packService->close();
            $output->writeln('<comment>Job already handled (status: ' . $job->getStatus() . ')</comment>');
            return Command::SUCCESS;
        }

        $jobType = $job->getType();
        $packService->close();

        $output->writeln(sprintf('Processing job type=%s', $jobType));

        // Clear any stale step lock from a previous crashed worker
        try {
            $packService->open($packProjectId, $packPath, $packName);
            $freshJob = $packService->findJobById($jobId);
            if ($freshJob) {
                $freshPayload = $freshJob->getPayload();
                $staleLock = $freshPayload['step_locked_at'] ?? 0;
                if ($staleLock > 0 && (time() - $staleLock >= 240)) {
                    unset($freshPayload['step_locked_at'], $freshPayload['step_lock_token']);
                    $packService->updateJobPayload($jobId, $freshPayload);
                    $output->writeln('<comment>Cleared stale step lock from previous worker.</comment>');
                }
            }
            $packService->close();
        } catch (\Throwable $e) {
            // Non-fatal
        }

        // 4. Process all steps in a loop until the job is complete or fails
        try {
            $maxIterations = 500;
            $iteration = 0;
            $lastProgress = -1;
            $lockContentionRetries = 0;
            $maxLockRetries = 5;

            while ($iteration < $maxIterations) {
                $iteration++;

                // Check if the job is still in a processable state
                $packService->open($packProjectId, $packPath, $packName);
                $currentJob = $packService->findJobById($jobId);
                if (!$currentJob || in_array($currentJob->getStatus(), [MemoryJob::STATUS_COMPLETED, MemoryJob::STATUS_FAILED, MemoryJob::STATUS_CANCELLED], true)) {
                    $packService->close();
                    $output->writeln('<comment>Job already completed/failed by another worker. Exiting.</comment>');
                    return Command::SUCCESS;
                }
                $currentProgress = $currentJob->getProgress();
                $currentPayload = $currentJob->getPayload();
                $packService->close();

                // Check if another worker is actively holding the step lock
                $stepLockedAt = $currentPayload['step_locked_at'] ?? 0;
                if ($stepLockedAt > 0 && (time() - $stepLockedAt < 240)) {
                    $lockContentionRetries++;
                    if ($lockContentionRetries >= $maxLockRetries) {
                        $output->writeln('<comment>Another worker is actively processing this job. Exiting to avoid duplication.</comment>');
                        return Command::SUCCESS;
                    }
                    $output->writeln(sprintf(
                        '<comment>Step locked by another worker (retry %d/%d), waiting 10s...</comment>',
                        $lockContentionRetries,
                        $maxLockRetries
                    ));
                    sleep(10);
                    continue;
                }

                if ($jobType === MemoryJob::TYPE_EXTRACT_RECURSIVE) {
                    $isComplete = $aiToolMemoryService->processPackExtractionJobStep($targetPack, $jobId);
                } elseif ($jobType === MemoryJob::TYPE_ANALYZE_RELATIONSHIPS) {
                    $isComplete = $aiToolMemoryService->processPackRelationshipAnalysisJobStep($targetPack, $jobId);
                } else {
                    $output->writeln('<error>Unknown job type: ' . $jobType . '</error>');
                    return Command::FAILURE;
                }

                // Heartbeat: update worker_spawned_at so UpdatesService stale-job
                // fallback doesn't re-spawn a duplicate worker during long jobs.
                try {
                    $packService->open($packProjectId, $packPath, $packName);
                    $heartbeatJob = $packService->findJobById($jobId);
                    if ($heartbeatJob && !in_array($heartbeatJob->getStatus(), [MemoryJob::STATUS_COMPLETED, MemoryJob::STATUS_FAILED, MemoryJob::STATUS_CANCELLED], true)) {
                        $heartbeatPayload = $heartbeatJob->getPayload();
                        $heartbeatPayload['worker_spawned_at'] = time();
                        $packService->updateJobPayload($jobId, $heartbeatPayload);
                    }
                    $packService->close();
                } catch (\Throwable $e) {
                    // Non-fatal — heartbeat failure shouldn't kill the worker
                }

                // Check if progress was made
                if ($currentProgress !== $lastProgress) {
                    $lastProgress = $currentProgress;
                    $lockContentionRetries = 0;
                }

                if ($isComplete) {
                    $output->writeln(sprintf(
                        '<info>Job %s completed after %d steps.</info>',
                        $jobId,
                        $iteration
                    ));

                    // Check if the extraction job spawned a relationship analysis job
                    // If so, process that too in the same worker
                    if ($jobType === MemoryJob::TYPE_EXTRACT_RECURSIVE) {
                        $packService->open($packProjectId, $packPath, $packName);
                        $jobsToProcess = $packService->getJobsToProcess(1);
                        $packService->close();

                        if (!empty($jobsToProcess)) {
                            $nextJob = $jobsToProcess[0];
                            if ($nextJob->getType() === MemoryJob::TYPE_ANALYZE_RELATIONSHIPS) {
                                $nextJobId = $nextJob->getId();
                                $output->writeln(sprintf('Continuing with analysis job %s', $nextJobId));
                                $jobType = MemoryJob::TYPE_ANALYZE_RELATIONSHIPS;
                                $jobId = $nextJobId;
                                $lastProgress = -1;
                                $lockContentionRetries = 0;
                                continue;
                            }
                        }
                    }

                    break;
                }

                // Small delay between steps to let UI polling catch up
                usleep(self::STEP_DELAY_MICROSECONDS);
            }

            if ($iteration >= $maxIterations) {
                $output->writeln('<comment>Job reached max iterations limit (' . $maxIterations . '), stopping.</comment>');
            }

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $output->writeln('<error>Job ' . $jobId . ' failed: ' . $e->getMessage() . '</error>');
            $output->writeln('<error>  at ' . $e->getFile() . ':' . $e->getLine() . '</error>');
            $output->writeln($e->getTraceAsString());

            try {
                $packService->open($packProjectId, $packPath, $packName);
                $packService->failJob($jobId, $e->getMessage());
                $packService->close();
            } catch (\Exception $e2) {
                // Ignore
            }

            return Command::FAILURE;
        }
    }
}
