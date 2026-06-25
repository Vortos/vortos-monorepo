<?php

declare(strict_types=1);

namespace Vortos\Release\Version;

enum BumpLevel: string
{
    case None = 'none';
    case Patch = 'patch';
    case Minor = 'minor';
    case Major = 'major';

    public static function max(self $a, self $b): self
    {
        return self::ordinal($a) >= self::ordinal($b) ? $a : $b;
    }

    private static function ordinal(self $level): int
    {
        return match ($level) {
            self::None => 0,
            self::Patch => 1,
            self::Minor => 2,
            self::Major => 3,
        };
    }
}
