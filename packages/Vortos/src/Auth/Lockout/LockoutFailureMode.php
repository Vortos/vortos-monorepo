<?php
declare(strict_types=1);

namespace Vortos\Auth\Lockout;

enum LockoutFailureMode: string
{
    case FailClosed = 'fail_closed';
    case FailOpen = 'fail_open';
}
