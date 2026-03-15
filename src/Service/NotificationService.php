<?php

namespace App\Service;

use App\Entity\Notification;
use App\SSE\Event;
use App\SSE\EventPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class NotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserDatabaseManager $userDatabaseManager,
        private readonly EventPublisher $eventPublisher
    ) {
    }

    public function createNotification(
        UserInterface $user,
        string $title,
        string $message,
        string $type = 'info',
        ?string $url = null
    ): Notification {
        $notification = new Notification();
        $notification
            ->setTitle($title)
            ->setMessage($message)
            ->setType($type)
            ->setUrl($url);

        // Store in user's database
        $userDb = $this->userDatabaseManager->getDatabaseConnection($user);
        $userDb->executeStatement(
            'INSERT INTO notifications (title, message, type, is_read, url) 
             VALUES (?, ?, ?, ?, ?)',
            [
                $notification->getTitle(),
                $notification->getMessage(),
                $notification->getType(),
                0,
                $notification->getUrl()
            ]
        );
        
        // Set the ID from the last insert
        $notification->setId($userDb->lastInsertId());

        // Emit SSE event with the notification data
        $this->eventPublisher->publish(new Event(
            'notification',
            ['notification' => $notification->toArray()]
        ), $user);

        return $notification;
    }

    public function markAllAsRead(UserInterface $user): void
    {
        $userDb = $this->userDatabaseManager->getDatabaseConnection($user);
        $userDb->executeStatement('UPDATE notifications SET is_read = 1 WHERE is_read = 0');
    }

    public function getUnreadNotifications(UserInterface $user): array
    {
        $userDb = $this->userDatabaseManager->getDatabaseConnection($user);
        $result = $userDb->executeQuery(
            'SELECT * FROM notifications WHERE is_read = 0 ORDER BY created_at DESC'
        );

        return array_map(
            fn(array $row) => $this->createNotificationFromRow($row),
            $result->fetchAllAssociative()
        );
    }

    public function getAllNotifications(UserInterface $user): array
    {
        $userDb = $this->userDatabaseManager->getDatabaseConnection($user);
        $result = $userDb->executeQuery(
            'SELECT * FROM notifications ORDER BY created_at DESC'
        );

        return array_map(
            fn(array $row) => $this->createNotificationFromRow($row),
            $result->fetchAllAssociative()
        );
    }

    public function markAsRead(UserInterface $user, int $notificationId): void
    {
        $userDb = $this->userDatabaseManager->getDatabaseConnection($user);
        $userDb->executeStatement(
            'UPDATE notifications SET is_read = 1 WHERE id = ?',
            [$notificationId]
        );
    }

    public function createNotificationEvent(Notification $notification): Event
    {
        return new Event(
            type: 'notification',
            data: $notification->toArray(),
            id: 'notification_' . $notification->getId()
        );
    }

    private function createNotificationFromRow(array $row): Notification
    {
        $notification = new Notification();
        $notification
            ->setId((int) $row['id'])
            ->setTitle($row['title'])
            ->setMessage($row['message'])
            ->setType($row['type'])
            ->setRead((bool) $row['is_read'])
            ->setUrl($row['url'] ?? null)
            ->setCreatedAt(new \DateTimeImmutable($row['created_at']));

        return $notification;
    }
}
