<?php

declare(strict_types=1);

namespace Vortos\Migration\Schema;

enum MigrationPhase: string
{
    case Expand = 'expand';
    case Contract = 'contract';

    public function isDestructive(): bool
    {
        return $this === self::Contract;
    }

    public static function safeDefault(): self
    {
        return self::Expand;
    }
}
