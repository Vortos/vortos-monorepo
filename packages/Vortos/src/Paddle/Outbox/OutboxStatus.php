<?php

declare(strict_types=1);

namespace Vortos\Paddle\Outbox;

enum OutboxStatus: string
{
    case Pending   = 'pending';
    case Delivered = 'delivered';
    case Failed    = 'failed';
}
