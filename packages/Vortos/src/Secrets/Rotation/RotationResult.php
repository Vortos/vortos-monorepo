<?php

declare(strict_types=1);

namespace Vortos\Secrets\Rotation;

use DateTimeImmutable;
use Vortos\Secrets\Value\SecretVersion;

/**
 * The outcome of one rotation: the new version, the version it superseded (if any),
 * and when the previous version's grace window expires.
 *
 * {@see validVersions()} is the two-phase-rotation guarantee made concrete: it
 * returns the new version PLUS the previous version for as long as `now` is inside
 * the grace window — proving old + new both verify mid-rotation.
 */
final readonly class RotationResult
{
    public function __construct(
        public SecretVersion $newVersion,
        public ?SecretVersion $previousVersion,
        public ?DateTimeImmutable $graceExpiresAt,
    ) {}

    /** @return list<SecretVersion> */
    public function validVersions(DateTimeImmutable $now): array
    {
        $versions = [$this->newVersion];

        if ($this->previousVersion !== null
            && $this->graceExpiresAt !== null
            && $now < $this->graceExpiresAt
        ) {
            $versions[] = $this->previousVersion;
        }

        return $versions;
    }

    public function isPreviousStillValid(DateTimeImmutable $now): bool
    {
        return count($this->validVersions($now)) > 1;
    }
}
