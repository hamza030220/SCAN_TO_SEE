<?php

namespace App\Tests\Service;

use App\Entity\DeletedEmailBlock;
use App\Repository\DeletedEmailBlockRepository;
use App\Service\EmailBlockService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class EmailBlockServiceTest extends TestCase
{
    public function testBlockNormalizesAndHashesEmailForThirtyDays(): void
    {
        $repository = $this->createMock(DeletedEmailBlockRepository::class);
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(['emailHash' => hash_hmac('sha256', 'owner@example.com', 'test-secret')])
            ->willReturn(null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(DeletedEmailBlock::class));
        $service = new EmailBlockService($repository, $em, 'test-secret');

        $block = $service->block('  Owner@Example.COM ');

        self::assertNotSame('owner@example.com', $block->getEmailHash());
        self::assertGreaterThan(new \DateTimeImmutable('+29 days'), $block->getBlockedUntil());
        self::assertLessThan(new \DateTimeImmutable('+31 days'), $block->getBlockedUntil());
    }

    public function testExpiredBlockIsRemovedAndNoLongerActive(): void
    {
        $block = (new DeletedEmailBlock())
            ->setEmailHash('irrelevant')
            ->setBlockedUntil(new \DateTimeImmutable('-1 minute'));
        $repository = $this->createMock(DeletedEmailBlockRepository::class);
        $repository->method('findOneBy')->willReturn($block);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('remove')->with($block);
        $em->expects(self::once())->method('flush');
        $service = new EmailBlockService($repository, $em, 'test-secret');

        self::assertNull($service->activeBlock('owner@example.com'));
    }
}
