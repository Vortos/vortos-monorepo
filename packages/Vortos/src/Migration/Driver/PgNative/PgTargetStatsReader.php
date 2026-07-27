<?php

declare(strict_types=1);

namespace Vortos\Migration\Driver\PgNative;

use Doctrine\DBAL\Connection;
use Vortos\Migration\Safety\TableStat;
use Vortos\Migration\Safety\TargetSchemaSnapshot;

final class PgTargetStatsReader
{
    private const STATS_TIMEOUT_MS = 5000;

    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function read(): ?TargetSchemaSnapshot
    {
        try {
            $this->connection->executeStatement(
                sprintf('SET statement_timeout = %d', self::STATS_TIMEOUT_MS),
            );

            $rows = $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT
                    n.nspname AS schema_name,
                    c.relname AS table_name,
                    COALESCE(c.reltuples, 0)::bigint AS estimated_rows,
                    COALESCE(pg_total_relation_size(c.oid), 0)::bigint AS total_bytes,
                    (COALESCE(c.reltuples, 0) > 0 OR COALESCE(s.n_live_tup, 0) > 0) AS has_data
                FROM pg_class c
                JOIN pg_namespace n ON n.oid = c.relnamespace
                LEFT JOIN pg_stat_user_tables s ON s.relid = c.oid
                WHERE c.relkind = 'r'
                  AND n.nspname NOT IN ('pg_catalog', 'information_schema')
                  AND n.nspname NOT LIKE 'pg_toast%'
                  AND n.nspname NOT LIKE 'pg_temp%'
                SQL,
            );

            $stats = [];
            foreach ($rows as $row) {
                $schema = strtolower((string) $row['schema_name']);
                $table = strtolower((string) $row['table_name']);

                $stat = new TableStat(
                    estimatedRows: max(0, (int) $row['estimated_rows']),
                    totalBytes: max(0, (int) $row['total_bytes']),
                    hasData: (bool) $row['has_data'],
                );

                // Keyed both schema-qualified and bare. A migration may write either form, and the
                // safety rules fail CLOSED when no statistic is found — so a key the analyzer
                // cannot match is indistinguishable from a genuinely enormous table.
                $stats[$schema . '.' . $table] = $stat;

                // Bare name only when unambiguous; a name existing in two schemas must not silently
                // resolve to whichever was read last.
                if (\array_key_exists($table, $stats)) {
                    unset($stats[$table]);
                } else {
                    $stats[$table] = $stat;
                }
            }

            return new TargetSchemaSnapshot($stats);
        } catch (\Throwable) {
            return null;
        } finally {
            try {
                $this->connection->executeStatement('SET statement_timeout = 0');
            } catch (\Throwable) {
            }
        }
    }
}
