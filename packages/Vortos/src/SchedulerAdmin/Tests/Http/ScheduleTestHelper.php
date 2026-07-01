<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\Tests\Http;

use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\RecurringTrigger;

final class ScheduleTestHelper
{
    public static function buildSchedule(
        ?ScheduleId    $id     = null,
        string         $name   = 'test-schedule',
        ScheduleSource $source = ScheduleSource::Dynamic,
        ScheduleStatus $status = ScheduleStatus::Active,
    ): Schedule {
        return new Schedule(
            id:        $id ?? ScheduleId::generate(),
            name:      $name,
            source:    $source,
            trigger:   new RecurringTrigger('0 * * * *', new \DateTimeZone('UTC')),
            command:   new CommandSpec('App\\Command\\TestCommand', []),
            misfire:   MisfirePolicy::skipMissed(),
            overlap:   OverlapPolicy::Skip,
            timezone:  new \DateTimeZone('UTC'),
            jitter:    null,
            status:    $status,
            tenantId:  null,
        );
    }
}
