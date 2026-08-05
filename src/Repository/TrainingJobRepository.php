<?php

namespace App\Repository;

use App\Entity\TrainingJob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TrainingJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, TrainingJob::class); }

    public function findActive(): ?TrainingJob
    {
        return $this->createQueryBuilder('job')
            ->andWhere('job.status IN (:statuses)')
            ->setParameter('statuses', TrainingJob::ACTIVE_STATUSES)
            ->orderBy('job.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }
}
