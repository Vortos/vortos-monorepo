<?php

declare(strict_types=1);

namespace Vortos\Tenant;

use Vortos\Tenant\Attribute\TenantScoped;

/**
 * Resolves the tenant column for a class from its {@see TenantScoped} attribute.
 *
 * Reflection is done once per class and memoized — repositories are long-lived
 * services, so this is effectively free after the first call.
 */
final class TenantScopeResolver
{
    /** @var array<class-string, string|null> */
    private static array $cache = [];

    /**
     * The tenant column for $class, or null if it is not tenant-scoped.
     *
     * @param object|class-string $class
     */
    public static function columnFor(object|string $class): ?string
    {
        $name = is_object($class) ? $class::class : $class;

        if (array_key_exists($name, self::$cache)) {
            return self::$cache[$name];
        }

        $attributes = (new \ReflectionClass($name))->getAttributes(TenantScoped::class);
        $column = $attributes === [] ? null : $attributes[0]->newInstance()->column;

        return self::$cache[$name] = $column;
    }

    public static function isScoped(object|string $class): bool
    {
        return self::columnFor($class) !== null;
    }
}
