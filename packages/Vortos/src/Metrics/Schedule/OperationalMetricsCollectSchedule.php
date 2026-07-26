<?php

declare(strict_types=1);

namespace Vortos\Metrics\Schedule;

use DateTimeZone;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Registry\StaticScheduleDefinition;
use Vortos\Scheduler\Schedule\Attribute\Scheduled;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;

/**
 * Framework-owned static schedule that keeps the operational messaging gauges alive under push
 * metric adapters. Registered by MetricsExtension only when the adapter is push-shaped AND both
 * vortos-scheduler and vortos-cqrs are installed (see MetricsExtension::registerCollectSchedule).
 *
 * Policy choices:
 *  - 60s interval — these gauges back the DLQ/backlog alerts, and alert latency cannot be better
 *    than sample latency. 60s is also the scheduler's own tick, so it costs one fire per tick and
 *    ~1 440 run-ledger rows/day, which config/scheduler.php's retention already accounts for.
 *  - IntervalTrigger, not RecurringTrigger — a gauge series wants an even cadence so rate/step
 *    queries and "no data" alert conditions behave predictably.
 *  - No jitter, for the same reason: jitter is for spreading load spikes, but it would make the
 *    sampling interval uneven and cause spurious no-data gaps at tight alert windows.
 *  - OverlapPolicy::Skip — the collector is a handful of aggregate SELECTs; if one run is still
 *    going, the next sample is better skipped than queued behind it.
 *  - skipMissed — a stale sample is worse than no sample. Never backfill a gauge.
 */
#[Scheduled]
final class OperationalMetricsCollectSchedule implements StaticScheduleDefinition
{
    /** Reserved system schedule ID — do not reuse for any other schedule. */
    public const SCHEDULE_ID = '00000000-0000-4000-8000-0000000000e0';
    public const SCHEDULE_NAME = 'metrics-operational-collect';

    public static function build(): Schedule
    {
        return new Schedule(
            id:        ScheduleId::fromString(self::SCHEDULE_ID),
            name:      self::SCHEDULE_NAME,
            source:    ScheduleSource::Static,
            trigger:   new IntervalTrigger(60),
            command:   new CommandSpec(CollectOperationalMetricsCommand::class),
            misfire:   MisfirePolicy::skipMissed(),
            overlap:   OverlapPolicy::Skip,
            timezone:  new DateTimeZone('UTC'),
            jitter:    null,
            status:    ScheduleStatus::Active,
            tenantId:  null,
            sensitive: false,
            metadata:  ['misfire_policy_explicit' => 'true'],
        );
    }
}
