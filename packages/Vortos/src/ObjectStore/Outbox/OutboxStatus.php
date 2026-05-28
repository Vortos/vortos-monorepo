<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Outbox;

enum OutboxStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Done = 'done';
    case Dead = 'dead';
}
