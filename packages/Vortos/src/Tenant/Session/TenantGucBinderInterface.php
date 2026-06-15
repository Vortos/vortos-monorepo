<?php

declare(strict_types=1);

namespace Vortos\Tenant\Session;

/**
 * Binds the ambient tenant to the database session variable
 * (`app.current_tenant`) that both the ORM tenant filter (Layer 2) and
 * Row-Level Security (Layer 3) read.
 *
 * DB-agnostic on purpose: the auth layer depends only on this interface, while
 * each persistence adapter (ORM, DBAL) provides the concrete, connection-bound
 * implementation. Using a single session variable as the source of truth keeps
 * the generated SQL tenant-invariant — one cached query plan shared across every
 * tenant — and guarantees the app-level filter and RLS can never disagree.
 */
interface TenantGucBinderInterface
{
    /**
     * Bind the current tenant for the whole request/session.
     *
     * MUST be called once per request after the tenant is resolved — and MUST
     * reset the variable when no tenant is present, so a pooled/worker
     * connection cannot leak a previous request's tenant.
     */
    public function bindSession(): void;

    /**
     * Bind the current tenant for the duration of the active transaction only
     * (transaction-scoped; auto-cleared on commit/rollback). Called at BEGIN by
     * the unit of work as defence-in-depth for writes.
     */
    public function bindLocal(): void;
}
