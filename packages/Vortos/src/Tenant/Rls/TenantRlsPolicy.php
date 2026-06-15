<?php

declare(strict_types=1);

namespace Vortos\Tenant\Rls;

/**
 * Generates PostgreSQL Row-Level Security SQL for a tenant-scoped table —
 * Layer 3 of tenant isolation, the database-enforced backstop.
 *
 * Even if an application query forgets its tenant filter, RLS makes the database
 * physically refuse to return or write another tenant's rows. The policy keys off
 * a session GUC (`app.current_tenant`) that the DBAL TenantSessionBinder sets
 * per request / per transaction from the {@see \Vortos\Tenant\TenantContext}.
 *
 * Emit this SQL from a migration. The `, true` second argument to current_setting
 * makes a missing GUC return NULL (rather than error), so an unset tenant matches
 * no rows — fail closed.
 *
 * Usage in a migration:
 *
 *   $policy = new TenantRlsPolicy('invoices');
 *   $this->addSql($policy->columnDefaultSql());   // optional: DB stamps tenant_id
 *   foreach ($policy->enableSql() as $stmt) {
 *       $this->addSql($stmt);
 *   }
 */
final class TenantRlsPolicy
{
    public function __construct(
        public readonly string $table,
        public readonly string $tenantColumn = 'tenant_id',
        public readonly string $setting = 'app.current_tenant',
        public readonly string $policyName = 'tenant_isolation',
    ) {
        if ($table === '' || $tenantColumn === '' || $setting === '') {
            throw new \InvalidArgumentException('TenantRlsPolicy requires non-empty table, column and setting.');
        }
    }

    /**
     * Statements to enable RLS and install the isolation policy.
     *
     * FORCE ROW LEVEL SECURITY ensures the policy also applies to the table
     * owner (otherwise superuser/owner connections bypass RLS silently).
     *
     * @return list<string>
     */
    public function enableSql(): array
    {
        $predicate = sprintf(
            "%s::text = current_setting('%s', true)",
            $this->tenantColumn,
            $this->setting,
        );

        return [
            "ALTER TABLE {$this->table} ENABLE ROW LEVEL SECURITY",
            "ALTER TABLE {$this->table} FORCE ROW LEVEL SECURITY",
            sprintf(
                "CREATE POLICY %s ON %s USING (%s) WITH CHECK (%s)",
                $this->policyName,
                $this->table,
                $predicate,
                $predicate,
            ),
        ];
    }

    /**
     * Statements to remove the policy and disable RLS (rollback / down migration).
     *
     * @return list<string>
     */
    public function disableSql(): array
    {
        return [
            "DROP POLICY IF EXISTS {$this->policyName} ON {$this->table}",
            "ALTER TABLE {$this->table} NO FORCE ROW LEVEL SECURITY",
            "ALTER TABLE {$this->table} DISABLE ROW LEVEL SECURITY",
        ];
    }

    /**
     * Optional: let the database fill tenant_id from the session GUC on INSERT,
     * so application code never has to stamp it. Pairs with WITH CHECK above.
     */
    public function columnDefaultSql(): string
    {
        return sprintf(
            "ALTER TABLE %s ALTER COLUMN %s SET DEFAULT current_setting('%s', true)",
            $this->table,
            $this->tenantColumn,
            $this->setting,
        );
    }
}
