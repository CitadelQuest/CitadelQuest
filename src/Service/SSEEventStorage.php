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
    
    /**
     * Constructor
     */
    public function __construct(
        private ParameterBagInterface $params,
        private Filesystem $filesystem
    ) {
        $this->dbPath = $this->params->get('kernel.project_dir') . '/' . self::DB_PATH;
        $this->ensureDatabase();
        
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $this->dbPath
        ]);
    }
    
    /**
     * Ensure the database exists and create it if it doesn't
     */
    private function ensureDatabase(): void
    {
        $this->filesystem->mkdir(dirname($this->dbPath));
        
        if (!file_exists($this->dbPath)) {
            $db = new \SQLite3($this->dbPath);
            $db->exec('
                CREATE TABLE events (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    type VARCHAR(255) NOT NULL,
                    data TEXT NOT NULL,
                    event_id VARCHAR(255),
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX idx_events_event_id ON events(event_id);
                CREATE INDEX idx_events_created_at ON events(created_at);

                CREATE TABLE windows (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    window_id VARCHAR(255) NOT NULL UNIQUE,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX idx_windows_window_id ON windows(window_id);
            ');
            $db->close();
        }
    }
    
    /**
     * Store an event in the database
     */
    public function storeEvent(Event $event): void
    {
        try {
            // Store the window ID in the database
            $windowId = $event->getId();
            //$this->storeWindow($windowId);

            // Get all windows
            $windows = $this->connection->fetchAllAssociative('SELECT window_id FROM windows');
        
            // Prepare bulk insert
            $query = 'INSERT INTO events (type, data, event_id) VALUES ';
            $params = [];
            $placeholders = [];
        
            foreach ($windows as $i => $window) {
                $placeholders[] = "(?, ?, ?)";
                $params[] = $event->getType();
                $params[] = json_encode($event->getData());
                $params[] = $window['window_id'];
            }
        
            if (!empty($placeholders)) {
                $query .= implode(', ', $placeholders);
                $this->connection->executeStatement($query, $params);
            }
        
            // Clean up old events (keep last 1 day) - for this window
            $this->connection->executeStatement(
                'DELETE FROM events WHERE created_at < datetime("now", "-1 day")'
            );
        } catch (\Exception $e) {
            // ignore
        }
    }
    
    /**
     * Get and clear events for a specific window
     */
    public function getAndClearEvents($windowId): array
    {
        try {
            $events = [];
            
            $rows = $this->connection->fetchAllAssociative(
                'SELECT * FROM events WHERE event_id = ? ORDER BY id ASC',
                [$windowId]
            );
            
            foreach ($rows as $row) {
                $events[] = new Event(
                    $row['type'],
                    json_decode($row['data'], true),
                    $row['event_id']
                );
            }
            
            if (!empty($events)) {
                $this->clearEvents($windowId);
            }

            // Update last active timestamp
            $this->connection->executeStatement(
                'UPDATE windows SET updated_at = CURRENT_TIMESTAMP WHERE window_id = ?',
                [$windowId]
            );
            
            // Clear old disconnected windows (keep last 20 minutes)
            $inactiveWindows = $this->connection->fetchAllAssociative(
                'SELECT window_id FROM windows WHERE updated_at < datetime("now", "-20 minutes")'
            );
            if (!empty($inactiveWindows)) {
                foreach ($inactiveWindows as $window) {
                    $this->clearWindow($window['window_id']);
                }
            }
            
            return $events;
        } catch (\Exception $e) {
            return [];
        }   
    }

    /**
     * Clear events for a specific window
     */
    public function clearEvents($windowId): void
    {
        try {
            $this->connection->executeStatement(
                'DELETE FROM events WHERE event_id = ?',
                [$windowId]
            );
        } catch (\Exception $e) {
            // ignore
        }
    }

    /**
     * Store a window ID in the database if it doesn't exist
     */
    public function storeWindow($windowId): void
    {
        try {
            $this->connection->executeStatement(
                'INSERT INTO windows (window_id, created_at, updated_at) VALUES (?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
                [$windowId]
            );
        } catch (\Exception $e) {
            // ignore
        }
    }
    
    /**
     * Clear all windows from the database
     */
    public function clearWindow($windowId): void
    {
        try {
            $this->connection->executeStatement('DELETE FROM windows WHERE window_id = ?', [$windowId]);
        } catch (\Exception $e) {
            // ignore
        }
        $this->clearEvents($windowId);
    }


    /**
     * Health check
     */
    public function healthCheck(): array
    {
        try {
            return [
                'status' => 'ok',
                'connections' => $this->connection->fetchOne('SELECT COUNT(*) FROM windows'),
                'pending_events' => $this->connection->fetchOne('SELECT COUNT(*) FROM events'),
                'timestamp' => new \DateTime()
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Clear database
     */
    public function clearDatabase(): void
    {
        try {
            // delete db file
            @unlink($this->dbPath);

            // reinitialize
            $this->ensureDatabase();
            
            $this->connection = DriverManager::getConnection([
                'driver' => 'pdo_sqlite',
                'path' => $this->dbPath
            ]);
        } catch (\Exception $e) {
            // ignore
        }
    }

    public function getWindows(): array
    {
        try {
            return $this->connection->fetchAllAssociative('SELECT * FROM windows LIMIT 666');
        } catch (\Exception $e) {
            return [];
        }
    }
}
