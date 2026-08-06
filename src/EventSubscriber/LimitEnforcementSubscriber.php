<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Enforces that owners who exceed their subscription limits complete the enforcement flow
 * before accessing any dashboard features.
 * 
 * Runs after authentication, account, 2FA, and subscription guards.
 */
class LimitEnforcementSubscriber implements EventSubscriberInterface
{
    private const ALLOWED_ROUTES = [
        'app_owner_subscription_enforce_limits',      // GET enforcement page
        'app_owner_subscription_enforce_limits_post', // POST enforcement submission
        'app_owner_subscription',                     // View subscription page
        'app_logout',
    ];

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly RouterInterface        $router,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 4]];
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

        // Skip the enforcement routes themselves
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

        // Admins bypass enforcement checks
        if ($user->getRole() === 'admin') {
            return;
        }

        // Check if enforcement is required
        if ($user->isEnforcementRequired()) {
            $request->getSession()->getFlashBag()->add(
                'warning',
                'Please select which menus to keep before continuing. Your new plan allows fewer menus than you currently have.'
            );

            $event->setResponse(new RedirectResponse(
                $this->router->generate('app_owner_subscription_enforce_limits')
            ));
        }
    }
}
