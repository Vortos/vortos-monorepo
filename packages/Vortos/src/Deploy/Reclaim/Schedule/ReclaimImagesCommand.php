<?php

declare(strict_types=1);

namespace Vortos\Deploy\Reclaim\Schedule;

use Vortos\Domain\Command\CommandInterface;
use Vortos\Scheduler\Security\Attribute\SchedulableCommand;

/**
 * Fired on a cadence by {@see ImageGcSchedule} — the scheduled safety-net layer of image GC.
 *
 * The deploy path already reclaims after every deploy attempt (success AND failure); this schedule
 * closes the remaining gap: disk accumulation with NO deploy in between (a deploy process killed
 * before it could reclaim, images pulled out-of-band, build-cache creep). It runs the identical
 * reference-counted {@see \Vortos\Deploy\Driver\Docker\ImageReclaimer} pass, so it can never remove the
 * live release or a rollback target.
 *
 * Carries only the target environment — the handler reads the current prune policy and the release-
 * authoritative digests at execution time, never from a snapshot taken when the schedule registered.
 * Idempotent: exactly-once dispatch is already guaranteed by the scheduler fire-ledger, and reclaim
 * itself is a no-op when there is nothing left to remove.
 */
#[SchedulableCommand]
final readonly class ReclaimImagesCommand implements CommandInterface
{
    public function __construct(
        public string $environment = 'production',
    ) {}

    public function idempotencyKey(): ?string
    {
        return null;
    }
}
