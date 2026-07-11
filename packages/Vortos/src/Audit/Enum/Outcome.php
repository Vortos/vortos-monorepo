<?php

declare(strict_types=1);

namespace Vortos\Audit\Enum;

/**
 * Whether the audited action succeeded, was denied by policy, or errored.
 *
 * Denied/errored attempts are first-class audit material — an audit trail that only
 * records successes hides exactly the events security reviews care about.
 */
enum Outcome: string
{
    case Allowed = 'allowed';
    case Denied  = 'denied';
    case Error   = 'error';
}
