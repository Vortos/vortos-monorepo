<?php

declare(strict_types=1);

namespace Vortos\Backup\Port;

use Vortos\Backup\Domain\RetentionPlan;
use Vortos\OpsKit\Driver\DriverInterface;

/**
 * A swappable backup *store*: durable, off-host destination for backup streams.
 * Default driver `object-store` (wraps the existing R2-capable object store).
 *
 * Stores accept a streamed, multipart upload (bounded memory for arbitrarily large
 * artifacts) and can read an artifact back for integrity verification / restore.
 * Retention executes only a pre-computed {@see RetentionPlan} — the store never
 * decides what to delete, it only carries out the reviewed plan.
 */
interface BackupStoreInterface extends DriverInterface
{
    /**
     * Stream a dump to the store under $key and return where it landed plus the
     * checksum computed while streaming. MUST abort/clean up a partial upload on
     * failure so a partial object can never masquerade as a complete backup.
     */
    public function store(BackupStream $stream, string $key): StoredBackup;

    /**
     * Open a stored artifact for reading (verify / restore).
     *
     * @return resource
     */
    public function open(string $key): mixed;

    public function exists(string $key): bool;

    public function delete(string $key): void;

    /**
     * List artifacts under $prefix.
     *
     * @return list<array{key:string, size:int}>
     */
    public function list(string $prefix): array;

    /** Execute the delete set of a reviewed retention plan (and nothing else). */
    public function applyRetention(RetentionPlan $plan): void;
}
