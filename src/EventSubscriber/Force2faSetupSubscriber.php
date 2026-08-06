<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class Force2faSetupSubscriber implements EventSubscriberInterface
{
    // Routes exempt from the 2FA setup redirect
    private const EXEMPT_PREFIXES = [
        '/2fa',
        '/logout',
        '/_',          // Symfony internal (profiler, wdt)
        '/login',
    ];

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private RouterInterface $router,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            // Firewall runs at 8; inactive-account protection runs at 7.
            KernelEvents::REQUEST => ['onKernelRequest', 6],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if (!$token) {
            return;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return;
        }

        // Only enforce for ROLE_ADMIN and ROLE_OWNER
        $roles = array_map(fn($r) => is_string($r) ? $r : $r->getRole(), $token->getRoleNames());
        if (!in_array('ROLE_ADMIN', $roles, true) && !in_array('ROLE_OWNER', $roles, true)) {
            return;
        }

        // Already configured — nothing to do
        if ($user->isTotpAuthenticationEnabled()) {
            return;
        }

        // Check if the current path is exempt
        $path = $event->getRequest()->getPathInfo();
        foreach (self::EXEMPT_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        $event->setResponse(new RedirectResponse(
            $this->router->generate('app_2fa_setup')
        ));
    }
}
