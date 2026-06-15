<?php

declare(strict_types=1);

namespace Vortos\Tenant\Attribute;

/**
 * Marks a repository as tenant-scoped — its reads are filtered and its writes
 * stamped by the current {@see \Vortos\Tenant\TenantContext}.
 *
 * Opt-in by design: global tables (signing keyrings, feature flags, plans) carry
 * no tenant column and must NOT be scoped, so scoping is never applied unless a
 * repository is explicitly marked.
 *
 *   #[TenantScoped]                       // uses the default 'tenant_id' column
 *   #[TenantScoped(column: 'org_id')]     // custom column
 *   final class InvoiceReadRepository extends DbalReadRepository { ... }
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class TenantScoped
{
    public function __construct(
        public readonly string $column = 'tenant_id',
    ) {
        if ($this->column === '') {
            throw new \InvalidArgumentException('TenantScoped column cannot be empty.');
        }
    }
}
