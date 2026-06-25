<?php

declare(strict_types=1);

namespace Vortos\Deploy\Target;

enum ActiveColor: string
{
    case Blue = 'blue';
    case Green = 'green';
    case None = 'none';

    public function opposite(): self
    {
        return match ($this) {
            self::Blue => self::Green,
            self::Green => self::Blue,
            self::None => self::Blue,
        };
    }
}
