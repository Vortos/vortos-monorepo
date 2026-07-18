<?php

declare(strict_types=1);

namespace Vortos\Search\Index\Dbal\Postgres;

use Doctrine\DBAL\Connection;

/**
 * Installs the Postgres-only search extras the portable Schema-diff migration can't express:
 * the weighted generated `search_vector` tsvector column, its GIN index, the pg_trgm extension
 * + trigram GIN index on `keywords`, and (opt-in) tenant row-level security.
 *
 * All statements are `IF NOT EXISTS`/idempotent, so this is safe to run on every deploy. Only
 * meaningful on Postgres; the {@see \Vortos\Search\Index\PortableLikeSearchDriver} needs none of it.
 */
final class PostgresSearchExtrasInstaller
{
    /** Weighting must match {@see \Vortos\Search\Index\PostgresFtsSearchDriver}: title A, subtitle/keywords B, body C. */
    private const VECTOR_EXPR =
        "setweight(to_tsvector('simple', coalesce(title,'')), 'A') || "
        . "setweight(to_tsvector('simple', coalesce(subtitle,'')), 'B') || "
        . "setweight(to_tsvector('simple', coalesce(keywords,'')), 'B') || "
        . "setweight(to_tsvector('simple', coalesce(body,'')), 'C')";

    public function __construct(
        private readonly Connection $connection,
        private readonly string $table = 'vortos_search_documents',
    ) {
    }

    public function isPostgres(): bool
    {
        return str_contains(strtolower($this->connection->getDatabasePlatform()::class), 'postgres');
    }

    public function installVectorColumn(): void
    {
        $this->connection->executeStatement(sprintf(
            'ALTER TABLE %s ADD COLUMN IF NOT EXISTS search_vector tsvector '
            . 'GENERATED ALWAYS AS (%s) STORED',
            $this->table,
            self::VECTOR_EXPR,
        ));
        $this->connection->executeStatement(sprintf(
            'CREATE INDEX IF NOT EXISTS idx_search_vector ON %s USING gin (search_vector)',
            $this->table,
        ));
    }

    public function installTrigram(): void
    {
        $this->connection->executeStatement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->connection->executeStatement(sprintf(
            'CREATE INDEX IF NOT EXISTS idx_search_keywords_trgm ON %s USING gin (keywords gin_trgm_ops)',
            $this->table,
        ));
    }

    /**
     * Enable tenant row-level security. The app must set the per-request GUC
     * `app.current_tenant` (the same one vortos-tenant / the audit spine use) for reads to see
     * their org's rows. FORCE applies the policy to the table owner too.
     */
    public function enableRls(): void
    {
        $this->connection->executeStatement("ALTER TABLE {$this->table} ENABLE ROW LEVEL SECURITY");
        $this->connection->executeStatement("ALTER TABLE {$this->table} FORCE ROW LEVEL SECURITY");
        $this->connection->executeStatement("DROP POLICY IF EXISTS vortos_search_tenant_isolation ON {$this->table}");
        $this->connection->executeStatement(
            "CREATE POLICY vortos_search_tenant_isolation ON {$this->table} "
            . "USING (tenant_id = current_setting('app.current_tenant', true))",
        );
    }

    public function disableRls(): void
    {
        $this->connection->executeStatement("DROP POLICY IF EXISTS vortos_search_tenant_isolation ON {$this->table}");
        $this->connection->executeStatement("ALTER TABLE {$this->table} NO FORCE ROW LEVEL SECURITY");
        $this->connection->executeStatement("ALTER TABLE {$this->table} DISABLE ROW LEVEL SECURITY");
    }
}
