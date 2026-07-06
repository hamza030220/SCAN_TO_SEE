<?php

namespace App\Scheduler;

use App\Message\SubscriptionDailyCheck;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('subscription_reminders')]
class SubscriptionReminderSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(
            RecurringMessage::cron('0 8 * * *', new SubscriptionDailyCheck())
        );
    }
}
