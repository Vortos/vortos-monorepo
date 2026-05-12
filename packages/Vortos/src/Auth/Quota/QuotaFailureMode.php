<?php
declare(strict_types=1);

namespace Vortos\Auth\Quota;

enum QuotaFailureMode: string
{
    case FailClosed = 'fail_closed';
    case FailOpen = 'fail_open';
}
