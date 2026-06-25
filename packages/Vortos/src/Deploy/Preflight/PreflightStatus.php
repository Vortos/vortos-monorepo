<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight;

/**
 * The tri-state outcome of a single fail-closed preflight check.
 *
 * Unlike the advisory {@see \Vortos\Foundation\Doctor\DoctorStatus} (which has a
 * soft "warning"), the deploy doctor must *refuse rather than guess*: there is no
 * warning state. A check either proves the gate is satisfied ({@see Pass}), proves
 * the gate genuinely does not apply ({@see Skip}), or fails ({@see Fail}). Anything
 * undeterminable — a thrown check, a missing dependency, an "I don't know" — maps to
 * {@see Fail}. {@see Skip} is never a bypass.
 */
enum PreflightStatus: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Skip = 'skip';

    public function isClear(): bool
    {
        return $this !== self::Fail;
    }
}
