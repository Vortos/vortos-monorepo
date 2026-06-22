<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags;

/**
 * A declarative, time-based rollout, resolved purely at evaluation time against an
 * injected clock — no background job required, fully deterministic for a given instant.
 *
 *  - `enableAt` / `disableAt`: scheduled on/off window (either may be null).
 *  - `stops`: a gradual ramp as ordered `{at, percentage}` points; the effective
 *    percentage is piecewise-linearly interpolated between stops (0 before the first
 *    stop, the last stop's percentage after the last). Combined with bucketing this
 *    auto-ramps e.g. 5%→100% over N days while keeping the prior cohort (sticky).
 *
 * All timestamps are compared as absolute instants (UTC-safe).
 */
final class RolloutSchedule
{
    /**
     * @param array<int,array{at:\DateTimeImmutable,percentage:int}> $stops ordered by time
     */
    public function __construct(
        public readonly ?\DateTimeImmutable $enableAt = null,
        public readonly ?\DateTimeImmutable $disableAt = null,
        public readonly array $stops = [],
    ) {}

    /** Whether the scheduled on/off window admits this instant. */
    public function isActiveAt(\DateTimeImmutable $now): bool
    {
        if ($this->enableAt !== null && $now < $this->enableAt) {
            return false;
        }
        if ($this->disableAt !== null && $now >= $this->disableAt) {
            return false;
        }

        return true;
    }

    /** The ramp percentage (0..100) at an instant, or null when no ramp is configured. */
    public function percentageAt(\DateTimeImmutable $now): ?int
    {
        if ($this->stops === []) {
            return null;
        }

        $first = $this->stops[0];
        if ($now < $first['at']) {
            return 0;
        }

        $last = $this->stops[count($this->stops) - 1];
        if ($now >= $last['at']) {
            return $last['percentage'];
        }

        // Find the bracketing pair and linearly interpolate.
        for ($i = 0; $i < count($this->stops) - 1; $i++) {
            $a = $this->stops[$i];
            $b = $this->stops[$i + 1];

            if ($now >= $a['at'] && $now < $b['at']) {
                $span     = $b['at']->getTimestamp() - $a['at']->getTimestamp();
                $elapsed  = $now->getTimestamp() - $a['at']->getTimestamp();
                $fraction = $span > 0 ? $elapsed / $span : 0.0;

                return (int) round($a['percentage'] + ($b['percentage'] - $a['percentage']) * $fraction);
            }
        }

        return $last['percentage'];
    }

    public function toArray(): array
    {
        return [
            'enable_at'  => $this->enableAt?->format(\DateTimeInterface::ATOM),
            'disable_at' => $this->disableAt?->format(\DateTimeInterface::ATOM),
            'stops'      => array_map(
                fn(array $s) => [
                    'at'         => $s['at']->format(\DateTimeInterface::ATOM),
                    'percentage' => $s['percentage'],
                ],
                $this->stops,
            ),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            enableAt:  isset($data['enable_at']) && $data['enable_at'] !== null ? new \DateTimeImmutable($data['enable_at']) : null,
            disableAt: isset($data['disable_at']) && $data['disable_at'] !== null ? new \DateTimeImmutable($data['disable_at']) : null,
            stops:     array_map(
                fn(array $s) => ['at' => new \DateTimeImmutable($s['at']), 'percentage' => (int) $s['percentage']],
                $data['stops'] ?? [],
            ),
        );
    }
}
