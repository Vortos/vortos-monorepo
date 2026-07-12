<?php

declare(strict_types=1);

namespace Vortos\Audit\Storage\Dbal\Lock;

use Doctrine\DBAL\Connection;

/**
 * Postgres per-chain append lock via `pg_advisory_xact_lock`.
 *
 * A transaction-scoped advisory lock keyed on a deterministic 63-bit hash of the chain key.
 * No extra table, no row to contend on, auto-released at commit/rollback — the optimal
 * strategy when the store runs on Postgres.
 */
final class PgAdvisoryChainLock implements ChainLockStrategyInterface
{
    public function acquire(Connection $conn, string $chainKey): void
    {
        $conn->executeStatement('SELECT pg_advisory_xact_lock(:k)', ['k' => self::lockKey($chainKey)]);
    }

    /** Deterministic 63-bit advisory-lock key from the chain key. */
    public static function lockKey(string $chainKey): int
    {
        // crc32 is unsigned 32-bit; namespace it into a stable signed-bigint range.
        return 0x41554449 << 20 | crc32($chainKey) % 0xFFFFF; // 'AUDI' namespace | bucket
    }
}
