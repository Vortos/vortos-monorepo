<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Tenant;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Doctrine SQL filter that scopes every read of a #[TenantScoped] entity to the
 * current tenant — Layer 2 (reads) of tenant isolation on the ORM path.
 *
 * It constrains scoped entities to the session variable rather than a literal:
 *
 *     {alias}.{tenant_col}::text = current_setting('app.current_tenant', true)
 *
 * Why the session variable and not a bound/inlined tenant value:
 *   - Doctrine inlines filter parameters as literals, which would produce a
 *     distinct cached SQL string and a distinct Postgres plan PER TENANT.
 *     Referencing current_setting() keeps the SQL identical for all tenants —
 *     one query-cache entry, one shared prepared-statement plan. Maximal reuse.
 *   - It is the exact same variable RLS reads, so the app filter and the
 *     database policy can never drift.
 *
 * When the variable is unset (no tenant established) current_setting(..., true)
 * returns NULL, the predicate is NULL, and no rows match — fail closed.
 *
 * The set of scoped entities is precomputed at compile time and read from
 * {@see TenantScopedEntityRegistry} (no per-query reflection). The session
 * variable is set per request by the tenant GUC binder.
 */
final class TenantFilter extends SQLFilter
{
    public const NAME = 'vortos_tenant';
    public const SETTING = 'app.current_tenant';

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        $column = TenantScopedEntityRegistry::columnFor($targetEntity->getName());
        if ($column === null) {
            return '';
        }

        return sprintf(
            "%s.%s::text = current_setting(%s, true)",
            $targetTableAlias,
            $column,
            $this->getConnection()->quote(self::SETTING),
        );
    }
}
