<?php

namespace App\Repository;

use App\Entity\MigrationRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<MigrationRequest>
 */
class MigrationRequestRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private LoggerInterface $logger
    ) {
        parent::__construct($registry, MigrationRequest::class);
    }

    public function save(MigrationRequest $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(MigrationRequest $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find pending outgoing migration for a user
     */
    public function findPendingOutgoingForUser(Uuid $userId): ?MigrationRequest
    {
        // DEBUG: Log the userId we're searching for
        $this->logger->error('MigrationRequestRepository::findPendingOutgoingForUser', [
            'userId_string' => (string)$userId,
        ]);
        
        // Try using Doctrine's findBy which handles UUID type automatically
        $allForUser = $this->findBy(['userId' => $userId]);
        
        $this->logger->error('MigrationRequestRepository - findBy result', [
            'found_count' => count($allForUser),
        ]);
        
        foreach ($allForUser as $mr) {
            $this->logger->error('MigrationRequestRepository - found record', [
                'id' => (string)$mr->getId(),
                'userId' => (string)$mr->getUserId(),
                'direction' => $mr->getDirection(),
                'status' => $mr->getStatus(),
            ]);
        }
        
        if (count($allForUser) === 0) {
            // Fallback: fetch all records to compare UUIDs
            $allRecords = $this->findAll();
            $this->logger->error('MigrationRequestRepository - total records in table', [
                'count' => count($allRecords),
            ]);
            
            foreach (array_slice($allRecords, 0, 5) as $mr) {
                $this->logger->error('MigrationRequestRepository - DB record', [
                    'id' => (string)$mr->getId(),
                    'db_userId' => (string)$mr->getUserId(),
                    'username' => $mr->getUsername(),
                    'direction' => $mr->getDirection(),
                ]);
            }
        }
        
        // Filter from the results we already have
        foreach ($allForUser as $mr) {
            if ($mr->getDirection() === MigrationRequest::DIRECTION_OUTGOING &&
                !in_array($mr->getStatus(), [
                    MigrationRequest::STATUS_COMPLETED,
                    MigrationRequest::STATUS_FAILED,
                    MigrationRequest::STATUS_REJECTED
                ])) {
                return $mr;
            }
        }
        
        return null;
    }

    /**
     * Find all pending incoming migration requests (for admin dashboard)
     */
    public function findPendingIncoming(): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.direction = :direction')
            ->andWhere('m.status = :status')
            ->setParameter('direction', MigrationRequest::DIRECTION_INCOMING)
            ->setParameter('status', MigrationRequest::STATUS_PENDING)
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all incoming migration requests (for admin dashboard)
     */
    public function findAllIncoming(): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.direction = :direction')
            ->setParameter('direction', MigrationRequest::DIRECTION_INCOMING)
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find migration request by token
     */
    public function findByToken(string $token): ?MigrationRequest
    {
        return $this->createQueryBuilder('m')
            ->where('m.migrationToken = :token')
            ->setParameter('token', $token)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find migration request by user ID and source domain (for incoming requests)
     */
    public function findByUserIdAndSourceDomain(string $userId, string $sourceDomain): ?MigrationRequest
    {
        return $this->createQueryBuilder('m')
            ->where('m.userId = :userId')
            ->andWhere('m.sourceDomain = :sourceDomain')
            ->andWhere('m.direction = :direction')
            ->andWhere('m.status NOT IN (:completedStatuses)')
            ->setParameter('userId', Uuid::fromString($userId))
            ->setParameter('sourceDomain', $sourceDomain)
            ->setParameter('direction', MigrationRequest::DIRECTION_INCOMING)
            ->setParameter('completedStatuses', [
                MigrationRequest::STATUS_COMPLETED,
                MigrationRequest::STATUS_FAILED,
                MigrationRequest::STATUS_REJECTED
            ])
            ->getQuery()
            ->getOneOrNullResult();
    }
}
