<?php
declare(strict_types=1);

namespace Vortos\Auth\Lockout;

final class LockoutCheckResult
{
    private function __construct(
        public readonly bool $locked,
        public readonly bool $unavailable,
    ) {}

    public static function locked(): self
    {
        return new self(true, false);
    }

    public static function notLocked(): self
    {
        return new self(false, false);
    }

    public static function unavailable(): self
    {
        return new self(false, true);
    }
}
