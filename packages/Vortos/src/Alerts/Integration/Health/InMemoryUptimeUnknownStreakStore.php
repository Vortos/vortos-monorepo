<?php

declare(strict_types=1);

namespace Vortos\Alerts\Integration\Health;

final class InMemoryUptimeUnknownStreakStore implements UptimeUnknownStreakStoreInterface
{
    /** @var array<string, int> */
    private array $streaks = [];

    public function increment(string $monitorId): int
    {
        $this->streaks[$monitorId] = ($this->streaks[$monitorId] ?? 0) + 1;

        return $this->streaks[$monitorId];
    }

    public function reset(string $monitorId): void
    {
        unset($this->streaks[$monitorId]);
    }
}
