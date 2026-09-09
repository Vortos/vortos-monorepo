<?php

declare(strict_types=1);

namespace Vortos\AnalyticsPosthog\FlagSync;

/**
 * What a definition sync did (or, in dry-run, would do). Returned rather than logged,
 * because prod hides `info()` and an ops command that only logs its result tells the
 * operator nothing.
 */
final class FlagSyncReport
{
    /** @var list<string> */
    public array $created = [];

    /** @var list<string> */
    public array $updated = [];

    /** @var list<string> */
    public array $unchanged = [];

    /** @var array<string,string> flag key => failure reason */
    public array $failed = [];

    public function __construct(public readonly bool $dryRun = false) {}

    public function total(): int
    {
        return count($this->created) + count($this->updated) + count($this->unchanged) + count($this->failed);
    }

    public function changed(): int
    {
        return count($this->created) + count($this->updated);
    }

    public function hasFailures(): bool
    {
        return $this->failed !== [];
    }
}
