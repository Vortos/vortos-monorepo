<?php

declare(strict_types=1);

namespace Vortos\Backup\Config;

use Vortos\Backup\Domain\RetentionPolicy;

/**
 * R8-6 (A7): declarative GFS retention as config, replacing the DI-service-override-only surface.
 * Tracks whether {@see hourly()} was set explicitly so {@see BackupConfig} knows when it may derive
 * the hourly bucket from the declared backup cadence instead of the (lossy) hard default of 0.
 */
final class RetentionBuilder
{
    private int $hourly = 0;
    private bool $hourlyExplicit = false;
    private int $daily = 7;
    private int $weekly = 4;
    private int $monthly = 6;
    private int $yearly = 1;
    private ?int $maxAgeDays = null;
    private int $minKeepFloor = 1;
    private ?int $walRetentionDays = null;

    public function hourly(int $count): self
    {
        $this->hourly = $count;
        $this->hourlyExplicit = true;

        return $this;
    }

    public function daily(int $count): self
    {
        $this->daily = $count;

        return $this;
    }

    public function weekly(int $count): self
    {
        $this->weekly = $count;

        return $this;
    }

    public function monthly(int $count): self
    {
        $this->monthly = $count;

        return $this;
    }

    public function yearly(int $count): self
    {
        $this->yearly = $count;

        return $this;
    }

    public function maxAgeDays(?int $days): self
    {
        $this->maxAgeDays = $days;

        return $this;
    }

    /**
     * How far back point-in-time recovery must reach, independent of how long restore points are kept.
     *
     * Left unset, WAL is retained back to the OLDEST retained restore point — so the WAL window is a
     * side effect of restore-point retention rather than a decision. That conflates two different
     * questions. Restore-point retention answers "how far back can I recover AT ALL"; this answers
     * "how far back can I recover TO AN ARBITRARY INSTANT". They are rarely the same number: a
     * mistake found weeks later is served fine by the nearest periodic dump, while to-the-second
     * recovery is only ever wanted for something recent.
     *
     * Separating them is what pgBackRest does with `repo-retention-archive` versus
     * `repo-retention-full`, and for the same reason: WAL volume tracks write ACTIVITY while restore
     * points track database SIZE, so tying the two together makes the cheap knob unreachable.
     *
     * The window is a floor, never a ceiling. {@see \Vortos\Backup\Service\RetentionEnforcer}
     * anchors pruning to the newest retained base at or before the cutoff, so the effective window
     * is whatever is needed to keep that base replayable — it can exceed this value (a weekly base
     * cadence with a 1-day window still keeps up to a week of WAL) but can never fall short of it.
     */
    public function walRetentionDays(?int $days): self
    {
        if ($days !== null && $days < 1) {
            throw new \InvalidArgumentException('Retention walRetentionDays must be >= 1 or null.');
        }
        $this->walRetentionDays = $days;

        return $this;
    }

    public function minKeepFloor(int $floor): self
    {
        $this->minKeepFloor = $floor;

        return $this;
    }

    public function hourlyWasSetExplicitly(): bool
    {
        return $this->hourlyExplicit;
    }

    /**
     * Build the immutable policy. When $derivedHourly > 0 and the app did not set hourly explicitly,
     * the derived value is used (R8-6 A7 cadence derivation).
     */
    public function build(int $derivedHourly = 0): RetentionPolicy
    {
        $hourly = $this->hourlyExplicit ? $this->hourly : ($derivedHourly > 0 ? $derivedHourly : $this->hourly);

        return new RetentionPolicy(
            hourly: $hourly,
            daily: $this->daily,
            weekly: $this->weekly,
            monthly: $this->monthly,
            yearly: $this->yearly,
            maxAgeDays: $this->maxAgeDays,
            minKeepFloor: $this->minKeepFloor,
            walRetentionDays: $this->walRetentionDays,
        );
    }
}
