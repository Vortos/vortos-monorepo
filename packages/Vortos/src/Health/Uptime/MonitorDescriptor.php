<?php

declare(strict_types=1);

namespace Vortos\Health\Uptime;

use InvalidArgumentException;

/**
 * What to declare/sync with the external monitor. `key` is the stable identity used
 * for idempotent sync (one key, one provider monitor, re-syncable indefinitely);
 * `regions`/`responseTimeSloMs` are carried from day one (§6.5) so multi-region and
 * latency-SLO probing are additive later, never a breaking port change.
 */
final readonly class MonitorDescriptor
{
    /** @param list<string> $regions */
    public function __construct(
        public string $key,
        public string $name,
        public SyntheticJourney $journey,
        public int $intervalSeconds = 60,
        public array $regions = [],
        public ?int $responseTimeSloMs = null,
    ) {
        if ($key === '') {
            throw new InvalidArgumentException('MonitorDescriptor key must not be empty.');
        }
        if ($name === '') {
            throw new InvalidArgumentException('MonitorDescriptor name must not be empty.');
        }
        if ($intervalSeconds <= 0) {
            throw new InvalidArgumentException('MonitorDescriptor intervalSeconds must be > 0.');
        }
        foreach ($regions as $region) {
            if ($region === '') {
                throw new InvalidArgumentException('MonitorDescriptor regions must be non-empty strings.');
            }
        }
        if ($responseTimeSloMs !== null && $responseTimeSloMs <= 0) {
            throw new InvalidArgumentException('MonitorDescriptor responseTimeSloMs, if set, must be > 0.');
        }
    }
}
