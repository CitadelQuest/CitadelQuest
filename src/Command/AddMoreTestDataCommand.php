<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AddMoreTestDataCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('app:add-more-test-data')
            ->setDescription('Adds additional test data with new tables');
    }

    public function __construct(
        private Connection $connection
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Adding more test data...');

        // Get the current user's database path
        $stmt = $this->connection->executeQuery('SELECT username, database_path FROM user LIMIT 1');
        $user = $stmt->fetchAssociative();
        
        if (!$user || !$user['database_path']) {
            $output->writeln('<error>No user found or database path not set</error>');
            return Command::FAILURE;
        }

        // Connect to user's SQLite database
        $userDb = new \SQLite3($user['database_path']);
        
        // Create new test tables
        $userDb->exec('
            CREATE TABLE IF NOT EXISTS test_contacts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL,
                phone TEXT,
                type TEXT DEFAULT "personal",
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $userDb->exec('
            CREATE TABLE IF NOT EXISTS test_notes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                content TEXT,
                category TEXT DEFAULT "general",
                is_encrypted BOOLEAN DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');

        // Add test contacts
        $contacts = [
            ['John Developer', 'john@dev.local', '+1234567890', 'work'],
            ['Alice Security', 'alice@security.local', '+1987654321', 'work'],
            ['Bob Admin', 'bob@admin.local', '+1122334455', 'work'],
            ['Personal Contact', 'personal@mail.local', '+9988776655', 'personal'],
            ['Emergency Contact', 'emergency@mail.local', '+1199887766', 'emergency']
        ];

        foreach ($contacts as $contact) {
            $stmt = $userDb->prepare('INSERT INTO test_contacts (name, email, phone, type) VALUES (:name, :email, :phone, :type)');
            $stmt->bindValue(':name', $contact[0], SQLITE3_TEXT);
            $stmt->bindValue(':email', $contact[1], SQLITE3_TEXT);
            $stmt->bindValue(':phone', $contact[2], SQLITE3_TEXT);
            $stmt->bindValue(':type', $contact[3], SQLITE3_TEXT);
            $stmt->execute();
        }

        // Add test notes
        $notes = [
            ['Architecture Overview', 'Detailed notes about system architecture...', 'technical', true],
            ['Meeting Minutes', 'Discussion about new features...', 'meetings', false],
            ['Security Protocols', 'List of security measures...', 'security', true],
            ['Development Guidelines', 'Coding standards and practices...', 'technical', false],
            ['API Documentation', 'REST API endpoints and usage...', 'technical', false],
            ['Deployment Checklist', 'Steps for production deployment...', 'operations', false],
            ['Incident Response', 'Emergency procedures...', 'security', true],
            ['Team Contacts', 'List of team members and roles...', 'general', false]
        ];

        foreach ($notes as $note) {
            $stmt = $userDb->prepare('INSERT INTO test_notes (title, content, category, is_encrypted) VALUES (:title, :content, :category, :is_encrypted)');
            $stmt->bindValue(':title', $note[0], SQLITE3_TEXT);
            $stmt->bindValue(':content', $note[1], SQLITE3_TEXT);
            $stmt->bindValue(':category', $note[2], SQLITE3_TEXT);
            $stmt->bindValue(':is_encrypted', $note[3], SQLITE3_INTEGER);
            $stmt->execute();
        }

        // Add more tasks to existing projects
        $moreTasks = [
            [1, 'Review code quality', 'pending', '2025-03-05'],
            [1, 'Update documentation', 'pending', '2025-03-10'],
            [2, 'Test AI responses', 'pending', '2025-03-20'],
            [2, 'Optimize AI performance', 'pending', '2025-03-25'],
            [3, 'Update SSL certificates', 'pending', '2025-03-15'],
            [3, 'Review access logs', 'pending', '2025-03-20']
        ];

        foreach ($moreTasks as $task) {
            $stmt = $userDb->prepare('INSERT INTO test_tasks (project_id, title, status, due_date) VALUES (:project_id, :title, :status, :due_date)');
            $stmt->bindValue(':project_id', $task[0], SQLITE3_INTEGER);
            $stmt->bindValue(':title', $task[1], SQLITE3_TEXT);
            $stmt->bindValue(':status', $task[2], SQLITE3_TEXT);
            $stmt->bindValue(':due_date', $task[3], SQLITE3_TEXT);
            $stmt->execute();
        }

        $userDb->close();

        $output->writeln('Additional test data added successfully!');
        $output->writeln("Added:\n- 5 test contacts\n- 8 test notes\n- 6 more tasks");
        
        return Command::SUCCESS;
    }
}
