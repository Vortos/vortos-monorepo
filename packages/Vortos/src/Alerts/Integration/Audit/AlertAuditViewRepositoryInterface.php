<?php

declare(strict_types=1);

namespace Vortos\Alerts\Integration\Audit;

/** Append-only read model for the alert notification/ack ledger — no update()/delete(). */
interface AlertAuditViewRepositoryInterface
{
    /** @param callable(int $nextSequence, string $prevHash): AlertAuditEntry $builder */
    public function appendNext(string $env, callable $builder): AlertAuditEntry;

    /** @return list<AlertAuditEntry> ordered by sequence ascending */
    public function findByEnv(string $env, int $limit = 1000): array;
}
