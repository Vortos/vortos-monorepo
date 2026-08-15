<?php

declare(strict_types=1);

namespace Vortos\Backup\Pitr;

use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\Exception\BackupException;

/**
 * Streaming gzip for archived WAL segments — the single definition of the codec shared by
 * {@see PostgresWalArchiver} (write) and {@see PostgresWalFetcher} (read).
 *
 * WHY THIS EXISTS. A WAL segment is a fixed-size 16 MiB file regardless of how much log it
 * actually carries, and `archive_timeout` forces a switch on a schedule rather than on fullness.
 * A server with steady low-volume writes therefore archives a full-size segment holding a few
 * hundred KB of records — the rest is zero padding. Measured on one production system: 262 MB/day
 * of real WAL records shipped as 22.7 GB/day of segments, an ~87x amplification, with 98.8% of the
 * bytes in the object store being padding. Compression removes the padding and nothing else; it
 * does not change the archiving cadence, so the RPO the `archive_timeout` buys is untouched.
 *
 * WHY GZIP AND NOT ZSTD. `CompressionCodec` names Zstd, but PHP reaches it only through ext-zstd,
 * which is not a core extension and is absent from the standard runtime image. zlib is always
 * present. A codec this path cannot actually apply is rejected loudly by {@see assertSupported()}
 * rather than silently degraded to no compression — silent degradation is precisely the failure
 * being fixed here, and it would be invisible until a storage bill or a restore surfaced it.
 *
 * WHY THE STORED OBJECT KEY IS UNCHANGED. No `.gz` suffix is appended. The key is derived from the
 * segment name, and the read path resolves a segment by that name across every configured store;
 * suffixing it would orphan every segment already archived and break the `exists()` idempotency
 * check that the archive_command contract depends on. The artifact is self-describing instead —
 * by the envelope's authenticated `innerCodec` when encrypted, and by the RFC 1952 magic when not.
 * That matches the rule {@see CompressionCodec} already states: never inferred from a file
 * extension.
 *
 * STREAMING, NOT BUFFERING. Everything here works a chunk at a time. A 16 MiB segment would
 * survive being buffered whole, but the archiver runs inside `archive_command` on the database
 * host under a modest memory limit, and the read path is shared with recovery, where holding
 * segments in memory would bound how fast WAL can be replayed.
 */
final class WalCompression
{
    /** RFC 1952 gzip member header. Two bytes are enough to disambiguate: a WAL segment begins with the XLOG page magic, never 0x1f8b. */
    public const GZIP_MAGIC = "\x1f\x8b";

    /**
     * zlib window size selecting the gzip container over raw deflate.
     *
     * Load-bearing: raw deflate (window 15) carries no integrity trailer, while the gzip container
     * appends a CRC32 and an ISIZE that `inflate_add()` verifies on finalisation. That trailer is
     * what lets the checksum guard on the write path be relaxed for compressed segments — the
     * integrity claim moves into the container rather than being dropped.
     */
    private const GZIP_WINDOW = 31;

    /** Matches {@see \Vortos\Backup\Crypto\EnvelopeStreamCipher::CHUNK_SIZE} so the two pipelines pull in the same units. */
    public const CHUNK_SIZE = 65536;

    public const DEFAULT_LEVEL = 6;

    private function __construct() {}

    /**
     * Reject a codec this path cannot honour, at the point of configuration rather than mid-archive.
     *
     * @throws BackupException
     */
    public static function assertSupported(CompressionCodec $codec): void
    {
        if ($codec === CompressionCodec::None || $codec === CompressionCodec::Gzip) {
            return;
        }

        throw new BackupException(sprintf(
            "WAL compression codec '%s' is not supported by this build — only 'none' and 'gzip' are. "
            . 'Refusing to archive rather than silently shipping uncompressed segments.',
            $codec->value,
        ));
    }

    /**
     * Attach a streaming gzip-deflate READ filter to an open segment handle.
     *
     * A read filter is used rather than a wrapper stream so the result composes with the encryption
     * seam unchanged: the transform still receives one readable resource and cannot tell the
     * difference, which keeps compress-then-encrypt ordering a property of this call site instead of
     * something the envelope has to know about.
     *
     * @param resource $handle
     *
     * @throws BackupException
     */
    public static function attachDeflate(mixed $handle, int $level = self::DEFAULT_LEVEL): void
    {
        if ($level < 1 || $level > 9) {
            throw new BackupException("Invalid gzip level {$level} for WAL compression — expected 1-9.");
        }

        $filter = stream_filter_append(
            $handle,
            'zlib.deflate',
            STREAM_FILTER_READ,
            ['level' => $level, 'window' => self::GZIP_WINDOW],
        );

        if ($filter === false) {
            throw new BackupException('Cannot attach the zlib.deflate filter — ext-zlib is unavailable, so WAL cannot be compressed as configured.');
        }
    }

    /**
     * Yield a resource as chunks, so resources and generators share one downstream shape.
     *
     * @param resource $stream
     *
     * @return \Generator<int, string, void, void>
     */
    public static function chunks(mixed $stream): \Generator
    {
        while (!feof($stream)) {
            $chunk = fread($stream, self::CHUNK_SIZE);
            if ($chunk === false || $chunk === '') {
                break;
            }
            yield $chunk;
        }
    }

    /**
     * Inflate when the stream is a gzip member, pass through byte-for-byte when it is not.
     *
     * Detection is per segment rather than per configuration, for the same reason the envelope
     * magic is: a store holds segments from both sides of the day compression was switched on, and
     * a recovery routinely spans that instant. Nothing an operator has to know or record.
     *
     * The magic is read from the buffered head of the stream, so a segment shorter than two bytes —
     * which cannot be a gzip member and cannot be valid WAL either — is passed through rather than
     * treated as an error. Deciding what a truncated segment means belongs to Postgres, not here.
     *
     * @param iterable<int, string> $chunks
     *
     * @return \Generator<int, string, void, void>
     *
     * @throws BackupException
     */
    public static function maybeInflate(iterable $chunks): \Generator
    {
        $gen = (static function () use ($chunks): \Generator { yield from $chunks; })();

        // Explicit cursor rather than a second foreach over the same generator. `foreach` calls
        // rewind(), and a generator that has already advanced cannot be rewound — resuming it that
        // way silently loses or repeats the chunk the sniff consumed, which corrupts the segment
        // without raising anything until the CRC check at the very end.
        $head = '';
        while ($gen->valid() && strlen($head) < strlen(self::GZIP_MAGIC)) {
            $head .= (string) $gen->current();
            $gen->next();
        }

        if ($head === '') {
            return;
        }

        if (!str_starts_with($head, self::GZIP_MAGIC)) {
            yield $head;
            while ($gen->valid()) {
                yield (string) $gen->current();
                $gen->next();
            }

            return;
        }

        $context = inflate_init(ZLIB_ENCODING_GZIP);
        if ($context === false) {
            throw new BackupException('Cannot initialise the gzip inflate context — ext-zlib is unavailable, so archived WAL cannot be decompressed.');
        }

        yield from self::inflateChunk($context, $head);

        while ($gen->valid()) {
            yield from self::inflateChunk($context, (string) $gen->current());
            $gen->next();
        }

        // Completeness is asserted from the decoder's own state, and this is the ONLY reliable way
        // to do it. `inflate_add(…, ZLIB_FINISH)` does NOT report truncation: measured against a
        // member with its 8-byte CRC32/ISIZE trailer removed, it returns '' and reports success,
        // and afterwards inflate_get_status() reads ZLIB_BUF_ERROR for complete and truncated input
        // alike, so a check made after finishing cannot tell them apart either.
        //
        // Read before finishing, the status separates them cleanly: ZLIB_STREAM_END is reached only
        // when the trailer has been consumed and verified. That matters more here than the usual
        // integrity argument — a truncated segment inflates to a SHORT BUT WELL-FORMED prefix of
        // real WAL, which Postgres would replay happily and stop at, silently recovering to an
        // earlier point in time than the operator asked for and reporting success.
        if (inflate_get_status($context) !== ZLIB_STREAM_END) {
            throw new BackupException(
                'Archived WAL segment is a truncated gzip member — refusing to replay a partial segment as if it were whole.',
            );
        }
    }

    /**
     * @param \InflateContext $context
     *
     * @return \Generator<int, string, void, void>
     *
     * @throws BackupException
     */
    private static function inflateChunk(mixed $context, string $chunk): \Generator
    {
        // Suppressed deliberately. inflate_add() raises a PHP warning *and* returns false on corrupt
        // input; under an error handler that promotes warnings to ErrorException — Symfony's does —
        // the warning would escape as an untyped exception before this typed one could be thrown,
        // so a corrupt segment during a recovery would surface as a generic error rather than as a
        // statement about the segment. The false return is the signal; the warning is noise.
        $out = @inflate_add($context, $chunk);
        if ($out === false) {
            throw new BackupException('Archived WAL segment is not a readable gzip member (corrupt compressed data).');
        }
        if ($out !== '') {
            yield $out;
        }
    }
}
