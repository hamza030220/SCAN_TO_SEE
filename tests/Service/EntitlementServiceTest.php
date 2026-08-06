<?php

namespace App\Tests\Service;

use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Service\EntitlementService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class EntitlementServiceTest extends TestCase
{
    public function testTrialStartsWithFiveDaysAndThreeAiUses(): void
    {
        $service = $this->service();
        $user = new User();

        $service->startTrial($user);

        self::assertTrue($user->isTrialActive());
        self::assertSame(Subscription::PLAN_BASIC, $service->accessPlan($user));
        self::assertSame(3, $service->remainingTrialAiUses($user));
    }

    public function testExpiredTrialHasNoAccess(): void
    {
        $service = $this->service();
        $user = (new User())->setTrialEndsAt(new \DateTimeImmutable('-1 minute'));

        self::assertFalse($service->hasAccess($user));
        self::assertNull($service->accessPlan($user));
        self::assertNull($service->remainingTrialAiUses($user));
    }

    public function testTrialAiReservationIsAtomicAndUpdatesRemainingCount(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('executeStatement')
            ->willReturn(1);
        $service = $this->service($connection);
        $user = (new User())->setTrialEndsAt(new \DateTimeImmutable('+5 days'));
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 42);

        self::assertTrue($service->reserveTrialAiUse($user));
        self::assertSame(1, $user->getTrialAiUses());
        self::assertSame(2, $service->remainingTrialAiUses($user));
    }

    public function testSubscriptionHistoryPreventsTrialFallback(): void
    {
        $subscriptions = $this->createMock(SubscriptionRepository::class);
        $subscriptions->method('findOneBy')->willReturn(
            (new Subscription())->setStatus(Subscription::STATUS_EXPIRED)
        );
        $service = new EntitlementService($subscriptions, $this->createMock(Connection::class));
        $user = (new User())->setTrialEndsAt(new \DateTimeImmutable('+5 days'));

        self::assertFalse($service->hasAccess($user));
        self::assertFalse($service->isTrialAccess($user));
        self::assertNull($service->accessPlan($user));
    }

    private function service(?Connection $connection = null): EntitlementService
    {
        $subscriptions = $this->createMock(SubscriptionRepository::class);
        $subscriptions->method('findOneBy')->willReturn(null);

        return new EntitlementService(
            $subscriptions,
            $connection ?? $this->createMock(Connection::class),
        );
    }
}
