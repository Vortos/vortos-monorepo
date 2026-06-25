<?php

declare(strict_types=1);

namespace Vortos\Alerts\Dedupe;

enum AlertStateStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
}
