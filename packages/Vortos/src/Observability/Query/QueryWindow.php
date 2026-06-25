<?php

declare(strict_types=1);

namespace Vortos\Observability\Query;

final readonly class QueryWindow
{
    public function __construct(
        public int $lookbackSeconds,
        public int $stepSeconds,
    ) {
        if ($this->stepSeconds < 1) {
            throw new \InvalidArgumentException(sprintf('QueryWindow step must be >= 1s, got %d.', $this->stepSeconds));
        }
        if ($this->lookbackSeconds < $this->stepSeconds) {
            throw new \InvalidArgumentException(sprintf(
                'QueryWindow lookback (%d) must be >= step (%d).',
                $this->lookbackSeconds,
                $this->stepSeconds,
            ));
        }
    }

    public static function ofMinutes(int $lookbackMinutes, int $stepSeconds = 15): self
    {
        return new self($lookbackMinutes * 60, $stepSeconds);
    }

    public function startTimestamp(int $nowTimestamp): int
    {
        return $nowTimestamp - $this->lookbackSeconds;
    }
}
