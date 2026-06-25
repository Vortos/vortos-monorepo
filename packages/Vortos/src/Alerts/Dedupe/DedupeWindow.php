<?php

declare(strict_types=1);

namespace Vortos\Alerts\Dedupe;

use InvalidArgumentException;

final readonly class DedupeWindow
{
    public function __construct(public int $seconds)
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException('DedupeWindow seconds must be >= 1.');
        }
    }
}
