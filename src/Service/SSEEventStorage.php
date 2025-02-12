<?php

namespace App\Service;

use App\SSE\Event;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;

class SSEEventStorage
{
    private Connection $connection;
    private const DB_PATH = 'var/sse.db';
    private string $dbPath;
    
    public function __construct(
        ParameterBagInterface $params,
        Filesystem $filesystem
    ) {
        $this->dbPath = $params->get('kernel.project_dir') . '/' . self::DB_PATH;
        $this->ensureDatabase($this->dbPath, $filesystem);
        
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $this->dbPath
        ]);
    }
    
    private function ensureDatabase(string $dbPath, Filesystem $filesystem): void
    {
        $filesystem->mkdir(dirname($dbPath));
        
        if (!file_exists($dbPath)) {
            $db = new \SQLite3($dbPath);
            $db->exec('
                CREATE TABLE events (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    type VARCHAR(255) NOT NULL,
                    data TEXT NOT NULL,
                    event_id VARCHAR(255),
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ');
            $db->close();
        }
    }
    
    public function storeEvent(Event $event): void
    {
        $this->connection->executeStatement(
            'INSERT INTO events (type, data, event_id) VALUES (?, ?, ?)',
            [
                $event->getType(),
                json_encode($event->getData()),
                $event->getId()
            ]
        );
        
        // Clean up old events (keep last 24 hours)
        $this->connection->executeStatement(
            'DELETE FROM events WHERE created_at < datetime("now", "-1 day")'
        );
    }
    
    public function getAndClearEvents(): array
    {
        $events = [];
        
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM events ORDER BY id ASC'
        );
        
        foreach ($rows as $row) {
            $events[] = new Event(
                $row['type'],
                json_decode($row['data'], true),
                $row['event_id']
            );
        }
        
        if (!empty($events)) {
            $this->connection->executeStatement('DELETE FROM events');
        }
        
        return $events;
    }
}
