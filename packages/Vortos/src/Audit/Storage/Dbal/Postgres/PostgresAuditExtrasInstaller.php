<?php

declare(strict_types=1);

namespace Vortos\Audit\Storage\Dbal\Postgres;

use Doctrine\DBAL\Connection;
use Vortos\Audit\Search\PostgresFtsSearchIndex;

/**
 * Installs the Postgres-only audit extras the portable Schema-diff migration seam cannot
 * express: the full-text-search GIN index and row-level-security tenant isolation.
 *
 * Every operation is idempotent (IF [NOT] EXISTS / DROP-then-CREATE), so the deploy can run
 * it every time. RLS uses the "restrict only when scoped" pattern: sessions that set the
 * `app.current_tenant` GUC (org read paths, via {@see AuditTenantGuc}) are confined to that
 * tenant's rows; sessions that leave it unset (the async write path, platform reads) are
 * unrestricted — so isolation is enforced exactly where it matters without breaking ingestion.
 */
final class PostgresAuditExtrasInstaller
{
    public const POLICY_NAME = 'audit_tenant_isolation';

    public function __construct(
        private readonly Connection $connection,
        private readonly string     $table = 'vortos_audit_events',
    ) {}

    /** Create the expression GIN index that turns full-text `search` from a scan into a probe. */
    public function installFtsIndex(): void
    {
        $index = $this->table . '_fts_gin';
        $this->connection->executeStatement(
            "CREATE INDEX IF NOT EXISTS {$index} ON {$this->table} USING gin (" . PostgresFtsSearchIndex::DOCUMENT_SQL . ')',
        );
    }

    public function dropFtsIndex(): void
    {
        $this->connection->executeStatement('DROP INDEX IF EXISTS ' . $this->table . '_fts_gin');
    }

    /** Enable + FORCE row-level security and (re)create the tenant-isolation policy. */
    public function enableRls(): void
    {
        $this->connection->executeStatement("ALTER TABLE {$this->table} ENABLE ROW LEVEL SECURITY");
        // FORCE so the policy also applies to the table owner (the app's DB role usually owns it).
        $this->connection->executeStatement("ALTER TABLE {$this->table} FORCE ROW LEVEL SECURITY");
        $this->connection->executeStatement('DROP POLICY IF EXISTS ' . self::POLICY_NAME . " ON {$this->table}");

        // Unset/empty GUC (writes, platform reads) → unrestricted. Set GUC (org reads) → that
        // tenant only. current_setting(..., true) is missing-safe (returns NULL when unset).
        $predicate =
            "(current_setting('app.current_tenant', true) IS NULL"
            . " OR current_setting('app.current_tenant', true) = ''"
            . " OR tenant_id = current_setting('app.current_tenant', true))";

        $this->connection->executeStatement(
            'CREATE POLICY ' . self::POLICY_NAME . " ON {$this->table} USING {$predicate} WITH CHECK {$predicate}",
        );
    }

    public function disableRls(): void
    {
        $this->connection->executeStatement('DROP POLICY IF EXISTS ' . self::POLICY_NAME . " ON {$this->table}");
        $this->connection->executeStatement("ALTER TABLE {$this->table} NO FORCE ROW LEVEL SECURITY");
        $this->connection->executeStatement("ALTER TABLE {$this->table} DISABLE ROW LEVEL SECURITY");
    }
}
