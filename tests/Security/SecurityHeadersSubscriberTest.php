<?php

namespace App\Tests\Security;

use App\EventSubscriber\SecurityHeadersSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SecurityHeadersSubscriberTest extends TestCase
{
    public function testItProtectsSensitiveResponsesAndSecureProductionTraffic(): void
    {
        $request = Request::create('https://example.test/admin');
        $response = new Response('private');
        $kernel = $this->createMock(HttpKernelInterface::class);
        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        (new SecurityHeadersSubscriber('prod'))->onKernelResponse($event);

        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertStringContainsString("frame-ancestors 'none'", (string) $response->headers->get('Content-Security-Policy'));
        self::assertSame('max-age=31536000', $response->headers->get('Strict-Transport-Security'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testItDoesNotSetHstsForPlainHttpDevelopmentTraffic(): void
    {
        $request = Request::create('http://127.0.0.1/');
        $response = new Response('public');
        $kernel = $this->createMock(HttpKernelInterface::class);
        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        (new SecurityHeadersSubscriber('dev'))->onKernelResponse($event);

        self::assertFalse($response->headers->has('Strict-Transport-Security'));
        self::assertStringNotContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
