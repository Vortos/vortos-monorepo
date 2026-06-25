<?php

declare(strict_types=1);

namespace Vortos\Observability\Slo;

use InvalidArgumentException;

/**
 * A time window an SLO objective is measured over (e.g. 30d rolling).
 */
final readonly class SloWindow
{
    public function __construct(
        public int $seconds,
    ) {
        if ($seconds < 60) {
            throw new InvalidArgumentException('SloWindow seconds must be >= 60.');
        }
    }

    public static function days(int $days): self
    {
        return new self($days * 86400);
    }

    public static function hours(int $hours): self
    {
        return new self($hours * 3600);
    }

    public static function minutes(int $minutes): self
    {
        return new self($minutes * 60);
    }
}
