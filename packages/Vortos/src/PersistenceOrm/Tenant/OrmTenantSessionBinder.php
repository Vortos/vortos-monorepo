<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Tenant;

use Doctrine\ORM\EntityManagerInterface;
use Vortos\Tenant\Session\TenantGucBinderInterface;
use Vortos\Tenant\TenantContext;

/**
 * Binds the ambient tenant to the PostgreSQL session variable read by the ORM
 * {@see TenantFilter} and by Row-Level Security — the glue between
 * {@see TenantContext} (Layer 1) and Layers 2/3 on the ORM path.
 *
 * Uses set_config('app.current_tenant', value, is_local):
 *   - bindSession() — is_local = false, lives for the connection/session. Called
 *     once per request; resets to '' when no tenant so a reused worker
 *     connection cannot leak the previous request's tenant.
 *   - bindLocal()   — is_local = true, transaction-scoped, auto-cleared on
 *     commit/rollback. Called at BEGIN by OrmUnitOfWork (write defence).
 *
 * In system scope no tenant is bound (the variable is reset). Cross-tenant
 * system work runs under a DB role with BYPASSRLS and uses
 * {@see TenantOrm::systemScope()} to drop the read filter.
 */
final class OrmTenantSessionBinder implements TenantGucBinderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenantContext,
    ) {}

    public function bindSession(): void
    {
        $this->bind($this->resolveTenant(), local: false);
    }

    public function bindLocal(): void
    {
        $this->bind($this->resolveTenant(), local: true);
    }

    /**
     * The concrete tenant to bind, or '' to reset (no tenant / system scope).
     */
    private function resolveTenant(): string
    {
        $decision = $this->tenantContext->scopingDecision();

        return ($decision === null || $decision === TenantContext::SYSTEM) ? '' : $decision;
    }

    private function bind(string $value, bool $local): void
    {
        $this->em->getConnection()->executeStatement(
            'SELECT set_config(?, ?, ?)',
            [TenantFilter::SETTING, $value, $local ? 1 : 0],
        );
    }
}
