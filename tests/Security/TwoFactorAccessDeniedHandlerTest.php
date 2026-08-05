<?php

namespace App\Tests\Security;

use App\Security\TwoFactorAccessDeniedHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class TwoFactorAccessDeniedHandlerTest extends TestCase
{
    public function testItRedirectsAnAuthenticatedUserAwayFromAStaleTwoFactorForm(): void
    {
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->expects(self::once())->method('isGranted')->with('IS_AUTHENTICATED_FULLY')->willReturn(true);
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::once())->method('generate')->with('app_dashboard')->willReturn('/dashboard');

        $response = (new TwoFactorAccessDeniedHandler($authorization, $router))
            ->handle(Request::create('/2fa_check', 'POST'), new AccessDeniedException());

        self::assertSame('/dashboard', $response?->headers->get('Location'));
    }

    public function testItDoesNotHideUnrelatedAuthorizationFailures(): void
    {
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->expects(self::never())->method('isGranted');
        $router = $this->createMock(RouterInterface::class);

        $response = (new TwoFactorAccessDeniedHandler($authorization, $router))
            ->handle(Request::create('/admin'), new AccessDeniedException());

        self::assertNull($response);
    }

    public function testItKeepsTheChallengeForAUserStillCompletingTwoFactorAuthentication(): void
    {
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->with('IS_AUTHENTICATED_FULLY')->willReturn(false);
        $router = $this->createMock(RouterInterface::class);

        $response = (new TwoFactorAccessDeniedHandler($authorization, $router))
            ->handle(Request::create('/2fa_check', 'POST'), new AccessDeniedException());

        self::assertNull($response);
    }
}
