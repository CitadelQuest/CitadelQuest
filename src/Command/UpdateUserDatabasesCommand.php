<?php

namespace App\Command;

use App\Service\UserDatabaseManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:update-user-databases',
    description: 'Updates schema of all user databases',
)]
class UpdateUserDatabasesCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserDatabaseManager $userDatabaseManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $users = $this->entityManager->getRepository('App\Entity\User')->findAll();

        foreach ($users as $user) {
            try {
                $this->userDatabaseManager->updateDatabaseSchema($user);
                $io->success(sprintf('Updated database schema for user: %s', $user->getUserIdentifier()));
            } catch (\Exception $e) {
                $io->error(sprintf(
                    'Failed to update database schema for user %s: %s',
                    $user->getUserIdentifier(),
                    $e->getMessage()
                ));
            }
        }

        return Command::SUCCESS;
    }
}
