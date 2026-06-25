<?php

declare(strict_types=1);

namespace Vortos\Health\Uptime;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class MonitorStatus
{
    /**
     * @param list<string> $failingRegions
     */
    public function __construct(
        public string $monitorId,
        public MonitorState $state,
        public ?float $latencyMs,
        public DateTimeImmutable $lastCheckedAt,
        public array $failingRegions = [],
        public ?string $incidentId = null,
    ) {
        if ($monitorId === '') {
            throw new InvalidArgumentException('MonitorStatus monitorId must not be empty.');
        }
        if ($latencyMs !== null && $latencyMs < 0.0) {
            throw new InvalidArgumentException('MonitorStatus latencyMs must be >= 0.');
        }
        foreach ($failingRegions as $region) {
            if ($region === '') {
                throw new InvalidArgumentException('MonitorStatus failingRegions must be non-empty strings.');
            }
        }
    }

    public static function unknown(string $monitorId, ?DateTimeImmutable $at = null): self
    {
        return new self($monitorId, MonitorState::Unknown, null, $at ?? new DateTimeImmutable());
    }

    public function isUp(): bool
    {
        return $this->state === MonitorState::Up;
    }

    public function isUnknown(): bool
    {
        return $this->state === MonitorState::Unknown;
    }
}
