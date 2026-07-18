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
     * Enable + FORCE tenant row-level security and (re)create the isolation policy.
     *
     * The predicate is deliberately **permissive when the GUC is unset/empty**: the trusted
     * write paths — the indexing consumer and the backfill command — never set
     * `app.current_tenant`, so they must be able to write rows for every tenant, while a request
     * path that DID set the GUC (via the same middleware the audit spine uses) is confined to
     * its org for both reads (USING) and writes (WITH CHECK). `current_setting(..., true)` is
     * missing-safe (returns NULL when unset). FORCE applies the policy to the table owner too.
     */
    public function enableRls(): void
    {
        $predicate =
            "(current_setting('app.current_tenant', true) IS NULL"
            . " OR current_setting('app.current_tenant', true) = ''"
            . " OR tenant_id = current_setting('app.current_tenant', true))";

        $this->connection->executeStatement("ALTER TABLE {$this->table} ENABLE ROW LEVEL SECURITY");
        $this->connection->executeStatement("ALTER TABLE {$this->table} FORCE ROW LEVEL SECURITY");
        $this->connection->executeStatement("DROP POLICY IF EXISTS vortos_search_tenant_isolation ON {$this->table}");
        $this->connection->executeStatement(
            "CREATE POLICY vortos_search_tenant_isolation ON {$this->table} "
            . "USING {$predicate} WITH CHECK {$predicate}",
        );
    }

    public function disableRls(): void
    {
        $this->connection->executeStatement("DROP POLICY IF EXISTS vortos_search_tenant_isolation ON {$this->table}");
        $this->connection->executeStatement("ALTER TABLE {$this->table} NO FORCE ROW LEVEL SECURITY");
        $this->connection->executeStatement("ALTER TABLE {$this->table} DISABLE ROW LEVEL SECURITY");
    }
}
