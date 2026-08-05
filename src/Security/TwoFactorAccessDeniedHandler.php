<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

final class TwoFactorAccessDeniedHandler implements AccessDeniedHandlerInterface
{
    public function __construct(
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly RouterInterface $router,
    ) {}

    public function handle(Request $request, AccessDeniedException $accessDeniedException): ?Response
    {
        if (in_array($request->getPathInfo(), ['/2fa', '/2fa_check'], true)
            && $this->authorizationChecker->isGranted('IS_AUTHENTICATED_FULLY')) {
            return new RedirectResponse($this->router->generate('app_dashboard'));
        }

        return null;
    }
}
