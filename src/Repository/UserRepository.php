<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use App\Service\UserDatabaseManager;

class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(
        ManagerRegistry $registry,
        private UserDatabaseManager $userDatabaseManager,
        private ParameterBagInterface $params
    ) {
        parent::__construct($registry, User::class);
    }

    public function save(User $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(User $user, bool $flush = false): void
    {
        // Remove user's database
        $this->userDatabaseManager->deleteUserDatabase($user);
        
        // and sse-{user_id}.db
        $sseDbPath = $this->params->get('kernel.project_dir') . '/var/user_databases/sse-' . $user->getId() . '.db';
        if (file_exists($sseDbPath)) {
            unlink($sseDbPath);
        }
        
        // and dir user_backups/{user_id}/
        $backupDir = $this->params->get('kernel.project_dir') . '/var/user_backups/' . $user->getId();
        if (file_exists($backupDir)) {
            $this->removeDirectory($backupDir);
        }

        $this->getEntityManager()->remove($user);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            $files = scandir($dir);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $path = $dir . '/' . $file;
                if (is_dir($path)) {
                    $this->removeDirectory($path);
                } else {
                    unlink($path);
                }
            }
            rmdir($dir);
        }
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', \get_class($user)));
        }

        $user->setPassword($newHashedPassword);
        $this->save($user, true);
    }

    public function getCQAIGatewayUsername(User $user): string
    {
        return $user->getUsername() . '_' . str_replace('.', '', $_SERVER["SERVER_NAME"]);
    }
}
