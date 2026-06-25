<?php
declare(strict_types=1);

namespace Vortos\Auth\RateLimit;

enum RateLimitFailureMode: string
{
    case FailClosed = 'fail_closed';
    case FailOpen = 'fail_open';
}
