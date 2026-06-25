<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover;

final class ReconcileRateLimiter
{
    private bool $bootBypassUsed = false;

    public function __construct(
        private readonly RateLimitStateStoreInterface $stateStore,
        private readonly int $minIntervalSeconds = 10,
    ) {
        if ($minIntervalSeconds < 1) {
            throw new \InvalidArgumentException(sprintf('Min interval must be >= 1, got %d.', $minIntervalSeconds));
        }
    }

    public function allow(string $env): bool
    {
        if (!$this->bootBypassUsed) {
            return true;
        }

        $last = $this->stateStore->loadLastReloadTimestamp($env);
        if ($last === null) {
            return true;
        }

        return (microtime(true) - $last) >= $this->minIntervalSeconds;
    }

    public function record(string $env): void
    {
        $this->stateStore->saveLastReloadTimestamp($env, microtime(true));
        $this->bootBypassUsed = true;
    }

    public function bootBypassUsed(): bool
    {
        return $this->bootBypassUsed;
    }

    public function minIntervalSeconds(): int
    {
        return $this->minIntervalSeconds;
    }
}
