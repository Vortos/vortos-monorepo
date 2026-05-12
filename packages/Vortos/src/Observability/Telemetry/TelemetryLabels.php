<?php

declare(strict_types=1);

namespace Vortos\Observability\Telemetry;

final class TelemetryLabels
{
    private const MAX_LENGTH = 120;

    public static function classShortName(object|string $class): string
    {
        $className = is_object($class) ? $class::class : $class;
        $short = strrchr($className, '\\');

        return self::safe($short === false ? $className : substr($short, 1));
    }

    public static function dottedClass(object|string $class): string
    {
        $className = is_object($class) ? $class::class : $class;

        return self::safe(str_replace('\\', '.', $className));
    }

    public static function safe(string|int|float|bool|null $value): string
    {
        $value = (string) ($value ?? 'none');
        $value = preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $value) ?? 'invalid';
        $value = trim($value, '_');

        if ($value === '') {
            return 'none';
        }

        if (strlen($value) > self::MAX_LENGTH) {
            return substr($value, 0, self::MAX_LENGTH);
        }

        return $value;
    }

    public static function statusFamily(int $statusCode): string
    {
        if ($statusCode < 100 || $statusCode > 599) {
            return 'unknown';
        }

        return (int) floor($statusCode / 100) . 'xx';
    }

    public static function exceptionType(\Throwable $exception): string
    {
        return self::classShortName($exception::class);
    }

    public static function userHash(string $userId): string
    {
        return hash('xxh128', $userId);
    }
}
