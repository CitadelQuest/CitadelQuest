<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ShowDatabaseContentCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('app:show-db-content')
            ->setDescription('Shows current database content');
    }

    public function __construct(
        private Connection $connection
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Get the current user's database path
        $stmt = $this->connection->executeQuery('SELECT username, database_path FROM user LIMIT 1');
        $user = $stmt->fetchAssociative();
        
        if (!$user || !$user['database_path']) {
            $output->writeln('<error>No user found or database path not set</error>');
            return Command::FAILURE;
        }

        $output->writeln("\nReading database: " . $user['database_path']);
        
        // Connect to user's SQLite database
        $userDb = new \SQLite3($user['database_path']);
        
        // Get list of tables
        $tables = [];
        $result = $userDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $tables[] = $row['name'];
        }

        foreach ($tables as $table) {
            $output->writeln("\n=== Table: $table ===");
            
            // Get column names
            $columns = [];
            $columnResult = $userDb->query("PRAGMA table_info(" . $table . ")");
            while ($columnRow = $columnResult->fetchArray(SQLITE3_ASSOC)) {
                $columns[] = $columnRow['name'];
            }
            
            // Get row count
            $countResult = $userDb->query("SELECT COUNT(*) as count FROM " . $table);
            $count = $countResult->fetchArray(SQLITE3_ASSOC)['count'];
            
            $output->writeln("Columns: " . implode(", ", $columns));
            $output->writeln("Row count: " . $count);
            
            // Show all rows for small tables
            if ($count > 0) {
                $output->writeln("\nAll rows:");
                $dataResult = $userDb->query("SELECT * FROM " . $table);
                while ($row = $dataResult->fetchArray(SQLITE3_ASSOC)) {
                    $output->writeln(json_encode($row, JSON_PRETTY_PRINT));
                }
            }
        }

        $userDb->close();
        return Command::SUCCESS;
    }
}
