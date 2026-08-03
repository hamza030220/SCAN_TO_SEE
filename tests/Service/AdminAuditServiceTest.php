<?php

namespace App\Tests\Service;

use App\Entity\AdminAuditLog;
use App\Entity\User;
use App\Service\AdminAuditService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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

        $log = (new AdminAuditService($em, $this->createMock(LoggerInterface::class)))->start(
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

        $result = (new AdminAuditService($em, $this->createMock(LoggerInterface::class)))->finish(
            $log,
            AdminAuditLog::OUTCOME_FAILED,
            ['isActive' => false],
            $longError,
        );

        self::assertTrue($result);
        self::assertSame(AdminAuditLog::OUTCOME_FAILED, $log->getOutcome());
        self::assertSame(['isActive' => false], $log->getAfterState());
        self::assertSame(255, mb_strlen((string) $log->getErrorMessage()));
        self::assertNotNull($log->getCompletedAt());
    }

    public function testFinishUpdatesPersistedAuditDirectlyWithoutFlushingTheUnitOfWork(): void
    {
        $log = new AdminAuditLog();
        $this->setAuditId($log, 42);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::stringContains('UPDATE admin_audit_log'),
                self::callback(static function (array $parameters): bool {
                    return $parameters['id'] === 42
                        && $parameters['outcome'] === AdminAuditLog::OUTCOME_SUCCESS
                        && $parameters['after_state'] === '{"deleted":true}'
                        && $parameters['error_message'] === null;
                }),
            )
            ->willReturn(1);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');
        $em->method('getConnection')->willReturn($connection);

        $service = new AdminAuditService($em, $this->createMock(LoggerInterface::class));

        self::assertTrue($service->finish(
            $log,
            AdminAuditLog::OUTCOME_SUCCESS,
            ['deleted' => true],
        ));
    }

    public function testFinishLogsFailureInsteadOfBreakingACompletedAction(): void
    {
        $log = new AdminAuditLog();
        $this->setAuditId($log, 43);

        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willThrowException(new \RuntimeException('Database unavailable'));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                'Administrator audit log could not be finalized.',
                self::callback(static fn (array $context): bool => $context['audit_id'] === 43),
            );

        $service = new AdminAuditService($em, $logger);

        self::assertFalse($service->finish($log, AdminAuditLog::OUTCOME_SUCCESS));
    }

    private function setAuditId(AdminAuditLog $log, int $id): void
    {
        $property = new \ReflectionProperty(AdminAuditLog::class, 'id');
        $property->setValue($log, $id);
    }
}
