<?php

namespace App\Service;

use App\Entity\Notification;
use App\SSE\Event;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class NotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserDatabaseManager $userDatabaseManager
    ) {
    }

    public function createNotification(
        UserInterface $user,
        string $title,
        string $message,
        string $type = 'info'
    ): Notification {
        $notification = new Notification();
        $notification
            ->setTitle($title)
            ->setMessage($message)
            ->setType($type);

        // Store in user's database
        $userDb = $this->userDatabaseManager->getDatabaseConnection($user);
        $userDb->executeStatement(
            'INSERT INTO notifications (title, message, type, is_read) 
             VALUES (?, ?, ?, ?)',
            [
                $notification->getTitle(),
                $notification->getMessage(),
                $notification->getType(),
                0
            ]
        );
        
        // Set the ID from the last insert
        $notification->setId($userDb->lastInsertId());

        return $notification;
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
            ->setCreatedAt(new \DateTimeImmutable($row['created_at']));

        return $notification;
    }
}
