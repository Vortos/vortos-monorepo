<?php

declare(strict_types=1);

namespace Vortos\Backup\Service;

use Psr\Clock\ClockInterface;
use Throwable;
use Vortos\Backup\Catalog\BackupCatalogRepositoryInterface;
use Vortos\Backup\Domain\BackupArtifact;
use Vortos\Backup\Domain\BackupId;
use Vortos\Backup\Domain\BackupRequest;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\Exception\IntegrityException;
use Vortos\Backup\Event\BackupEvent;
use Vortos\Backup\Event\BackupEventSinkInterface;
use Vortos\Backup\Port\BackupStream;
use Vortos\Backup\Port\BackupStoreInterface;
use Vortos\Backup\Port\BackupStoreRegistry;
use Vortos\Backup\Port\BackupTargetRegistry;
use Vortos\Backup\Service\EncryptionSeam\EnvelopeStreamTransform;
use Vortos\Backup\Service\EncryptionSeam\StreamTransformFactoryInterface;

/**
 * Orchestrates a single backup, fail-closed end-to-end:
 *
 *   lock → dump → transform (encryption seam) → store (streamed, checksummed) →
 *   verify at creation (read-back) → catalog (append-only) → emit success.
 *
 * Any failure after a partial store deletes the stored object and emits a typed
 * `Critical` event (a partial dump is never cataloged as good). Concurrent runs of
 * the same scope degrade to a no-op via {@see BackupLock} (returns null).
 */
final class BackupRunner
{
    public function __construct(
        private readonly BackupTargetRegistry $targets,
        private readonly BackupStoreRegistry $stores,
        private readonly BackupCatalogRepositoryInterface $catalog,
        private readonly IntegrityVerifier $verifier,
        private readonly BackupEventSinkInterface $events,
        private readonly StreamTransformFactoryInterface $transforms,
        private readonly BackupLock $lock,
        private readonly ClockInterface $clock,
        private readonly string $storeKey,
        private readonly string $keyPrefix,
    ) {}

    public function run(BackupRequest $request, ?string $schemaFingerprint = null): ?BackupArtifact
    {
        $scope = $request->engine->value . '/' . $request->environment;

        return $this->lock->withLock($scope, fn (): BackupArtifact => $this->execute($request, $schemaFingerprint));
    }

    private function execute(BackupRequest $request, ?string $schemaFingerprint): BackupArtifact
    {
        $now = $this->clock->now();
        $id = BackupId::generate($request->engine, $request->kind, $now);
        $store = $this->stores->store($this->storeKey);
        $storeKey = '';
        $stored = null;

        try {
            $target = $this->targets->target($request->engine->value);
            $dump = $target->dump($request);

            // The target is the source of truth for the on-disk codec (e.g. pg custom
            // format is internally compressed → codec None; mongodump --gzip → Gzip).
            $storeKey = $this->objectKey($request, $id, $dump->codec);

            // Built here, not injected: the envelope binds engine/kind/codec into its authenticated
            // header, and the codec is only settled once the target has produced the dump.
            $transform = $this->transforms->forBackup($dump->engine, $dump->kind, $dump->codec);

            $piped = new BackupStream(
                $transform->transform($dump->resource()),
                $dump->engine,
                $dump->kind,
                $dump->codec,
                $dump->sourceRef,
            );

            $stored = $store->store($piped, $storeKey);

            // The bytes are now fully consumed: confirm the dump subprocess exited 0
            // (a mid-stream failure becomes a loud DumpFailedException, not a partial
            // backup recorded as good).
            $dump->finish();

            $this->verifier->verify(
                $store,
                $stored->storeKey,
                $stored->checksum,
                $request->engine,
                $request->kind,
                $dump->codec,
            );

            $encryption = $transform instanceof EnvelopeStreamTransform
                ? $transform->lastMetadata()
                : null;

            $artifact = new BackupArtifact(
                $id,
                $request->engine,
                $request->kind,
                $request->environment,
                $now,
                $stored->sizeBytes,
                $stored->checksum,
                $stored->storeKey,
                $dump->codec,
                $dump->sourceRef,
                null,
                $schemaFingerprint,
                $encryption,
            );

            $this->catalog->record($artifact);
            $this->events->emit(BackupEvent::succeeded($artifact, $this->clock->now()));

            return $artifact;
        } catch (IntegrityException $e) {
            $this->cleanup($store, $storeKey);
            $this->events->emit(BackupEvent::integrityFailed($request->engine, $request->environment, $e->getMessage(), $this->clock->now()));
            throw $e;
        } catch (Throwable $e) {
            if ($stored !== null) {
                $this->cleanup($store, $storeKey);
            }
            $this->events->emit(BackupEvent::failed($request->engine, $request->environment, $e->getMessage(), $this->clock->now()));
            throw $e;
        }
    }

    private function cleanup(BackupStoreInterface $store, string $storeKey): void
    {
        try {
            $store->delete($storeKey);
        } catch (Throwable) {
            // Best effort — the original failure is what matters and is re-thrown.
        }
    }

    private function objectKey(BackupRequest $request, BackupId $id, CompressionCodec $codec): string
    {
        $ext = match ($codec) {
            CompressionCodec::Gzip => '.gz',
            CompressionCodec::Zstd => '.zst',
            CompressionCodec::None => '',
        };

        return sprintf(
            '%s/%s/%s/%s/%s%s',
            trim($this->keyPrefix, '/'),
            $request->environment,
            $request->engine->value,
            $request->kind->value,
            $id->value(),
            $ext,
        );
    }
}
