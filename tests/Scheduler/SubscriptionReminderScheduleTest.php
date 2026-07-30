<?php

namespace App\Tests\Scheduler;

use App\Scheduler\SubscriptionReminderSchedule;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;

class SubscriptionReminderScheduleTest extends TestCase
{
    public function testDailyCheckUsesNativeDailyTriggerAtEight(): void
    {
        $messages = (new SubscriptionReminderSchedule())
            ->getSchedule()
            ->getRecurringMessages();

        self::assertCount(1, $messages);

        $trigger = $messages[0]->getTrigger();
        self::assertInstanceOf(PeriodicalTrigger::class, $trigger);
        self::assertSame('every 1 day', (string) $trigger);

        $now = new \DateTimeImmutable();
        $nextRun = $trigger->getNextRunDate($now);

        self::assertNotNull($nextRun);
        self::assertGreaterThan($now, $nextRun);
        self::assertSame('08:00', $nextRun->format('H:i'));
    }
}
