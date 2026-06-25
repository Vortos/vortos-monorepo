<?php

declare(strict_types=1);

namespace Vortos\Secrets\Rotation;

use DateTimeImmutable;
use Vortos\Secrets\Value\SecretVersion;

/**
 * How a secret rotates, expressed entirely in seconds for precise, pinned-clock
 * testability.
 *
 * - `intervalSeconds`: how often a version is due for rotation.
 * - `gracePeriodSeconds`: how long the PREVIOUS version stays valid after a new one
 *   is issued (the two-phase overlap window — mirrors the flags SDK-key 24h grace).
 *   A rotation during a live deploy never causes a momentary auth outage because
 *   both the old and new versions verify during this window.
 * - `maxAgeSeconds`: a hard ceiling after which a version is forcibly invalid, even
 *   if still "within grace" by the interval math (defense in depth against a missed
 *   rotation).
 */
final readonly class RotationPolicy
{
    public function __construct(
        public int $intervalSeconds,
        public int $gracePeriodSeconds,
        public int $maxAgeSeconds,
    ) {
        if ($intervalSeconds <= 0) {
            throw RotationException::invalidPolicy('intervalSeconds must be > 0.');
        }
        if ($gracePeriodSeconds < 0) {
            throw RotationException::invalidPolicy('gracePeriodSeconds must be >= 0.');
        }
        if ($maxAgeSeconds <= 0) {
            throw RotationException::invalidPolicy('maxAgeSeconds must be > 0.');
        }
        if ($maxAgeSeconds < $intervalSeconds) {
            throw RotationException::invalidPolicy('maxAgeSeconds must be >= intervalSeconds.');
        }
    }

    public function isDue(SecretVersion $version, DateTimeImmutable $now): bool
    {
        $ageSeconds = $now->getTimestamp() - $version->createdAt->getTimestamp();

        return $ageSeconds >= $this->intervalSeconds;
    }

    /**
     * Whether a RETIRED version (the previous one, after a new rotation) is still
     * within its grace window at $now, given the moment it was superseded.
     */
    public function isWithinGrace(DateTimeImmutable $supersededAt, DateTimeImmutable $now): bool
    {
        $elapsed = $now->getTimestamp() - $supersededAt->getTimestamp();

        return $elapsed >= 0 && $elapsed < $this->gracePeriodSeconds;
    }

    public function isPastMaxAge(SecretVersion $version, DateTimeImmutable $now): bool
    {
        $ageSeconds = $now->getTimestamp() - $version->createdAt->getTimestamp();

        return $ageSeconds > $this->maxAgeSeconds;
    }
}
