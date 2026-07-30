<?php

namespace App\Tests\Service;

use App\Entity\AdminAuditLog;
use App\Entity\User;
use App\Service\AdminAuditService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class AdminAuditServiceTest extends TestCase
{
    public function testStartStoresAttributionWithoutSensitiveCredentials(): void
    {
        $admin = (new User())->setEmail('admin@example.com')->setRole('admin');
        $request = Request::create('/admin/owners/42/delete', 'POST', server: [
            'REMOTE_ADDR' => '203.0.113.10',
        ]);

        $persisted = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (AdminAuditLog $log) use (&$persisted): void {
                $persisted = $log;
            });
        $em->expects(self::once())->method('flush');

        $log = (new AdminAuditService($em))->start(
            $admin,
            'owner.delete',
            'owner',
            42,
            'owner@example.com',
            'Verified owner request.',
            $request,
            ['isActive' => true],
        );

        self::assertSame($log, $persisted);
        self::assertSame('admin@example.com', $log->getActorEmail());
        self::assertSame('owner.delete', $log->getAction());
        self::assertSame('owner@example.com', $log->getTargetLabel());
        self::assertSame('Verified owner request.', $log->getReason());
        self::assertSame('203.0.113.10', $log->getIpAddress());
        self::assertSame(['isActive' => true], $log->getBeforeState());
        self::assertSame(AdminAuditLog::OUTCOME_STARTED, $log->getOutcome());
    }

    public function testFinishRecordsOutcomeAndTruncatesInternalError(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $log = new AdminAuditLog();
        $longError = str_repeat('x', 300);

        (new AdminAuditService($em))->finish(
            $log,
            AdminAuditLog::OUTCOME_FAILED,
            ['isActive' => false],
            $longError,
        );

        self::assertSame(AdminAuditLog::OUTCOME_FAILED, $log->getOutcome());
        self::assertSame(['isActive' => false], $log->getAfterState());
        self::assertSame(255, mb_strlen((string) $log->getErrorMessage()));
        self::assertNotNull($log->getCompletedAt());
    }
}
