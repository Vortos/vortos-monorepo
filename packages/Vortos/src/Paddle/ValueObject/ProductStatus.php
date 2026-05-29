<?php

declare(strict_types=1);

namespace Vortos\Paddle\ValueObject;

enum ProductStatus: string
{
    case Active   = 'active';
    case Archived = 'archived';
}
