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
                    c.relname AS table_name,
                    COALESCE(c.reltuples, 0)::bigint AS estimated_rows,
                    COALESCE(pg_total_relation_size(c.oid), 0)::bigint AS total_bytes,
                    (COALESCE(c.reltuples, 0) > 0 OR COALESCE(s.n_live_tup, 0) > 0) AS has_data
                FROM pg_class c
                JOIN pg_namespace n ON n.oid = c.relnamespace
                LEFT JOIN pg_stat_user_tables s ON s.relid = c.oid
                WHERE c.relkind = 'r'
                  AND n.nspname = 'public'
                SQL,
            );

            $stats = [];
            foreach ($rows as $row) {
                $stats[strtolower((string) $row['table_name'])] = new TableStat(
                    estimatedRows: max(0, (int) $row['estimated_rows']),
                    totalBytes: max(0, (int) $row['total_bytes']),
                    hasData: (bool) $row['has_data'],
                );
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
