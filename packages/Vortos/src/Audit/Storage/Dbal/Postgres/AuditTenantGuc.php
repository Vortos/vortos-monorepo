<?php

declare(strict_types=1);

namespace Vortos\Audit\Storage\Dbal\Postgres;

use Doctrine\DBAL\Connection;

/**
 * Sets the `app.current_tenant` Postgres GUC that the RLS policy keys on.
 *
 * The org read path calls {@see scopeToTenant()} at the start of a request so the DB itself
 * confines the session to that tenant's audit rows — a backstop behind the query-layer
 * scoping. {@see clear()} removes the scope for connections that outlive the request (pooled).
 * A no-op off Postgres, so callers can invoke it unconditionally.
 */
final class AuditTenantGuc
{
    public function __construct(private readonly Connection $connection) {}

    public function scopeToTenant(string $tenantId): void
    {
        $this->set($tenantId);
    }

    public function clear(): void
    {
        $this->set('');
    }

    private function set(string $value): void
    {
        if (!$this->isPostgres()) {
            return;
        }
        // set_config(name, value, is_local=false) → session scope; the app clears it per request.
        $this->connection->executeStatement("SELECT set_config('app.current_tenant', :v, false)", ['v' => $value]);
    }

    private function isPostgres(): bool
    {
        try {
            return str_contains(strtolower($this->connection->getDatabasePlatform()::class), 'postgre');
        } catch (\Throwable) {
            return false;
        }
    }
}
