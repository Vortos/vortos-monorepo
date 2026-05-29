<?php

declare(strict_types=1);

namespace Vortos\Paddle\ValueObject;

enum CustomerStatus: string
{
    case Active   = 'active';
    case Archived = 'archived';
}
