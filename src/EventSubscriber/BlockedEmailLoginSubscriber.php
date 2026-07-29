<?php

namespace App\EventSubscriber;

use App\Service\EmailBlockService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

class BlockedEmailLoginSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EmailBlockService $emailBlocks,
        private readonly RouterInterface $router,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Routing has completed and this runs before the security firewall.
        return [KernelEvents::REQUEST => ['onKernelRequest', 12]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if ($request->attributes->get('_route') !== 'app_login' || !$request->isMethod('POST')) {
            return;
        }
        $email = (string) $request->request->get('email', '');
        $block = $this->emailBlocks->activeBlock($email);
        if (!$block) {
            return;
        }
        $request->getSession()->set('blocked_email_until', $block->getBlockedUntil()->format(DATE_ATOM));
        $event->setResponse(new RedirectResponse($this->router->generate('app_email_blocked')));
    }
}
