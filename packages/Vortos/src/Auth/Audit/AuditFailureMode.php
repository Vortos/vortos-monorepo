<?php
declare(strict_types=1);

namespace Vortos\Auth\Audit;

enum AuditFailureMode: string
{
    case FailClosed = 'fail_closed';
    case FailOpen = 'fail_open';
}
