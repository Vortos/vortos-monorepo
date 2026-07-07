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
        );
    }
}
