<?php

namespace App\Tests\Service;

use App\Service\AdminAnalyticsService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class AdminAnalyticsServiceTest extends TestCase
{
    public function testDateIndexedSqlSeriesDoesNotBecomeNamedArguments(): void
    {
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllKeyValue')->willReturn([$today => '7']);
        $service = new AdminAnalyticsService($connection);
        $method = new \ReflectionMethod($service, 'dailySeries');

        $series = $method->invoke($service, 'scan_capture');

        self::assertCount(14, $series);
        self::assertSame(7, $series[13]['value']);
        self::assertSame(100, $series[13]['height']);
    }

    public function testEmptySeriesKeepsSafeGraphScale(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllKeyValue')->willReturn([]);
        $service = new AdminAnalyticsService($connection);
        $method = new \ReflectionMethod($service, 'dailySeries');

        $series = $method->invoke($service, 'scan_capture');

        self::assertCount(14, $series);
        self::assertSame(0, $series[13]['value']);
        self::assertSame(4, $series[13]['height']);
    }
}
