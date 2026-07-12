<?php

declare(strict_types=1);

namespace Vortos\Audit\Storage\Dbal\Lock;

use Doctrine\DBAL\Connection;

/**
 * Serialises appends WITHIN a single hash chain so sequence + prev_hash stay consistent,
 * while letting different chains (different tenants) append concurrently.
 *
 * Called inside the store's append transaction, before the chain tail is read. The lock
 * is held until the surrounding transaction commits or rolls back.
 *
 * Two strategies ship:
 *   - {@see PgAdvisoryChainLock}: a Postgres `pg_advisory_xact_lock` — no extra table, no
 *     row contention. The default when the connection is Postgres.
 *   - {@see RowChainLock}: a portable `SELECT ... FOR UPDATE` on a per-chain head row in
 *     `audit_chain_heads`. Works on any transactional DB (MySQL, SQLite-with-locks, etc.).
 */
interface ChainLockStrategyInterface
{
    /**
     * Acquire the exclusive append lock for $chainKey on the current transaction.
     *
     * @param Connection $conn     the connection running the append transaction
     * @param string     $chainKey 'platform' | 'tenant:{id}'
     */
    public function acquire(Connection $conn, string $chainKey): void;
}
