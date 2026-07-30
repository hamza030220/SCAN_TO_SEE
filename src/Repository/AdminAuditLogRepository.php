<?php

namespace App\Repository;

use App\Entity\AdminAuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AdminAuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminAuditLog::class);
    }

    /** @return AdminAuditLog[] */
    public function findPage(int $page, int $perPage = 50): array
    {
        return $this->createQueryBuilder('log')
            ->leftJoin('log.actor', 'actor')
            ->addSelect('actor')
            ->orderBy('log.createdAt', 'DESC')
            ->setFirstResult((max(1, $page) - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }
}
