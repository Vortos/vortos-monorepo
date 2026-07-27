<?php

declare(strict_types=1);

namespace Vortos\Backup\Restore;

use Vortos\Backup\Crypto\EnvelopeStreamCipher;
use Vortos\Backup\Domain\BackupArtifact;
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
     * The target that would handle this engine.
     *
     * Exposed so callers can ask what a restore is CAPABLE of before choosing what to hand it —
     * the drill uses it to avoid selecting a physical_base for a target that only speaks
     * `pg_restore`, which would fail for a reason unrelated to the backup's health.
     */
    public function targetFor(DatabaseEngine $engine): RestoreTargetInterface
    {
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

            $target = $this->targets->target($artifact->engine->value);
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
