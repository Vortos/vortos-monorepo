<?php

declare(strict_types=1);

namespace Vortos\Paddle\ValueObject;

enum TransactionStatus: string
{
    case Draft     = 'draft';
    case Ready     = 'ready';
    case Billed    = 'billed';
    case Paid      = 'paid';
    case Completed = 'completed';
    case Canceled  = 'canceled';
    case PastDue   = 'past_due';
}
