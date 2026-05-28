<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Outbox;

enum OutboxStatus: string
{
    case Pending    = 'pending';
    case Processing = 'processing';
    case Sent       = 'sent';
    case Failed     = 'failed';
    case Dead       = 'dead';
}
