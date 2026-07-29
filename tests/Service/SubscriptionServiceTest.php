<?php

namespace App\Tests\Service;

use App\Entity\Business;
use App\Entity\Menu;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\MenuRepository;
use App\Repository\SubscriptionRepository;
use App\Service\SubscriptionService;
use App\Service\EntitlementService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class SubscriptionServiceTest extends TestCase
{
    private SubscriptionService $service;
    private SubscriptionRepository $subscriptionRepo;
    private MenuRepository $menuRepo;
    private EntityManagerInterface $em;
    private EntitlementService $entitlements;

    protected function setUp(): void
    {
        // Create mocks for dependencies
        $this->subscriptionRepo = $this->createMock(SubscriptionRepository::class);
        $this->menuRepo = $this->createMock(MenuRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->entitlements = $this->createMock(EntitlementService::class);
        $this->entitlements->method('hasPaidAccess')->willReturnCallback(
            function (User $user): bool {
                return $this->subscriptionRepo->findOneBy(['owner' => $user])?->isActive() ?? false;
            }
        );
        $this->entitlements->method('hasAccess')->willReturnCallback(
            function (User $user): bool {
                return $this->subscriptionRepo->findOneBy(['owner' => $user])?->isActive() ?? false;
            }
        );
        $this->entitlements->method('accessPlan')->willReturnCallback(
            function (User $user): ?string {
                $subscription = $this->subscriptionRepo->findOneBy(['owner' => $user]);
                return $subscription?->isActive() ? $subscription->getPlan() : null;
            }
        );

        // Initialize service with mocked dependencies
        $this->service = new SubscriptionService(
            $this->subscriptionRepo,
            $this->menuRepo,
            $this->em,
            $this->entitlements,
            'sk_test_fake', // Stripe secret key
            'whsec_fake',   // Webhook secret
            [               // Price IDs
                'basic' => ['monthly' => 'price_basic_monthly', 'yearly' => 'price_basic_yearly'],
                'premium' => ['monthly' => 'price_premium_monthly', 'yearly' => 'price_premium_yearly'],
                'pro' => ['monthly' => 'price_pro_monthly', 'yearly' => 'price_pro_yearly'],
            ]
        );
    }

    // ── Test: hasActiveSubscription ──────────────────────────────────────────

    public function testHasActiveSubscriptionReturnsTrueWhenActive(): void
    {
        $user = $this->createUser();
        $subscription = $this->createSubscription($user, Subscription::PLAN_BASIC, Subscription::STATUS_ACTIVE);

        $this->subscriptionRepo
            ->method('findOneBy')
            ->with(['owner' => $user])
            ->willReturn($subscription);

        $result = $this->service->hasActiveSubscription($user);

        $this->assertTrue($result);
    }

    public function testHasActiveSubscriptionReturnsFalseWhenNoSubscription(): void
    {
        $user = $this->createUser();

        $this->subscriptionRepo
            ->method('findOneBy')
            ->with(['owner' => $user])
            ->willReturn(null);

        $result = $this->service->hasActiveSubscription($user);

        $this->assertFalse($result);
    }

    public function testHasActiveSubscriptionReturnsFalseWhenCancelled(): void
    {
        $user = $this->createUser();
        $subscription = $this->createSubscription($user, Subscription::PLAN_BASIC, Subscription::STATUS_CANCELLED);

        $this->subscriptionRepo
            ->method('findOneBy')
            ->with(['owner' => $user])
            ->willReturn($subscription);

        $result = $this->service->hasActiveSubscription($user);

        $this->assertFalse($result);
    }

    // ── Test: countPublishedMenus ────────────────────────────────────────────

    public function testCountPublishedMenusReturnsCorrectCount(): void
    {
        $user = $this->createUser();

        $queryBuilder = $this->createMockQueryBuilder(5); // 5 published menus

        $this->menuRepo
            ->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        $result = $this->service->countPublishedMenus($user);

        $this->assertSame(5, $result);
    }

    public function testCountPublishedMenusReturnsZeroWhenNoMenus(): void
    {
        $user = $this->createUser();

        $queryBuilder = $this->createMockQueryBuilder(0);

        $this->menuRepo
            ->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        $result = $this->service->countPublishedMenus($user);

        $this->assertSame(0, $result);
    }

    // ── Test: countDraftMenus ────────────────────────────────────────────────

    public function testCountDraftMenusReturnsCorrectCount(): void
    {
        $user = $this->createUser();

        $queryBuilder = $this->createMockQueryBuilder(3); // 3 draft menus

        $this->menuRepo
            ->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        $result = $this->service->countDraftMenus($user);

        $this->assertSame(3, $result);
    }

    // ── Test: canSetMenuStatus (Basic Plan - 1+1) ───────────────────────────

    public function testCanSetMenuStatusAllowsPublishingWhenUnderLimit(): void
    {
        $user = $this->createUser();
        $subscription = $this->createSubscription($user, Subscription::PLAN_BASIC, Subscription::STATUS_ACTIVE);

        $this->subscriptionRepo->method('findOneBy')->willReturn($subscription);
        $this->menuRepo->method('createQueryBuilder')->willReturn($this->createMockQueryBuilder(0)); // 0 published

        $result = $this->service->canSetMenuStatus($user, null, 'published');

        $this->assertTrue($result);
    }

    public function testCanSetMenuStatusBlocksPublishingWhenAtLimit(): void
    {
        $user = $this->createUser();
        $subscription = $this->createSubscription($user, Subscription::PLAN_BASIC, Subscription::STATUS_ACTIVE);

        $this->subscriptionRepo->method('findOneBy')->willReturn($subscription);
        
        // Mock countPublishedMenus to return 1 (at limit for Basic)
        $queryBuilder = $this->createMockQueryBuilder(1);
        $this->menuRepo->method('createQueryBuilder')->willReturn($queryBuilder);

        $result = $this->service->canSetMenuStatus($user, null, 'published');

        $this->assertFalse($result);
    }

    public function testCanSetMenuStatusAllowsSameStatusTransition(): void
    {
        $user = $this->createUser();
        $subscription = $this->createSubscription($user, Subscription::PLAN_BASIC, Subscription::STATUS_ACTIVE);

        $this->subscriptionRepo->method('findOneBy')->willReturn($subscription);

        // No status change should always be allowed
        $result = $this->service->canSetMenuStatus($user, 'published', 'published');

        $this->assertTrue($result);
    }

    public function testCanSetMenuStatusAllowsDemotionWhenDraftSlotsAvailable(): void
    {
        $user = $this->createUser();
        $subscription = $this->createSubscription($user, Subscription::PLAN_BASIC, Subscription::STATUS_ACTIVE);

        $this->subscriptionRepo->method('findOneBy')->willReturn($subscription);
        $this->menuRepo->method('createQueryBuilder')->willReturn($this->createMockQueryBuilder(0)); // 0 drafts

        $result = $this->service->canSetMenuStatus($user, 'published', 'draft');

        $this->assertTrue($result);
    }

    // ── Test: canSetMenuStatus (Pro Plan - Unlimited) ───────────────────────

    public function testCanSetMenuStatusAlwaysAllowsForProPlan(): void
    {
        $user = $this->createUser();
        $subscription = $this->createSubscription($user, Subscription::PLAN_PRO, Subscription::STATUS_ACTIVE);

        $this->subscriptionRepo->method('findOneBy')->willReturn($subscription);
        $this->menuRepo->method('createQueryBuilder')->willReturn($this->createMockQueryBuilder(100)); // Many menus

        $result = $this->service->canSetMenuStatus($user, null, 'published');

        $this->assertTrue($result);
    }

    // ── Test: canSetMenuStatus (No Active Subscription) ─────────────────────

    public function testCanSetMenuStatusReturnsFalseWhenNoSubscription(): void
    {
        $user = $this->createUser();

        $this->subscriptionRepo->method('findOneBy')->willReturn(null);

        $result = $this->service->canSetMenuStatus($user, null, 'published');

        $this->assertFalse($result);
    }

    public function testCanSetMenuStatusReturnsFalseWhenSubscriptionExpired(): void
    {
        $user = $this->createUser();
        $subscription = $this->createSubscription($user, Subscription::PLAN_BASIC, Subscription::STATUS_EXPIRED);

        $this->subscriptionRepo->method('findOneBy')->willReturn($subscription);

        $result = $this->service->canSetMenuStatus($user, null, 'published');

        $this->assertFalse($result);
    }

    // ── Test: canCreateMenu ──────────────────────────────────────────────────

    public function testCanCreateMenuAllowsWhenPublishedSlotFree(): void
    {
        $user = $this->createUser();
        $subscription = $this->createSubscription($user, Subscription::PLAN_BASIC, Subscription::STATUS_ACTIVE);

        $this->subscriptionRepo->method('findOneBy')->willReturn($subscription);
        $this->menuRepo->method('createQueryBuilder')->willReturn($this->createMockQueryBuilder(0)); // 0 menus

        $result = $this->service->canCreateMenu($user);

        $this->assertTrue($result);
    }

    public function testCanCreateMenuBlocksWhenBothSlotsFull(): void
    {
        $user = $this->createUser();
        $subscription = $this->createSubscription($user, Subscription::PLAN_BASIC, Subscription::STATUS_ACTIVE);

        $this->subscriptionRepo->method('findOneBy')->willReturn($subscription);
        $this->menuRepo->method('createQueryBuilder')->willReturn($this->createMockQueryBuilder(1)); // 1 menu (both slots full)

        $result = $this->service->canCreateMenu($user);

        $this->assertFalse($result);
    }

    // ── Helper Methods ───────────────────────────────────────────────────────

    private function createUser(): User
    {
        $user = new User();
        $user->setEmail('test@example.com');
        $user->setPassword('hashed_password');
        $user->setRole('owner');

        // Use reflection to set ID (since it's auto-generated)
        $reflection = new \ReflectionClass($user);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($user, 1);

        return $user;
    }

    private function createSubscription(User $user, string $plan, string $status): Subscription
    {
        $subscription = new Subscription();
        $subscription->setOwner($user);
        $subscription->setPlan($plan);
        $subscription->setStatus($status);
        $subscription->setBillingPeriod(Subscription::PERIOD_MONTHLY);

        if ($status === Subscription::STATUS_ACTIVE) {
            $subscription->setCurrentPeriodEnd(
                (new \DateTimeImmutable())->modify('+30 days')
            );
        }

        return $subscription;
    }

    private function createMockQueryBuilder(int $count): object
    {
        $query = $this->getMockBuilder(\Doctrine\ORM\Query::class)
            ->disableOriginalConstructor()
            ->getMock();
        
        $query->method('getSingleScalarResult')->willReturn($count);
        $query->method('getResult')->willReturn(array_fill(0, $count, new Menu()));

        $qb = $this->getMockBuilder(\Doctrine\ORM\QueryBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();
        
        $qb->method('select')->willReturnSelf();
        $qb->method('join')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        return $qb;
    }

    // ── Test: autoSwapMenuStatus ────────────────────────────────────────────

    public function testAutoSwapMenuStatusAllowsWhenWithinLimit(): void
    {
        $user = $this->createUser();
        $subscription = $this->createSubscription($user, Subscription::PLAN_BASIC, Subscription::STATUS_ACTIVE);

        $this->subscriptionRepo->method('findOneBy')->willReturn($subscription);
        $this->menuRepo->method('createQueryBuilder')->willReturn($this->createMockQueryBuilder(0)); // Under limit

        $result = $this->service->autoSwapMenuStatus($user, null, null, 'published');

        $this->assertTrue($result['allowed']);
        $this->assertNull($result['swapped_menu']);
        $this->assertNull($result['message']);
    }

    public function testAutoSwapMenuStatusReturnsFalseWithoutActiveSubscription(): void
    {
        $user = $this->createUser();
        
        $this->subscriptionRepo->method('findOneBy')->willReturn(null);

        $result = $this->service->autoSwapMenuStatus($user, null, null, 'published');

        $this->assertFalse($result['allowed']);
        $this->assertNull($result['swapped_menu']);
        $this->assertSame('No active subscription found.', $result['message']);
    }

    public function testAutoSwapMenuStatusAllowsUnlimitedForProPlan(): void
    {
        $user = $this->createUser();
        $subscription = $this->createSubscription($user, Subscription::PLAN_PRO, Subscription::STATUS_ACTIVE);

        $this->subscriptionRepo->method('findOneBy')->willReturn($subscription);

        $result = $this->service->autoSwapMenuStatus($user, null, null, 'published');

        $this->assertTrue($result['allowed']);
        $this->assertNull($result['swapped_menu']);
        $this->assertNull($result['message']);
    }

    public function testAutoSwapMenuStatusDoesNotDemotePublishedMenuWhenLimitIsReached(): void
    {
        $user = $this->createUser();
        $subscription = $this->createSubscription($user, Subscription::PLAN_BASIC, Subscription::STATUS_ACTIVE);

        $this->subscriptionRepo->method('findOneBy')->willReturn($subscription);

        // Create mock menu that will be swapped
        $oldestMenu = $this->createMock(Menu::class);
        $oldestMenu->method('getName')->willReturn('Old Menu');
        $oldestMenu->expects($this->never())->method('setStatus');
        $oldestMenu->expects($this->never())->method('setUpdatedAt');

        // First call: countPublishedMenus in canSetMenuStatus (returns 1 = at limit)
        $countQuery1 = $this->getMockBuilder(\Doctrine\ORM\Query::class)
            ->disableOriginalConstructor()
            ->getMock();
        $countQuery1->method('getSingleScalarResult')->willReturn(1);

        $countQueryBuilder1 = $this->getMockBuilder(\Doctrine\ORM\QueryBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();
        
        $countQueryBuilder1->method('select')->willReturnSelf();
        $countQueryBuilder1->method('join')->willReturnSelf();
        $countQueryBuilder1->method('where')->willReturnSelf();
        $countQueryBuilder1->method('andWhere')->willReturnSelf();
        $countQueryBuilder1->method('setParameter')->willReturnSelf();
        $countQueryBuilder1->method('getQuery')->willReturn($countQuery1);

        // Second call: countDraftMenus in canSetMenuStatus (not relevant but called anyway)
        $countQuery2 = $this->getMockBuilder(\Doctrine\ORM\Query::class)
            ->disableOriginalConstructor()
            ->getMock();
        $countQuery2->method('getSingleScalarResult')->willReturn(0);

        $countQueryBuilder2 = $this->getMockBuilder(\Doctrine\ORM\QueryBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();
        
        $countQueryBuilder2->method('select')->willReturnSelf();
        $countQueryBuilder2->method('join')->willReturnSelf();
        $countQueryBuilder2->method('where')->willReturnSelf();
        $countQueryBuilder2->method('andWhere')->willReturnSelf();
        $countQueryBuilder2->method('setParameter')->willReturnSelf();
        $countQueryBuilder2->method('getQuery')->willReturn($countQuery2);

        // Third call: countPublishedMenus in autoSwapMenuStatus (returns 1 = at limit)
        $countQuery3 = $this->getMockBuilder(\Doctrine\ORM\Query::class)
            ->disableOriginalConstructor()
            ->getMock();
        $countQuery3->method('getSingleScalarResult')->willReturn(1);

        $countQueryBuilder3 = $this->getMockBuilder(\Doctrine\ORM\QueryBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();
        
        $countQueryBuilder3->method('select')->willReturnSelf();
        $countQueryBuilder3->method('join')->willReturnSelf();
        $countQueryBuilder3->method('where')->willReturnSelf();
        $countQueryBuilder3->method('andWhere')->willReturnSelf();
        $countQueryBuilder3->method('setParameter')->willReturnSelf();
        $countQueryBuilder3->method('getQuery')->willReturn($countQuery3);

        // Fourth call: Find oldest published menu to swap
        $menuQuery = $this->getMockBuilder(\Doctrine\ORM\Query::class)
            ->disableOriginalConstructor()
            ->getMock();
        $menuQuery->method('getOneOrNullResult')->willReturn($oldestMenu);

        $menuQueryBuilder = $this->getMockBuilder(\Doctrine\ORM\QueryBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();
        
        $menuQueryBuilder->method('join')->willReturnSelf();
        $menuQueryBuilder->method('where')->willReturnSelf();
        $menuQueryBuilder->method('andWhere')->willReturnSelf();
        $menuQueryBuilder->method('setParameter')->willReturnSelf();
        $menuQueryBuilder->method('orderBy')->willReturnSelf();
        $menuQueryBuilder->method('setMaxResults')->willReturnSelf();
        $menuQueryBuilder->method('getQuery')->willReturn($menuQuery);

        $this->menuRepo->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls($countQueryBuilder1, $countQueryBuilder2, $countQueryBuilder3, $menuQueryBuilder);

        $this->em->expects($this->never())->method('flush');

        $result = $this->service->autoSwapMenuStatus($user, null, null, 'published');

        $this->assertFalse($result['allowed']);
        $this->assertNull($result['swapped_menu']);
        $this->assertStringContainsString('Basic', $result['message']);
        $this->assertStringContainsString('published', $result['message']);
    }

    public function testAutoSwapMenuStatusDoesNotPublishDraftMenuWhenLimitIsReached(): void
    {
        $user = $this->createUser();
        $subscription = $this->createSubscription($user, Subscription::PLAN_BASIC, Subscription::STATUS_ACTIVE);

        $this->subscriptionRepo->method('findOneBy')->willReturn($subscription);

        // Create mock menu that will be swapped
        $oldestMenu = $this->createMock(Menu::class);
        $oldestMenu->method('getName')->willReturn('Old Draft');
        $oldestMenu->expects($this->never())->method('setStatus');
        $oldestMenu->expects($this->never())->method('setUpdatedAt');

        // First call: countPublishedMenus in canSetMenuStatus (not relevant but called)
        $countQuery1 = $this->getMockBuilder(\Doctrine\ORM\Query::class)
            ->disableOriginalConstructor()
            ->getMock();
        $countQuery1->method('getSingleScalarResult')->willReturn(0);

        $countQueryBuilder1 = $this->getMockBuilder(\Doctrine\ORM\QueryBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();
        
        $countQueryBuilder1->method('select')->willReturnSelf();
        $countQueryBuilder1->method('join')->willReturnSelf();
        $countQueryBuilder1->method('where')->willReturnSelf();
        $countQueryBuilder1->method('andWhere')->willReturnSelf();
        $countQueryBuilder1->method('setParameter')->willReturnSelf();
        $countQueryBuilder1->method('getQuery')->willReturn($countQuery1);

        // Second call: countDraftMenus in canSetMenuStatus (returns 1 = at limit)
        $countQuery2 = $this->getMockBuilder(\Doctrine\ORM\Query::class)
            ->disableOriginalConstructor()
            ->getMock();
        $countQuery2->method('getSingleScalarResult')->willReturn(1);

        $countQueryBuilder2 = $this->getMockBuilder(\Doctrine\ORM\QueryBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();
        
        $countQueryBuilder2->method('select')->willReturnSelf();
        $countQueryBuilder2->method('join')->willReturnSelf();
        $countQueryBuilder2->method('where')->willReturnSelf();
        $countQueryBuilder2->method('andWhere')->willReturnSelf();
        $countQueryBuilder2->method('setParameter')->willReturnSelf();
        $countQueryBuilder2->method('getQuery')->willReturn($countQuery2);

        // Third call: countDraftMenus in autoSwapMenuStatus (returns 1 = at limit)
        $countQuery3 = $this->getMockBuilder(\Doctrine\ORM\Query::class)
            ->disableOriginalConstructor()
            ->getMock();
        $countQuery3->method('getSingleScalarResult')->willReturn(1);

        $countQueryBuilder3 = $this->getMockBuilder(\Doctrine\ORM\QueryBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();
        
        $countQueryBuilder3->method('select')->willReturnSelf();
        $countQueryBuilder3->method('join')->willReturnSelf();
        $countQueryBuilder3->method('where')->willReturnSelf();
        $countQueryBuilder3->method('andWhere')->willReturnSelf();
        $countQueryBuilder3->method('setParameter')->willReturnSelf();
        $countQueryBuilder3->method('getQuery')->willReturn($countQuery3);

        // Fourth call: Find oldest draft menu to swap
        $menuQuery = $this->getMockBuilder(\Doctrine\ORM\Query::class)
            ->disableOriginalConstructor()
            ->getMock();
        $menuQuery->method('getOneOrNullResult')->willReturn($oldestMenu);

        $menuQueryBuilder = $this->getMockBuilder(\Doctrine\ORM\QueryBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();
        
        $menuQueryBuilder->method('join')->willReturnSelf();
        $menuQueryBuilder->method('where')->willReturnSelf();
        $menuQueryBuilder->method('andWhere')->willReturnSelf();
        $menuQueryBuilder->method('setParameter')->willReturnSelf();
        $menuQueryBuilder->method('orderBy')->willReturnSelf();
        $menuQueryBuilder->method('setMaxResults')->willReturnSelf();
        $menuQueryBuilder->method('getQuery')->willReturn($menuQuery);

        $this->menuRepo->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls($countQueryBuilder1, $countQueryBuilder2, $countQueryBuilder3, $menuQueryBuilder);

        $this->em->expects($this->never())->method('flush');

        $result = $this->service->autoSwapMenuStatus($user, null, null, 'draft');

        $this->assertFalse($result['allowed']);
        $this->assertNull($result['swapped_menu']);
        $this->assertStringContainsString('Basic', $result['message']);
        $this->assertStringContainsString('draft', $result['message']);
    }

    public function testPendingSubscriptionCannotCreateOrPublishMenus(): void
    {
        $user = $this->createUser();
        $subscription = $this->createSubscription(
            $user,
            Subscription::PLAN_PRO,
            Subscription::STATUS_PENDING
        );

        $this->subscriptionRepo->method('findOneBy')->willReturn($subscription);

        $this->assertFalse($this->service->canCreateMenu($user));
        $this->assertFalse($this->service->canSetMenuStatus($user, 'draft', 'published'));
    }

    public function testInvalidMenuStatusIsRejected(): void
    {
        $user = $this->createUser();
        $subscription = $this->createSubscription(
            $user,
            Subscription::PLAN_PRO,
            Subscription::STATUS_ACTIVE
        );

        $this->subscriptionRepo->method('findOneBy')->willReturn($subscription);

        $this->assertFalse($this->service->canSetMenuStatus($user, 'draft', 'unknown'));
    }

    public function testBusinessLimitUsesActivePlan(): void
    {
        $user = $this->createUser();
        $subscription = $this->createSubscription(
            $user,
            Subscription::PLAN_BASIC,
            Subscription::STATUS_ACTIVE
        );

        $this->subscriptionRepo->method('findOneBy')->willReturn($subscription);

        $this->assertTrue($this->service->canCreateBusiness($user, 0));
        $this->assertFalse($this->service->canCreateBusiness($user, 1));

        $subscription->setPlan(Subscription::PLAN_PREMIUM);
        $this->assertTrue($this->service->canCreateBusiness($user, 10));
    }

    public function testStripeSynchronizationUsesItemPeriodAndConfiguredPrice(): void
    {
        $subscription = new Subscription();
        $periodEnd = time() + 3600;
        $stripeSubscription = \Stripe\Subscription::constructFrom([
            'id' => 'sub_test',
            'status' => 'active',
            'items' => [
                'object' => 'list',
                'data' => [[
                    'id' => 'si_test',
                    'object' => 'subscription_item',
                    'current_period_end' => $periodEnd,
                    'price' => [
                        'id' => 'price_pro_yearly',
                        'object' => 'price',
                    ],
                ]],
            ],
        ]);

        $this->service->synchronizeFromStripe($subscription, $stripeSubscription);

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->getStatus());
        $this->assertSame(Subscription::PLAN_PRO, $subscription->getPlan());
        $this->assertSame(Subscription::PERIOD_YEARLY, $subscription->getBillingPeriod());
        $this->assertSame($periodEnd, $subscription->getCurrentPeriodEnd()?->getTimestamp());
    }

    public function testInvoiceSubscriptionLookupSupportsCurrentAndLegacyStripeShapes(): void
    {
        $method = new \ReflectionMethod(SubscriptionService::class, 'getInvoiceSubscriptionId');

        $currentInvoice = \Stripe\Invoice::constructFrom([
            'id' => 'in_current',
            'parent' => [
                'type' => 'subscription_details',
                'subscription_details' => ['subscription' => 'sub_current'],
            ],
        ]);
        $legacyInvoice = \Stripe\Invoice::constructFrom([
            'id' => 'in_legacy',
            'subscription' => 'sub_legacy',
        ]);

        $this->assertSame('sub_current', $method->invoke($this->service, $currentInvoice));
        $this->assertSame('sub_legacy', $method->invoke($this->service, $legacyInvoice));
    }
}
