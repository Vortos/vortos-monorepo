<?php

declare(strict_types=1);

namespace Vortos\Paddle\ValueObject;

enum DiscountType: string
{
    case Flat        = 'flat';
    case FlatPerSeat = 'flat_per_seat';
    case Percentage  = 'percentage';
}
