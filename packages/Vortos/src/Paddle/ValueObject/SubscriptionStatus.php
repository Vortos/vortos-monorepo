<?php

declare(strict_types=1);

namespace Vortos\Paddle\ValueObject;

enum SubscriptionStatus: string
{
    case Active   = 'active';
    case Trialing = 'trialing';
    case PastDue  = 'past_due';
    case Paused   = 'paused';
    case Canceled = 'canceled';
    case Inactive = 'inactive';
}
