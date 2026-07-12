<?php

declare(strict_types=1);

namespace Vortos\Audit\Storage\Dbal\Lock;

use Doctrine\DBAL\Connection;

/**
 * Portable per-chain append lock via `SELECT ... FOR UPDATE` on a per-chain head row.
 *
 * Works on any transactional database with pessimistic row locking (MySQL/InnoDB, Postgres,
 * MariaDB, …) — no vendor-specific advisory-lock builtin required. The head row in
 * `audit_chain_heads` exists purely as the lock anchor; the chain tail is still derived from
 * `audit_events`, so this table holds no authoritative state.
 *
 * The head row is created lazily on first append. A concurrent create losing the unique race
 * is swallowed, then the row is re-selected FOR UPDATE — so exactly one appender proceeds.
 */
final class RowChainLock implements ChainLockStrategyInterface
{
    public function __construct(
        private readonly string $headsTable = 'vortos_audit_chain_heads',
    ) {}

    public function acquire(Connection $conn, string $chainKey): void
    {
        if ($this->lockRow($conn, $chainKey)) {
            return;
        }

        // No head row yet — create it, tolerating a concurrent creator, then lock it.
        try {
            $conn->insert($this->headsTable, ['chain_key' => $chainKey]);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            // Another appender created it first; fall through to lock the existing row.
        }

        if (!$this->lockRow($conn, $chainKey)) {
            throw new \RuntimeException(
                "Failed to acquire chain lock for '{$chainKey}': head row missing after create.",
            );
        }
    }

    private function lockRow(Connection $conn, string $chainKey): bool
    {
        $row = $conn->fetchOne(
            "SELECT chain_key FROM {$this->headsTable} WHERE chain_key = :ck FOR UPDATE",
            ['ck' => $chainKey],
        );

        return $row !== false;
    }
}
