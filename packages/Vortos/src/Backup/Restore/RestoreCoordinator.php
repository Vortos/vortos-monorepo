<?php

declare(strict_types=1);

namespace Vortos\Backup\Restore;

use Vortos\Backup\Crypto\EnvelopeStreamCipher;
use Vortos\Backup\Domain\BackupArtifact;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\Exception\IntegrityException;
use Vortos\Backup\Port\BackupStoreInterface;
use Vortos\Backup\Restore\RestoreTargetInterface;
use Vortos\Secrets\Key\KeyProviderInterface;

/**
 * Orchestrates a restore: store.open → decrypt → target.restore.
 *
 * The full chain exercises KEK unwrap + AEAD decrypt + restore binary,
 * which is the whole point of §12.7.
 */
final class RestoreCoordinator
{
    public function __construct(
        private readonly RestoreTargetRegistry $targets,
        private readonly EnvelopeStreamCipher $cipher,
        private readonly ?KeyProviderInterface $keyProvider,
    ) {}

    /**
     * Suffix marking the point-in-time variant of an engine's restore target.
     *
     * Registered as a SEPARATE driver key rather than as a mode of the base target, because a
     * physical restore and a logical one share nothing but an engine: one streams a dump into
     * `pg_restore` over a connection, the other lays a data directory down and boots a postmaster
     * over it to replay a log.
     */
    public const PITR_SUFFIX = '-pitr';

    /**
     * The target that would handle this engine — and, when it matters, this KIND of artifact.
     *
     * Exposed so callers can ask what a restore is CAPABLE of before choosing what to hand it —
     * the drill uses it to avoid selecting a physical_base for a target that only speaks
     * `pg_restore`, which would fail for a reason unrelated to the backup's health.
     *
     * Kind-aware because engine alone is not enough to name a restore. Resolution used to key on
     * `$engine->value` and nothing else, which made a second Postgres target unreachable no matter
     * how it was registered: a `physical_base` artifact would still have been handed to the logical
     * target, which honestly declares it cannot do point-in-time recovery. Falling back to the
     * base key when no PITR variant is registered keeps every existing single-target installation
     * behaving exactly as before.
     */
    public function targetFor(DatabaseEngine $engine, ?BackupKind $kind = null): RestoreTargetInterface
    {
        if ($kind === BackupKind::PhysicalBase) {
            $pitrKey = $engine->value . self::PITR_SUFFIX;
            if ($this->targets->has($pitrKey)) {
                return $this->targets->target($pitrKey);
            }
        }

        return $this->targets->target($engine->value);
    }

    public function restore(
        BackupArtifact $artifact,
        BackupStoreInterface $store,
        RestoreRequest $request,
    ): void {
        $raw = $store->open($artifact->storeKey);
        if (!is_resource($raw)) {
            throw IntegrityException::unreadable($artifact->storeKey);
        }

        try {
            if ($artifact->encryption !== null) {
                if ($this->keyProvider === null) {
                    throw IntegrityException::undecryptable('no key provider configured');
                }
                $chunks = $this->cipher->decryptStreamLazy(
                    $raw,
                    fn ($wrapped) => $this->keyProvider->unwrap($wrapped),
                );
            } else {
                $chunks = $this->readChunks($raw);
            }

            // Resolved from the artifact's KIND as well as its engine: a base backup and a logical
            // dump are both 'postgres' and need entirely different restore machinery.
            $target = $this->targetFor($artifact->engine, $artifact->kind);
            $target->restore($chunks, $request);
        } finally {
            if (is_resource($raw)) {
                fclose($raw);
            }
        }
    }

    /** @return \Generator<int, string, void, void> */
    private function readChunks(mixed $stream): \Generator
    {
        while (!feof($stream)) {
            $chunk = fread($stream, EnvelopeStreamCipher::CHUNK_SIZE);
            if ($chunk === false || $chunk === '') {
                break;
            }
            yield $chunk;
        }
    }
}
