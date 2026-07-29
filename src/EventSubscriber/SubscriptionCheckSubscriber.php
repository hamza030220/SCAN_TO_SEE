<?php

namespace App\EventSubscriber;

use App\Service\EntitlementService;
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
        'app_owner_subscription_change',
        'app_owner_subscription_downgrade',
        'app_owner_subscription_cancel',
        'app_owner_account_delete',
        'app_stripe_webhook',
        'app_trial_expired',
        'app_logout',
    ];

    public function __construct(
        private readonly TokenStorageInterface  $tokenStorage,
        private readonly RouterInterface        $router,
        private readonly EntitlementService      $entitlements,
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

        if (!str_starts_with($route, 'app_owner') && $route !== 'app_dashboard') {
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

        if (!$user->isEmailVerified()
            && !in_array($route, ['app_owner_account_delete', 'app_logout'], true)) {
            $event->setResponse(new RedirectResponse(
                $this->router->generate('app_verify_email_pending')
            ));
            return;
        }

        // Verified owners without access must still reach payment, deletion,
        // logout, and the expiry gateway.
        if (in_array($route, self::ALLOWED_ROUTES, true)) {
            return;
        }

        if (!$this->entitlements->hasAccess($user)) {
            $event->setResponse(new RedirectResponse(
                $this->router->generate('app_trial_expired')
            ));
        }
    }
}
