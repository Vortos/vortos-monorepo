<?php

declare(strict_types=1);

namespace Vortos\Paddle\ValueObject;

enum AdjustmentAction: string
{
    case Refund = 'refund';
    case Credit = 'credit';
}
