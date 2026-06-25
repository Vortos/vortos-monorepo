<?php

declare(strict_types=1);

namespace Vortos\Observability\Audit;

/**
 * Append-only read model storage for the deploy audit ledger (Block 16, §3.1).
 *
 * Exposes **append + reads only** — there is deliberately no `update()`/`delete()`
 * method on this interface; {@see \Vortos\Observability\Tests\Architecture\AuditAppendOnlyTest}
 * fails the build if any caller mutates a persisted row.
 */
interface DeployAuditViewRepositoryInterface
{
    /**
     * Atomically resolves the next sequence number and previous content hash for
     * `$env` (single-writer per env via a tail lock), builds the entry with
     * `$builder`, and appends it. The whole operation is one transaction so a
     * concurrent append for the same env can never observe or produce a gap.
     *
     * @param callable(int $nextSequence, string $prevHash): AuditEntry $builder
     */
    public function appendNext(string $env, callable $builder): AuditEntry;

    /**
     * @return list<AuditEntry> ordered by sequence ascending
     */
    public function findByEnv(string $env, int $limit = 1000): array;

    /**
     * @return list<AuditEntry> ordered by (env, sequence) ascending
     */
    public function findAll(
        ?string $env = null,
        ?string $actorId = null,
        ?string $buildId = null,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
        int $limit = 1000,
    ): array;

    /**
     * @return \Generator<AuditEntry> Memory-bounded stream for export
     */
    public function stream(
        ?string $env = null,
        ?string $actorId = null,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
    ): \Generator;
}
