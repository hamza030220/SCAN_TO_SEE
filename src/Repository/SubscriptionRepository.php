<?php

namespace App\Repository;

use App\Entity\Subscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    /** Find subscriptions expiring within the next $days days that haven't had a reminder sent */
    public function findExpiringIn(int $days): array
    {
        $from = new \DateTimeImmutable('today');
        $to   = new \DateTimeImmutable("+{$days} days 23:59:59");

        return $this->createQueryBuilder('s')
            ->where('s.status = :active')
            ->andWhere('s.currentPeriodEnd >= :from')
            ->andWhere('s.currentPeriodEnd <= :to')
            ->andWhere('s.cancelAtPeriodEnd = true')
            ->andWhere('s.expiryReminderSent = false')
            ->setParameter('active', Subscription::STATUS_ACTIVE)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();
    }

    /** Find all active subscriptions whose period has ended (need to be expired) */
    public function findExpiredActive(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.status = :active')
            ->andWhere('s.currentPeriodEnd < :now')
            ->setParameter('active', Subscription::STATUS_ACTIVE)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    /** Find payment-retry grace periods that have now elapsed. */
    public function findExpiredGracePeriods(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.status = :pastDue')
            ->andWhere('s.paymentGraceEndsAt IS NOT NULL')
            ->andWhere('s.paymentGraceEndsAt < :now')
            ->setParameter('pastDue', Subscription::STATUS_PAST_DUE)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }
}
