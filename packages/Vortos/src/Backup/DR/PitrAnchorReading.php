<?php

declare(strict_types=1);

namespace Vortos\Backup\DR;

use DateTimeImmutable;

/** The newest point-in-time recovery anchor and the WAL a recovery from it would replay. */
final readonly class PitrAnchorReading
{
    private function __construct(
        public ?DateTimeImmutable $anchorAt,
        public int $segmentsSinceAnchor,
        public float $segmentsPerSecond,
        public string $absence,
    ) {}

    public static function at(DateTimeImmutable $anchorAt, int $segmentsSinceAnchor, float $segmentsPerSecond): self
    {
        return new self($anchorAt, max(0, $segmentsSinceAnchor), max(0.0, $segmentsPerSecond), '');
    }

    /** No anchor exists; $why says so in words fit for an alert. */
    public static function none(string $why): self
    {
        return new self(null, 0, 0.0, $why);
    }
}
