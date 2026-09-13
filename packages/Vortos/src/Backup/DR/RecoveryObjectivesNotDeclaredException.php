<?php

declare(strict_types=1);

namespace Vortos\Backup\DR;

use RuntimeException;

/**
 * Something that exists to measure recovery against an objective was asked for, and no objective was
 * declared. Thrown rather than defaulted: the objectives used to come from two env vars with built-in
 * fallbacks (300 s / 1800 s), so an installation could "have" an RTO nobody had ever chosen.
 */
final class RecoveryObjectivesNotDeclaredException extends RuntimeException
{
    public static function create(): self
    {
        return new self(
            'No recovery objectives are declared. Add ->objectives(rpoSeconds: …, rtoSeconds: …) to '
            . 'config/backup.php: restore drills are judged against the RTO, and the '
            . 'recovery-point-objective / recovery-time-objective probes page on both.',
        );
    }
}
