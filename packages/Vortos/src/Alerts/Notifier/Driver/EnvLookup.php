<?php

declare(strict_types=1);

namespace Vortos\Alerts\Notifier\Driver;

/** Reads a destination secret/URL from the environment at use-time — never stored on a driver instance or logged. */
final class EnvLookup
{
    public static function string(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
