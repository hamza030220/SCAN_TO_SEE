<?php

namespace App\EventSubscriber;

use App\Entity\Subscription;
use App\Repository\SubscriptionRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Enforces that every owner has an active subscription before accessing /owner/* routes.
 * Exceptions: the subscription page itself and the Stripe webhook.
 */
class SubscriptionCheckSubscriber implements EventSubscriberInterface
{
    private const ALLOWED_ROUTES = [
        'app_owner_subscription',
        'app_owner_subscription_checkout',
        'app_owner_subscription_success',
        'app_owner_subscription_downgrade',
        'app_owner_subscription_cancel',
        'app_stripe_webhook',
        'app_logout',
    ];

    public function __construct(
        private readonly TokenStorageInterface  $tokenStorage,
        private readonly RouterInterface        $router,
        private readonly SubscriptionRepository $subRepo,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 10]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route   = $request->attributes->get('_route', '');

        // Only enforce on /owner/* routes
        if (!str_starts_with($route, 'app_owner')) {
            return;
        }

        // Skip the subscription management routes themselves
        if (in_array($route, self::ALLOWED_ROUTES, true)) {
            return;
        }

        // Check authenticated user
        $token = $this->tokenStorage->getToken();
        if (!$token) {
            return;
        }

        $user = $token->getUser();
        if (!$user instanceof \App\Entity\User) {
            return;
        }

        // Admins bypass subscription checks
        if ($user->getRole() === 'admin') {
            return;
        }

        // Check subscription
        $sub = $this->subRepo->findOneBy(['owner' => $user]);

        // Allow both active and pending (pending = payment received, waiting for webhook confirmation)
        if (!$sub || (!$sub->isActive() && $sub->getStatus() !== Subscription::STATUS_PENDING)) {
            $status = $sub?->getStatus() ?? 'none';

            // Add context-appropriate flash message
            if ($status === Subscription::STATUS_EXPIRED || $status === Subscription::STATUS_CANCELLED) {
                $request->getSession()->getFlashBag()->add(
                    'error',
                    'Your subscription has expired. Please renew to access your dashboard.'
                );
            } else {
                $request->getSession()->getFlashBag()->add(
                    'warning',
                    'Please choose a subscription plan to access your dashboard.'
                );
            }

            $event->setResponse(new RedirectResponse(
                $this->router->generate('app_owner_subscription')
            ));
        }
    }
}
