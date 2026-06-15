<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Tenant;

/**
 * Process-static map of tenant-scoped entity class => tenant column.
 *
 * Populated ONCE at boot from a container parameter that {@see \Vortos\PersistenceOrm\DependencyInjection\Compiler\TenantScopedEntitiesPass}
 * computes at container-compile time by scanning entities for #[TenantScoped].
 *
 * Doctrine instantiates SQL filters without the DI container, so the filter
 * cannot be constructor-injected — it reads this registry instead. Because the
 * map is precomputed at compile time, lookups here are a plain array access with
 * zero reflection on the request path.
 */
final class TenantScopedEntityRegistry
{
    /** @var array<class-string, string> */
    private static array $map = [];

    /**
     * @param array<class-string, string> $map entityClass => tenant column
     */
    public static function load(array $map): void
    {
        self::$map = $map;
    }

    /**
     * The tenant column for $entityClass, or null if it is not tenant-scoped.
     */
    public static function columnFor(string $entityClass): ?string
    {
        return self::$map[$entityClass] ?? null;
    }

    public static function isScoped(string $entityClass): bool
    {
        return isset(self::$map[$entityClass]);
    }

    /** @return array<class-string, string> */
    public static function all(): array
    {
        return self::$map;
    }
}
