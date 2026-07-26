<?php

declare(strict_types=1);

namespace Vortos\Alerts\Schedule;

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
 * Framework-owned static schedule that drives alert evaluation.
 *
 * Policy choices:
 *  - 60s interval — an alert can never be fresher than its evaluation cadence, and the rules carry
 *    their own forDuration, so evaluating often does not make them fire sooner; it makes them fire
 *    on time.
 *  - No jitter — even spacing keeps forDuration windows honest.
 *  - OverlapPolicy::Skip — a slow tick must not queue a second evaluation behind it and double-
 *    dispatch; the dedupe layer would absorb it, but the DB work is wasted.
 *  - skipMissed — alerting is about now. A backfilled evaluation of a window that has already
 *    passed would page someone about a condition that has since resolved.
 */
#[Scheduled]
final class EvaluateAlertSourcesSchedule implements StaticScheduleDefinition
{
    /** Reserved system schedule ID — do not reuse for any other schedule. */
    public const SCHEDULE_ID = '00000000-0000-4000-8000-0000000000e1';
    public const SCHEDULE_NAME = 'alerts-evaluate-sources';

    public static function build(): Schedule
    {
        return new Schedule(
            id:        ScheduleId::fromString(self::SCHEDULE_ID),
            name:      self::SCHEDULE_NAME,
            source:    ScheduleSource::Static,
            trigger:   new IntervalTrigger(60),
            command:   new CommandSpec(EvaluateAlertSourcesCommand::class),
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
