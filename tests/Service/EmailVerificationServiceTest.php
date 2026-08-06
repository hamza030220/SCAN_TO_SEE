<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\EmailVerificationService;
use App\Service\EntitlementService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\RouterInterface;

final class EmailVerificationServiceTest extends TestCase
{
    public function testVerificationActivatesTheFiveDayTrial(): void
    {
        $token = 'valid-verification-token';
        $user = (new User())
            ->setEmail('owner@example.com')
            ->setEmailVerificationTokenHash(hash('sha256', $token))
            ->setEmailVerificationExpiresAt(new \DateTimeImmutable('+1 hour'));

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(['emailVerificationTokenHash' => hash('sha256', $token)])
            ->willReturn($user);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(User::class)->willReturn($repository);
        $em->expects(self::once())->method('flush');

        $entitlements = $this->createMock(EntitlementService::class);
        $entitlements->expects(self::once())->method('hasSubscriptionRecord')->with($user)->willReturn(false);
        $entitlements->expects(self::once())
            ->method('startTrial')
            ->with($user)
            ->willReturnCallback(static function (User $verifiedUser): void {
                $verifiedUser
                    ->setTrialEndsAt(new \DateTimeImmutable('+5 days'))
                    ->setTrialAiUses(0);
            });

        $service = new EmailVerificationService(
            $em,
            $this->createMock(MailerInterface::class),
            $this->createMock(RouterInterface::class),
            $entitlements,
            'no-reply@example.com',
        );

        self::assertSame($user, $service->verify($token));
        self::assertTrue($user->isEmailVerified());
        self::assertTrue($user->isTrialActive());
        self::assertSame(0, $user->getTrialAiUses());
        self::assertNull($user->getEmailVerificationTokenHash());
        self::assertNull($user->getEmailVerificationExpiresAt());
    }

    public function testVerificationDoesNotGrantTrialWhenSubscriptionHistoryExists(): void
    {
        $token = 'paid-owner-token';
        $user = (new User())->setEmail('paid@example.com')
            ->setEmailVerificationTokenHash(hash('sha256', $token))
            ->setEmailVerificationExpiresAt(new \DateTimeImmutable('+1 hour'));
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($user);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(User::class)->willReturn($repository);
        $entitlements = $this->createMock(EntitlementService::class);
        $entitlements->expects(self::once())->method('hasSubscriptionRecord')->with($user)->willReturn(true);
        $entitlements->expects(self::never())->method('startTrial');
        $service = new EmailVerificationService(
            $em, $this->createMock(MailerInterface::class), $this->createMock(RouterInterface::class),
            $entitlements, 'no-reply@example.com',
        );

        self::assertSame($user, $service->verify($token));
        self::assertTrue($user->isEmailVerified());
        self::assertNull($user->getTrialEndsAt());
    }
}
