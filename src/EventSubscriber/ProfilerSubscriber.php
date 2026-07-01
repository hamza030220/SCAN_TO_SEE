<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Profiler\Profiler;

/**
 * Disables the Symfony web profiler toolbar for any request that does not
 * come from localhost. This keeps the toolbar visible in the browser on your
 * development machine while hiding it when the app is accessed remotely
 * (e.g. via ngrok on a phone).
 */
class ProfilerSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly ?Profiler $profiler = null)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 10],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($this->profiler === null || !$event->isMainRequest()) {
            return;
        }

        $host = $event->getRequest()->getHost();

        // Only keep the profiler active for localhost access
        if ($host !== 'localhost' && $host !== '127.0.0.1') {
            $this->profiler->disable();
        }
    }
}
