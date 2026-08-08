<?php

declare(strict_types=1);

namespace Vortos\Backup\Pitr;

use Vortos\Backup\Crypto\EnvelopeHeader;
use Vortos\Backup\Crypto\EnvelopeStreamCipher;
use Vortos\Backup\Domain\Exception\BackupException;
use Vortos\Backup\Port\BackupStoreRegistry;
use Vortos\Secrets\Key\KeyProviderInterface;

/**
 * Retrieves one archived WAL segment and writes it where Postgres asked for it — the read
 * counterpart of {@see PostgresWalArchiver}, and the hook behind `restore_command`.
 *
 * This exists because encrypting WAL without a decrypting fetch would be worse than not
 * encrypting it at all: recovery would silently replay ciphertext, and the failure would only
 * surface as a corrupt database during the one operation nobody can afford to have fail.
 *
 * Envelope detection is per segment, not per configuration. A store almost always holds segments
 * from both sides of the day encryption was switched on, and a recovery that spans that boundary
 * has to replay both. Reading the magic decides it, so no operator has to know which is which.
 *
 * Writes go to a temporary file and are renamed into place. Postgres treats the presence of the
 * destination file as "this segment is restored", so a partial write left behind by an interrupted
 * fetch would be replayed as if it were complete. rename(2) within a filesystem is atomic, which
 * makes the file appear only once it is whole.
 */
final class PostgresWalFetcher
{
    /**
     * @param non-empty-list<string> $storeKeys stores to search, in order
     */
    public function __construct(
        private readonly BackupStoreRegistry $stores,
        private readonly array $storeKeys,
        private readonly string $keyPrefix,
        private readonly ?EnvelopeStreamCipher $cipher = null,
        private readonly ?KeyProviderInterface $keyProvider = null,
    ) {}

    /**
     * @return int bytes written
     */
    public function fetch(string $segmentName, string $destinationPath, string $environment): int
    {
        $objectKey = sprintf('%s/%s/postgres/wal/%s', trim($this->keyPrefix, '/'), $environment, $segmentName);

        // EVERY configured store is searched, not just the current one.
        //
        // WAL may be split across buckets — Object Lock is bucket-level, so keeping restore points
        // immutable while still pruning WAL means two of them — and a recovery routinely spans the
        // instant the split was introduced. Segments archived before it are in the old bucket and
        // segments after it in the new one, and replay needs both.
        //
        // The catalog knows which is which and is useless here: it lives in the database being
        // restored. So this cannot resolve per artifact and must simply look.
        //
        // Getting this wrong is silent, which is why it is worth the loop. A miss is how Postgres
        // learns where the archive ends, so a fetcher that could not see the older bucket would not
        // fail — it would report the archive as ending at the split and stop replaying there,
        // producing a database that looks successfully recovered to the wrong point in time.
        $store = null;
        foreach ($this->storeKeys as $candidate) {
            $s = $this->stores->store($candidate);
            if ($s->exists($objectKey)) {
                $store = $s;
                break;
            }
        }

        if ($store === null) {
            // Not an error worth shouting about: Postgres probes for segments past the end of the
            // archive on every recovery, and that probe failing is how it learns where to stop.
            throw new ArchivedWalNotFoundException($segmentName);
        }

        $source = $store->open($objectKey);
        if (!is_resource($source)) {
            throw new BackupException("Cannot read archived WAL segment '{$segmentName}'.");
        }

        $tmpPath = $destinationPath . '.vortos-partial';
        $out = fopen($tmpPath, 'wb');
        if ($out === false) {
            fclose($source);
            throw new BackupException("Cannot write WAL segment to '{$tmpPath}'.");
        }

        $written = 0;

        try {
            foreach ($this->chunks($source, $segmentName) as $chunk) {
                $put = fwrite($out, $chunk);
                if ($put === false) {
                    throw new BackupException("Short write restoring WAL segment '{$segmentName}'.");
                }
                $written += $put;
            }
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($tmpPath);
            throw $e;
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }

        fclose($out);

        if (!rename($tmpPath, $destinationPath)) {
            @unlink($tmpPath);
            throw new BackupException("Cannot move restored WAL segment into place at '{$destinationPath}'.");
        }

        return $written;
    }

    /** @return \Generator<int, string, void, void> */
    private function chunks(mixed $source, string $segmentName): \Generator
    {
        $magic = fread($source, \strlen(EnvelopeHeader::MAGIC));
        rewind($source);

        if ($magic !== EnvelopeHeader::MAGIC) {
            while (!feof($source)) {
                $chunk = fread($source, EnvelopeStreamCipher::CHUNK_SIZE);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                yield $chunk;
            }

            return;
        }

        if ($this->cipher === null || $this->keyProvider === null) {
            // Fail loudly rather than write ciphertext to pg_wal. Postgres would accept the file,
            // fail to parse it, and report a damaged archive — sending the operator to look at the
            // wrong problem during a recovery.
            throw new BackupException(sprintf(
                "WAL segment '%s' is encrypted but no backup key provider is configured — refusing to restore ciphertext.",
                $segmentName,
            ));
        }

        yield from $this->cipher->decryptStreamLazy(
            $source,
            fn ($wrapped) => $this->keyProvider->unwrap($wrapped),
        );
    }
}
