<?php

namespace App\Tests\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\Force2faSetupSubscriber;
use App\EventSubscriber\InactiveUserSubscriber;
use App\EventSubscriber\LimitEnforcementSubscriber;
use App\EventSubscriber\SubscriptionCheckSubscriber;
use App\Service\EntitlementService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class AccountGuardSubscriberTest extends TestCase
{
    public function testAccountGuardsRunAfterFirewallInSecurityOrder(): void
    {
        self::assertSame(7, InactiveUserSubscriber::getSubscribedEvents()[KernelEvents::REQUEST][1]);
        self::assertSame(6, Force2faSetupSubscriber::getSubscribedEvents()[KernelEvents::REQUEST][1]);
        self::assertSame(5, SubscriptionCheckSubscriber::getSubscribedEvents()[KernelEvents::REQUEST][1]);
        self::assertSame(4, LimitEnforcementSubscriber::getSubscribedEvents()[KernelEvents::REQUEST][1]);
    }

    public function testExpiredOwnerDashboardRequestIsRedirected(): void
    {
        $owner = (new User())
            ->setEmail('owner@example.com')
            ->setRole('owner')
            ->setEmailVerifiedAt(new \DateTimeImmutable());
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($owner);
        $storage = $this->createMock(TokenStorageInterface::class);
        $storage->method('getToken')->willReturn($token);
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::once())->method('generate')->with('app_trial_expired')->willReturn('/trial-expired');
        $entitlements = $this->createMock(EntitlementService::class);
        $entitlements->method('hasAccess')->with($owner)->willReturn(false);
        $request = Request::create('/dashboard');
        $request->attributes->set('_route', 'app_dashboard');
        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        (new SubscriptionCheckSubscriber($storage, $router, $entitlements))->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame('/trial-expired', $event->getResponse()?->headers->get('Location'));
    }

    public function testOwnerWithUnresolvedLimitsCannotBypassSelectionThroughDashboard(): void
    {
        $owner = (new User())
            ->setEmail('owner@example.com')
            ->setRole('owner')
            ->setEnforcementRequired(true);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($owner);
        $storage = $this->createMock(TokenStorageInterface::class);
        $storage->method('getToken')->willReturn($token);
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::once())->method('generate')
            ->with('app_owner_subscription_enforce_limits')
            ->willReturn('/owner/subscription/enforce-limits');
        $request = Request::create('/dashboard');
        $request->attributes->set('_route', 'app_dashboard');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        (new LimitEnforcementSubscriber($storage, $router))->onKernelRequest($event);

        self::assertSame('/owner/subscription/enforce-limits', $event->getResponse()?->headers->get('Location'));
    }
}
