<?php

namespace App\Tests\Entity;

use App\Entity\Subscription;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class SubscriptionTest extends TestCase
{
    public function testSubscriptionCreatesWithDefaultValues(): void
    {
        $subscription = new Subscription();

        $this->assertSame(Subscription::PLAN_BASIC, $subscription->getPlan());
        $this->assertSame(Subscription::PERIOD_MONTHLY, $subscription->getBillingPeriod());
        $this->assertSame(Subscription::STATUS_PENDING, $subscription->getStatus());
        $this->assertFalse($subscription->isExpiryReminderSent());
        $this->assertInstanceOf(\DateTimeImmutable::class, $subscription->getCreatedAt());
    }

    public function testIsActiveReturnsTrueForActiveSubscription(): void
    {
        $subscription = new Subscription();
        $subscription->setStatus(Subscription::STATUS_ACTIVE);
        $subscription->setCurrentPeriodEnd(
            (new \DateTimeImmutable())->modify('+30 days')
        );

        $this->assertTrue($subscription->isActive());
    }

    public function testIsActiveReturnsFalseForCancelledSubscription(): void
    {
        $subscription = new Subscription();
        $subscription->setStatus(Subscription::STATUS_CANCELLED);

        $this->assertFalse($subscription->isActive());
    }

    public function testIsActiveReturnsFalseForExpiredSubscription(): void
    {
        $subscription = new Subscription();
        $subscription->setStatus(Subscription::STATUS_ACTIVE);
        $subscription->setCurrentPeriodEnd(
            (new \DateTimeImmutable())->modify('-1 day')
        );

        $this->assertFalse($subscription->isActive());
    }

    public function testIsActiveReturnsFalseWithoutVerifiedPeriodEnd(): void
    {
        $subscription = (new Subscription())
            ->setStatus(Subscription::STATUS_ACTIVE)
            ->setCurrentPeriodEnd(null);

        $this->assertFalse($subscription->isActive());
    }

    public function testScheduledCancellationKeepsPaidPeriodActive(): void
    {
        $subscription = (new Subscription())
            ->setStatus(Subscription::STATUS_ACTIVE)
            ->setCurrentPeriodEnd(new \DateTimeImmutable('+10 days'))
            ->setCancelAtPeriodEnd(true);

        $this->assertTrue($subscription->isActive());
        $this->assertTrue($subscription->isCancelAtPeriodEnd());
    }

    public function testPastDueSubscriptionHasAccessOnlyDuringGracePeriod(): void
    {
        $subscription = (new Subscription())
            ->setStatus(Subscription::STATUS_PAST_DUE)
            ->setCurrentPeriodEnd(new \DateTimeImmutable('-1 hour'))
            ->setPaymentGraceEndsAt(new \DateTimeImmutable('+3 days'));

        $this->assertTrue($subscription->isInPaymentGrace());
        $this->assertTrue($subscription->isActive());

        $subscription->setPaymentGraceEndsAt(new \DateTimeImmutable('-1 second'));
        $this->assertFalse($subscription->isInPaymentGrace());
        $this->assertFalse($subscription->isActive());
    }

    public function testGetPublishedMenuLimitReturnsCorrectValue(): void
    {
        $subscription = new Subscription();
        
        $subscription->setPlan(Subscription::PLAN_BASIC);
        $this->assertSame(1, $subscription->getPublishedMenuLimit());

        $subscription->setPlan(Subscription::PLAN_PREMIUM);
        $this->assertSame(3, $subscription->getPublishedMenuLimit());

        $subscription->setPlan(Subscription::PLAN_PRO);
        $this->assertNull($subscription->getPublishedMenuLimit()); // Unlimited
    }

    public function testGetDraftMenuLimitReturnsCorrectValue(): void
    {
        $subscription = new Subscription();
        
        $subscription->setPlan(Subscription::PLAN_BASIC);
        $this->assertSame(1, $subscription->getDraftMenuLimit());

        $subscription->setPlan(Subscription::PLAN_PREMIUM);
        $this->assertSame(3, $subscription->getDraftMenuLimit());

        $subscription->setPlan(Subscription::PLAN_PRO);
        $this->assertNull($subscription->getDraftMenuLimit()); // Unlimited
    }

    public function testGetPlanRankReturnsCorrectOrder(): void
    {
        $subscription = new Subscription();
        
        $subscription->setPlan(Subscription::PLAN_BASIC);
        $this->assertSame(1, $subscription->getPlanRank());

        $subscription->setPlan(Subscription::PLAN_PREMIUM);
        $this->assertSame(2, $subscription->getPlanRank());

        $subscription->setPlan(Subscription::PLAN_PRO);
        $this->assertSame(3, $subscription->getPlanRank());
    }

    public function testGetDaysUntilExpiryReturnsCorrectValue(): void
    {
        $subscription = new Subscription();
        $futureDate = (new \DateTimeImmutable())->modify('+7 days');
        $subscription->setCurrentPeriodEnd($futureDate);

        $days = $subscription->getDaysUntilExpiry();

        // Use range assertion to account for timing edge cases during test execution
        $this->assertGreaterThanOrEqual(6, $days);
        $this->assertLessThanOrEqual(7, $days);
    }

    public function testGetDaysUntilExpiryReturnsNullWhenNoPeriodEnd(): void
    {
        $subscription = new Subscription();

        $days = $subscription->getDaysUntilExpiry();

        $this->assertNull($days);
    }

    public function testGetDaysUntilExpiryReturnsNegativeForExpired(): void
    {
        $subscription = new Subscription();
        $subscription->setCurrentPeriodEnd(
            (new \DateTimeImmutable())->modify('-3 days')
        );

        $days = $subscription->getDaysUntilExpiry();

        $this->assertSame(-3, $days);
    }

    public function testSetAndGetOwner(): void
    {
        $user = new User();
        $user->setEmail('owner@example.com');

        $subscription = new Subscription();
        $subscription->setOwner($user);

        $this->assertSame($user, $subscription->getOwner());
    }

    public function testSetAndGetStripeIds(): void
    {
        $subscription = new Subscription();
        $subscription->setStripeCustomerId('cus_123');
        $subscription->setStripeSubscriptionId('sub_456');

        $this->assertSame('cus_123', $subscription->getStripeCustomerId());
        $this->assertSame('sub_456', $subscription->getStripeSubscriptionId());
    }

    public function testPendingDowngradeCanBeScheduledAndCleared(): void
    {
        $effectiveAt = new \DateTimeImmutable('+1 month');
        $subscription = (new Subscription())
            ->setPendingPlan(Subscription::PLAN_BASIC)
            ->setPendingBillingPeriod(Subscription::PERIOD_MONTHLY)
            ->setPendingPlanEffectiveAt($effectiveAt);

        $this->assertTrue($subscription->hasPendingDowngrade());
        $this->assertSame(Subscription::PLAN_BASIC, $subscription->getPendingPlan());
        $this->assertSame($effectiveAt, $subscription->getPendingPlanEffectiveAt());

        $subscription->clearPendingDowngrade();
        $this->assertFalse($subscription->hasPendingDowngrade());
    }

    public function testPlanLabelsAreCorrect(): void
    {
        $this->assertSame('Basic', Subscription::LABELS[Subscription::PLAN_BASIC]);
        $this->assertSame('Premium', Subscription::LABELS[Subscription::PLAN_PREMIUM]);
        $this->assertSame('Pro', Subscription::LABELS[Subscription::PLAN_PRO]);
    }

    public function testPlanLimitsAreCorrect(): void
    {
        $this->assertSame(
            ['published' => 1, 'draft' => 1],
            Subscription::LIMITS[Subscription::PLAN_BASIC]
        );
        $this->assertSame(
            ['published' => 3, 'draft' => 3],
            Subscription::LIMITS[Subscription::PLAN_PREMIUM]
        );
        $this->assertSame(
            ['published' => null, 'draft' => null],
            Subscription::LIMITS[Subscription::PLAN_PRO]
        );
    }

    public function testBusinessLimitsAreCorrect(): void
    {
        $subscription = new Subscription();

        $subscription->setPlan(Subscription::PLAN_BASIC);
        $this->assertNull($subscription->getBusinessLimit());

        $subscription->setPlan(Subscription::PLAN_PREMIUM);
        $this->assertNull($subscription->getBusinessLimit());

        $subscription->setPlan(Subscription::PLAN_PRO);
        $this->assertNull($subscription->getBusinessLimit());
    }

    public function testPlanPricesAreCorrect(): void
    {
        // Basic plan
        $this->assertSame(500, Subscription::PRICES[Subscription::PLAN_BASIC][Subscription::PERIOD_MONTHLY]);
        $this->assertSame(5000, Subscription::PRICES[Subscription::PLAN_BASIC][Subscription::PERIOD_YEARLY]);

        // Premium plan
        $this->assertSame(1200, Subscription::PRICES[Subscription::PLAN_PREMIUM][Subscription::PERIOD_MONTHLY]);
        $this->assertSame(12000, Subscription::PRICES[Subscription::PLAN_PREMIUM][Subscription::PERIOD_YEARLY]);

        // Pro plan
        $this->assertSame(2500, Subscription::PRICES[Subscription::PLAN_PRO][Subscription::PERIOD_MONTHLY]);
        $this->assertSame(25000, Subscription::PRICES[Subscription::PLAN_PRO][Subscription::PERIOD_YEARLY]);
    }
}
