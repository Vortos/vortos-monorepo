<?php

declare(strict_types=1);

namespace Vortos\Paddle\ValueObject;

enum ReportStatus: string
{
    case Pending = 'pending';
    case Ready   = 'ready';
    case Failed  = 'failed';
    case Expired = 'expired';
}
