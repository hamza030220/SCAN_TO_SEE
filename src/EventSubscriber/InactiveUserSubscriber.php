<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * On every request: if the logged-in user is inactive, log them out immediately.
 * This handles the case where an owner was already logged in when the admin deactivated them.
 */
class InactiveUserSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private RouterInterface $router,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Must run after Symfony's firewall (priority 8), once the session
        // token has been loaded, and before the other account guards.
        return [KernelEvents::REQUEST => ['onKernelRequest', 7]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isActive()) {
            // Clear the security token (logs them out in-process)
            $this->tokenStorage->setToken(null);

            // Invalidate the session so the remember-me cookie is also cleared
            $request = $event->getRequest();
            if ($request->hasSession()) {
                $request->getSession()->invalidate();
            }

            $response = new RedirectResponse($this->router->generate('app_login'));
            $response->headers->clearCookie('REMEMBERME');
            $event->setResponse($response);
        }
    }
}
