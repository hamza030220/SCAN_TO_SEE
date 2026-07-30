<?php

namespace App\Tests\Controller;

use App\Controller\AdminController;
use App\Entity\AdminAuditLog;
use App\Entity\User;
use App\Service\AccountDeletionService;
use App\Service\AdminAuditService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class AdminControllerTest extends TestCase
{
    public function testDeactivationRequiresReasonAndConfirmation(): void
    {
        $admin = $this->admin();
        $owner = $this->owner();
        $request = $this->request([
            '_token' => 'valid',
            'desired_state' => '0',
            'reason' => '',
            'confirmation' => 'CONFIRM',
        ]);
        $controller = $this->controller($admin, $request);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');
        $audit = $this->createMock(AdminAuditService::class);
        $log = new AdminAuditLog();
        $audit->expects(self::once())->method('start')->willReturn($log);
        $audit->expects(self::once())
            ->method('finish')
            ->with($log, AdminAuditLog::OUTCOME_DENIED, null, 'A reason is required.');

        $response = $controller->ownerToggle($owner, $request, $em, $audit);

        self::assertTrue($owner->isActive());
        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('app_admin_owner_confirm', (string) $response->headers->get('Location'));
    }

    public function testConfirmedDeactivationIsPersistedAndAudited(): void
    {
        $admin = $this->admin();
        $owner = $this->owner();
        $request = $this->request([
            '_token' => 'valid',
            'desired_state' => '0',
            'reason' => 'Repeated abuse report confirmed by support.',
            'confirmation' => 'CONFIRM',
        ]);
        $controller = $this->controller($admin, $request);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $audit = $this->createMock(AdminAuditService::class);
        $log = new AdminAuditLog();
        $audit->expects(self::once())
            ->method('start')
            ->with(
                $admin,
                'owner.deactivate',
                'owner',
                42,
                'owner@example.com',
                'Repeated abuse report confirmed by support.',
                $request,
                ['isActive' => true],
            )
            ->willReturn($log);
        $audit->expects(self::once())
            ->method('finish')
            ->with($log, AdminAuditLog::OUTCOME_SUCCESS, ['isActive' => false]);

        $response = $controller->ownerToggle($owner, $request, $em, $audit);

        self::assertFalse($owner->isActive());
        self::assertSame('/app_admin_owner_show', $response->headers->get('Location'));
    }

    public function testDeletionIsDeniedBeforeServiceCallWhenConfirmationIsIncomplete(): void
    {
        $admin = $this->admin();
        $owner = $this->owner();
        $request = $this->request([
            '_token' => 'valid',
            'reason' => '',
            'target_email' => 'owner@example.com',
            'confirmation' => 'DELETE',
            'admin_password' => 'correct-password',
            'auth_code' => '123456',
        ]);
        $controller = $this->controller($admin, $request);

        $deletion = $this->createMock(AccountDeletionService::class);
        $deletion->expects(self::never())->method('delete');
        $audit = $this->createMock(AdminAuditService::class);
        $log = new AdminAuditLog();
        $audit->expects(self::once())->method('start')->willReturn($log);
        $audit->expects(self::once())
            ->method('finish')
            ->with($log, AdminAuditLog::OUTCOME_DENIED, null, 'A reason is required.');

        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $passwordHasher->expects(self::never())->method('isPasswordValid');
        $totp = $this->createMock(TotpAuthenticatorInterface::class);
        $totp->expects(self::never())->method('checkCode');

        $response = $controller->ownerDelete(
            $owner,
            $request,
            $deletion,
            $audit,
            $passwordHasher,
            $totp,
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('app_admin_owner_confirm', (string) $response->headers->get('Location'));
    }

    public function testDeletionRequiresValidAdminPasswordAndTwoFactorCode(): void
    {
        $admin = $this->admin(withTwoFactor: true);
        $owner = $this->owner();
        $request = $this->request([
            '_token' => 'valid',
            'reason' => 'Verified legal deletion request from the account owner.',
            'target_email' => 'owner@example.com',
            'confirmation' => 'DELETE',
            'admin_password' => 'correct-password',
            'auth_code' => '123456',
        ]);
        $controller = $this->controller($admin, $request);

        $blockedUntil = new \DateTimeImmutable('+30 days');
        $deletion = $this->createMock(AccountDeletionService::class);
        $deletion->expects(self::once())->method('delete')->with($owner)->willReturn($blockedUntil);

        $audit = $this->createMock(AdminAuditService::class);
        $log = new AdminAuditLog();
        $audit->expects(self::once())->method('start')->willReturn($log);
        $audit->expects(self::once())
            ->method('finish')
            ->with(
                $log,
                AdminAuditLog::OUTCOME_SUCCESS,
                ['emailBlockedUntil' => $blockedUntil->format(DATE_ATOM)],
            );

        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $passwordHasher->expects(self::once())
            ->method('isPasswordValid')
            ->with($admin, 'correct-password')
            ->willReturn(true);
        $totp = $this->createMock(TotpAuthenticatorInterface::class);
        $totp->expects(self::once())
            ->method('checkCode')
            ->with($admin, '123456')
            ->willReturn(true);

        $response = $controller->ownerDelete(
            $owner,
            $request,
            $deletion,
            $audit,
            $passwordHasher,
            $totp,
        );

        self::assertSame('/app_admin_owners', $response->headers->get('Location'));
    }

    public function testDeletionIsDeniedForInvalidAdministratorPassword(): void
    {
        $admin = $this->admin(withTwoFactor: true);
        $owner = $this->owner();
        $request = $this->request($this->validDeletionParameters());
        $controller = $this->controller($admin, $request);

        $deletion = $this->createMock(AccountDeletionService::class);
        $deletion->expects(self::never())->method('delete');
        $audit = $this->createMock(AdminAuditService::class);
        $log = new AdminAuditLog();
        $audit->method('start')->willReturn($log);
        $audit->expects(self::once())
            ->method('finish')
            ->with(
                $log,
                AdminAuditLog::OUTCOME_DENIED,
                null,
                'Your administrator password is incorrect.',
            );

        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $passwordHasher->method('isPasswordValid')->willReturn(false);
        $totp = $this->createMock(TotpAuthenticatorInterface::class);
        $totp->expects(self::never())->method('checkCode');

        $controller->ownerDelete(
            $owner,
            $request,
            $deletion,
            $audit,
            $passwordHasher,
            $totp,
        );
    }

    public function testDeletionIsDeniedForInvalidTwoFactorCode(): void
    {
        $admin = $this->admin(withTwoFactor: true);
        $owner = $this->owner();
        $request = $this->request($this->validDeletionParameters());
        $controller = $this->controller($admin, $request);

        $deletion = $this->createMock(AccountDeletionService::class);
        $deletion->expects(self::never())->method('delete');
        $audit = $this->createMock(AdminAuditService::class);
        $log = new AdminAuditLog();
        $audit->method('start')->willReturn($log);
        $audit->expects(self::once())
            ->method('finish')
            ->with(
                $log,
                AdminAuditLog::OUTCOME_DENIED,
                null,
                'The two-factor authentication code is invalid.',
            );

        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $passwordHasher->method('isPasswordValid')->willReturn(true);
        $totp = $this->createMock(TotpAuthenticatorInterface::class);
        $totp->method('checkCode')->willReturn(false);

        $controller->ownerDelete(
            $owner,
            $request,
            $deletion,
            $audit,
            $passwordHasher,
            $totp,
        );
    }

    private function controller(User $admin, Request $request): AdminController
    {
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')
            ->willReturnCallback(static fn(string $route): string => '/' . $route);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($admin);
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $container = new Container();
        $container->set('security.csrf.token_manager', $csrf);
        $container->set('router', $router);
        $container->set('security.token_storage', $tokenStorage);
        $container->set('request_stack', $requestStack);

        $controller = new AdminController();
        $controller->setContainer($container);

        return $controller;
    }

    private function request(array $parameters): Request
    {
        $request = Request::create('/admin/test', 'POST', $parameters, server: [
            'REMOTE_ADDR' => '203.0.113.10',
        ]);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function validDeletionParameters(): array
    {
        return [
            '_token' => 'valid',
            'reason' => 'Verified legal deletion request from the account owner.',
            'target_email' => 'owner@example.com',
            'confirmation' => 'DELETE',
            'admin_password' => 'correct-password',
            'auth_code' => '123456',
        ];
    }

    private function admin(bool $withTwoFactor = false): User
    {
        $admin = (new User())
            ->setEmail('admin@example.com')
            ->setRole('admin')
            ->setPassword('hash');
        $this->setId($admin, 1);
        if ($withTwoFactor) {
            $admin->setTotpSecret('totp-secret')->setBackupCodes(['backup-code']);
        }

        return $admin;
    }

    private function owner(): User
    {
        $owner = (new User())
            ->setEmail('owner@example.com')
            ->setRole('owner')
            ->setPassword('hash');
        $this->setId($owner, 42);

        return $owner;
    }

    private function setId(User $user, int $id): void
    {
        $property = new \ReflectionProperty(User::class, 'id');
        $property->setValue($user, $id);
    }
}
