<?php

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $kernelEnvironment,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onKernelResponse'];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        $headers = $response->headers;

        $isOwnerMenuPreview = $request->attributes->get('_route') === 'app_public_menu_view'
            && $request->query->getBoolean('preview');

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', $isOwnerMenuPreview ? 'SAMEORIGIN' : 'DENY');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $frameAncestors = $isOwnerMenuPreview ? "'self'" : "'none'";
        $headers->set('Content-Security-Policy', "base-uri 'self'; form-action 'self'; frame-ancestors {$frameAncestors}; object-src 'none'");

        if ($this->kernelEnvironment === 'prod' && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        if (preg_match('#^/(admin|owner|dashboard|2fa)(?:/|$)#D', $request->getPathInfo())) {
            $headers->set('Cache-Control', 'no-store, private');
            $headers->set('Pragma', 'no-cache');
        }
    }
}
