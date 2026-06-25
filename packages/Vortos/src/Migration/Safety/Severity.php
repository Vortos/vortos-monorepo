<?php

declare(strict_types=1);

namespace Vortos\Migration\Safety;

enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';

    public static function safeDefault(): self
    {
        return self::Error;
    }

    public function blocksCI(): bool
    {
        return $this === self::Error;
    }

    public function sortOrder(): int
    {
        return match ($this) {
            self::Error => 0,
            self::Warning => 1,
            self::Info => 2,
        };
    }
}
