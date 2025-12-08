<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsCommand(
    name: 'app:run-main-db-migrations',
    description: 'Runs pending migrations for main.db (same logic as build-release.sh)',
)]
class RunMainDbMigrationsCommand extends Command
{
    public function __construct(
        private ParameterBagInterface $params
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Running main.db migrations');

        $projectDir = $this->params->get('kernel.project_dir');
        $dbPath = $projectDir . '/var/main.db';
        $migrationsDir = $projectDir . '/migrations';

        if (!file_exists($dbPath)) {
            $io->error('Main database not found at: ' . $dbPath);
            return Command::FAILURE;
        }

        if (!is_dir($migrationsDir)) {
            $io->error('Migrations directory not found at: ' . $migrationsDir);
            return Command::FAILURE;
        }

        try {
            // Connect to main database
            $mainDb = new \PDO('sqlite:' . $dbPath);
            $mainDb->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            // Ensure doctrine_migration_versions table exists
            $mainDb->exec('CREATE TABLE IF NOT EXISTS doctrine_migration_versions (
                version VARCHAR(191) NOT NULL PRIMARY KEY,
                executed_at DATETIME DEFAULT NULL,
                execution_time INTEGER DEFAULT NULL
            )');

            // Get list of applied migrations
            $appliedMigrations = [];
            $stmt = $mainDb->query('SELECT version FROM doctrine_migration_versions ORDER BY version ASC');
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $appliedMigrations[$row['version']] = true;
            }

            $io->info('Found ' . count($appliedMigrations) . ' already applied migrations');

            // Scan migrations directory
            $migrations = [];
            foreach (new \DirectoryIterator($migrationsDir) as $file) {
                if ($file->isDot() || $file->isDir()) continue;
                
                if (preg_match('/^Version(\d+)\.php$/', $file->getFilename(), $matches)) {
                    $version = $matches[1];
                    $migrations[$version] = $file->getPathname();
                }
            }

            // Sort migrations by version
            ksort($migrations);

            $newMigrations = 0;

            // Run new migrations
            foreach ($migrations as $version => $file) {
                $migrationVersion = 'DoctrineMigrations\\Version' . $version;
                
                // Skip if already applied
                if (isset($appliedMigrations[$migrationVersion])) {
                    continue;
                }

                $io->text("Running migration: Version{$version}");

                // Include and instantiate migration class
                require_once $file;
                $migration = new $migrationVersion();

                // Start transaction
                $mainDb->beginTransaction();

                try {
                    $startTime = microtime(true);
                    
                    // Run migration with PDO connection
                    $migration->up($mainDb);
                    
                    $executionTime = round((microtime(true) - $startTime) * 1000);

                    // Record migration
                    $stmt = $mainDb->prepare(
                        'INSERT INTO doctrine_migration_versions (version, executed_at, execution_time) '
                        . 'VALUES (?, datetime("now"), ?)'
                    );
                    $stmt->execute([$migrationVersion, $executionTime]);

                    // Commit transaction
                    $mainDb->commit();

                    $io->success("Migration Version{$version} completed ({$executionTime}ms)");
                    $newMigrations++;

                } catch (\Exception $e) {
                    // Rollback on error
                    $mainDb->rollBack();
                    $io->error("Migration Version{$version} failed: " . $e->getMessage());
                    return Command::FAILURE;
                }
            }

            if ($newMigrations === 0) {
                $io->success('No new migrations to run');
            } else {
                $io->success("Completed {$newMigrations} new migration(s)");
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
