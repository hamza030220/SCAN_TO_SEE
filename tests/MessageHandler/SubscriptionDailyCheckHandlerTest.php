<?php

namespace App\Tests\MessageHandler;

use App\Entity\Subscription;
use App\MessageHandler\SubscriptionDailyCheckHandler;
use App\Repository\SubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;

final class SubscriptionDailyCheckHandlerTest extends TestCase
{
    public function testExpiredPaidPeriodsAndGracePeriodsAreClosed(): void
    {
        $periodEnded = (new Subscription())
            ->setStatus(Subscription::STATUS_ACTIVE)
            ->setCurrentPeriodEnd(new \DateTimeImmutable('-1 minute'));
        $graceEnded = (new Subscription())
            ->setStatus(Subscription::STATUS_PAST_DUE)
            ->setPaymentGraceEndsAt(new \DateTimeImmutable('-1 minute'));
        $repository = $this->createMock(SubscriptionRepository::class);
        $repository->method('findExpiredActive')->willReturn([$periodEnded]);
        $repository->method('findExpiredGracePeriods')->willReturn([$graceEnded]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $handler = new SubscriptionDailyCheckHandler(
            $repository,
            $em,
            $this->createMock(MailerInterface::class),
            'noreply@example.com',
            'https://example.com',
        );
        $method = new \ReflectionMethod($handler, 'expireOverdueSubscriptions');

        $method->invoke($handler);

        self::assertSame(Subscription::STATUS_EXPIRED, $periodEnded->getStatus());
        self::assertSame(Subscription::STATUS_EXPIRED, $graceEnded->getStatus());
        self::assertNull($graceEnded->getPaymentGraceEndsAt());
    }
}
