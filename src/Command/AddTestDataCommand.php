<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class AddTestDataCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('app:add-test-data')
            ->setDescription('Adds test data to the current user database');
    }

    public function __construct(
        private Connection $connection,
        private ParameterBagInterface $params
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Adding test data...');

        // Get the current user's database path
        $stmt = $this->connection->executeQuery('SELECT username, database_path FROM user LIMIT 1');
        $user = $stmt->fetchAssociative();
        
        if (!$user || !$user['database_path']) {
            $output->writeln('<error>No user found or database path not set</error>');
            return Command::FAILURE;
        }

        // Connect to user's SQLite database
        $userDb = new \SQLite3($user['database_path']);
        
        // Create test tables if they don't exist
        $userDb->exec('
            CREATE TABLE IF NOT EXISTS test_projects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                description TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $userDb->exec('
            CREATE TABLE IF NOT EXISTS test_tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER,
                title TEXT NOT NULL,
                status TEXT DEFAULT "pending",
                due_date DATETIME,
                FOREIGN KEY (project_id) REFERENCES test_projects(id)
            )
        ');

        // Add test projects
        $projects = [
            ['CitadelQuest Development', 'Main development project for CitadelQuest'],
            ['AI Integration', 'Integration with various AI services'],
            ['Security Audit', 'Regular security checks and improvements']
        ];

        foreach ($projects as $project) {
            $stmt = $userDb->prepare('INSERT INTO test_projects (name, description) VALUES (:name, :description)');
            $stmt->bindValue(':name', $project[0], SQLITE3_TEXT);
            $stmt->bindValue(':description', $project[1], SQLITE3_TEXT);
            $stmt->execute();
        }

        // Add test tasks
        $tasks = [
            [1, 'Implement backup system', 'completed', '2025-02-10'],
            [1, 'Add restore functionality', 'completed', '2025-02-15'],
            [1, 'Test backup/restore', 'pending', '2025-02-20'],
            [2, 'Setup Claude API', 'pending', '2025-03-01'],
            [2, 'Implement AI tools', 'pending', '2025-03-15'],
            [3, 'Review encryption', 'pending', '2025-02-28'],
            [3, 'Penetration testing', 'pending', '2025-03-10']
        ];

        foreach ($tasks as $task) {
            $stmt = $userDb->prepare('INSERT INTO test_tasks (project_id, title, status, due_date) VALUES (:project_id, :title, :status, :due_date)');
            $stmt->bindValue(':project_id', $task[0], SQLITE3_INTEGER);
            $stmt->bindValue(':title', $task[1], SQLITE3_TEXT);
            $stmt->bindValue(':status', $task[2], SQLITE3_TEXT);
            $stmt->bindValue(':due_date', $task[3], SQLITE3_TEXT);
            $stmt->execute();
        }

        $userDb->close();

        $output->writeln('Test data added successfully!');
        $output->writeln("Added:\n- 3 test projects\n- 7 test tasks");
        
        return Command::SUCCESS;
    }
}
