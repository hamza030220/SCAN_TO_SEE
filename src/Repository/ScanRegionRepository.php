<?php

namespace App\Repository;

use App\Entity\ScanRegion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ScanRegionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScanRegion::class);
    }

    public function trainingStatistics(): array
    {
        return $this->createQueryBuilder('region')->select('COUNT(region.id) total')
            ->addSelect('SUM(CASE WHEN region.reviewOutcome = :accepted THEN 1 ELSE 0 END) accepted')
            ->addSelect('SUM(CASE WHEN region.reviewOutcome = :modified THEN 1 ELSE 0 END) modified')
            ->addSelect('SUM(CASE WHEN region.reviewOutcome = :deleted THEN 1 ELSE 0 END) deleted')
            ->addSelect('SUM(CASE WHEN region.excludedFromTraining = true THEN 1 ELSE 0 END) excluded')
            ->addSelect('AVG(region.confidence) averageConfidence')
            ->setParameter('accepted', 'accepted')->setParameter('modified', 'modified')->setParameter('deleted', 'deleted')
            ->getQuery()->getSingleResult();
    }

    public function findReviewPage(int $page, int $perPage): array
    {
        return $this->createQueryBuilder('region')->addSelect('scan')->join('region.scan', 'scan')
            ->orderBy('region.correctedAt', 'DESC')->addOrderBy('region.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery()->getResult();
    }

    public function countTrainingEligible(): int
    {
        return (int) $this->eligibleQuery()->select('COUNT(region.id)')->getQuery()->getSingleScalarResult();
    }

    public function findTrainingEligible(): array
    {
        return $this->eligibleQuery()->addSelect('scan')->orderBy('scan.createdAt', 'ASC')
            ->addOrderBy('region.boxId', 'ASC')->getQuery()->getResult();
    }

    private function eligibleQuery(): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('region')->join('region.scan', 'scan')
            ->andWhere('scan.status = :reviewed')->andWhere('region.reviewOutcome IN (:outcomes)')
            ->andWhere('region.correctedText IS NOT NULL')->andWhere('region.correctedText != :empty')
            ->andWhere('region.cropUrl IS NOT NULL')->andWhere('region.excludedFromTraining = false')
            ->setParameter('reviewed', 'reviewed')->setParameter('outcomes', ['accepted', 'modified'])->setParameter('empty', '');
    }
}
