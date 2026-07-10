<?php

declare(strict_types=1);

namespace Vortos\Deploy\Reclaim\Schedule;

use DateTimeZone;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Registry\StaticScheduleDefinition;
use Vortos\Scheduler\Schedule\Attribute\Scheduled;
use Vortos\Scheduler\Schedule\Policy\Jitter;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\RecurringTrigger;

/**
 * Framework-owned static schedule for the image-GC safety-net.
 *
 * Registered by DeployExtension only when BOTH vortos-scheduler and vortos-cqrs are installed (an
 * app without a scheduler still gets the full deploy-path reclaim; it just has no cadence sweep).
 * The daily fire runs {@see ReclaimImagesCommand}, which reclaims superseded release images + build
 * cache down to the reference-counted keep-set — the identical pass the deploy path runs, so disk
 * stays bounded even when no deploy has happened.
 *
 * Policy choices:
 *  - Daily at 04:00 UTC — low-traffic, and offset from the scheduler's own 03:00 run-prune so the
 *    two housekeeping sweeps do not fire in the same minute.
 *  - 15-minute jitter — reuses the existing Jitter policy to spread load.
 *  - OverlapPolicy::Skip — reclaim is idempotent, but two concurrent docker sweeps are wasteful.
 *  - skipMissed — a missed GC is harmless; tomorrow's fire reclaims the slightly larger backlog.
 *  - sensitive: false — reclaiming unused images is not a 4-eyes-gated action.
 */
#[Scheduled]
final class ImageGcSchedule implements StaticScheduleDefinition
{
    /** Reserved system schedule ID — do not reuse for any other schedule. */
    public const SCHEDULE_ID = '00000000-0000-4000-8000-0000000000d0';
    public const SCHEDULE_NAME = 'deploy-image-gc';

    public static function build(): Schedule
    {
        return new Schedule(
            id:        ScheduleId::fromString(self::SCHEDULE_ID),
            name:      self::SCHEDULE_NAME,
            source:    ScheduleSource::Static,
            trigger:   new RecurringTrigger('0 4 * * *', new DateTimeZone('UTC')),
            command:   new CommandSpec(ReclaimImagesCommand::class),
            misfire:   MisfirePolicy::skipMissed(),
            overlap:   OverlapPolicy::Skip,
            timezone:  new DateTimeZone('UTC'),
            jitter:    new Jitter(900),
            status:    ScheduleStatus::Active,
            tenantId:  null,
            sensitive: false,
            metadata:  ['misfire_policy_explicit' => 'true'],
        );
    }
}
