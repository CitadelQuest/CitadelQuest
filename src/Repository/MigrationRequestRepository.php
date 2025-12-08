<?php

namespace App\Repository;

use App\Entity\MigrationRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<MigrationRequest>
 */
class MigrationRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
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
        return $this->createQueryBuilder('m')
            ->where('m.userId = :userId')
            ->andWhere('m.direction = :direction')
            ->andWhere('m.status NOT IN (:completedStatuses)')
            ->setParameter('userId', $userId)
            ->setParameter('direction', MigrationRequest::DIRECTION_OUTGOING)
            ->setParameter('completedStatuses', [
                MigrationRequest::STATUS_COMPLETED,
                MigrationRequest::STATUS_FAILED,
                MigrationRequest::STATUS_REJECTED
            ])
            ->getQuery()
            ->getOneOrNullResult();
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
