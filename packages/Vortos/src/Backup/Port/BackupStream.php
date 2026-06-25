<?php

declare(strict_types=1);

namespace Vortos\Backup\Port;

use InvalidArgumentException;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\SourceRef;
use Vortos\Backup\Service\Process\ProcessGuard;

/**
 * A live, readable dump stream plus the metadata describing it.
 *
 * The {@see $stream} is a one-shot resource: it is read once, end-to-end, by the
 * runner (hash → transform → store) and never buffered whole. Ownership transfers to
 * the consumer, which closes it.
 *
 * When the stream is produced by a subprocess (pg_dump / mongodump), an optional
 * {@see ProcessGuard} lets the runner detect a *mid-stream* non-zero exit AFTER the
 * bytes have been consumed — so a truncated/partial dump fails loudly instead of being
 * cataloged as good. {@see finish()} is a no-op when there is no underlying process.
 */
final class BackupStream
{
    /** @var resource */
    private mixed $stream;

    /** @param resource $stream */
    public function __construct(
        mixed $stream,
        public readonly DatabaseEngine $engine,
        public readonly BackupKind $kind,
        public readonly CompressionCodec $codec,
        public readonly SourceRef $sourceRef,
        private readonly ?ProcessGuard $guard = null,
    ) {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('BackupStream requires an open resource.');
        }
        $this->stream = $stream;
    }

    /** @return resource */
    public function resource()
    {
        return $this->stream;
    }

    /**
     * Validate the producing process completed cleanly. Call AFTER the stream has been
     * fully consumed. Throws {@see \Vortos\Backup\Domain\Exception\DumpFailedException}
     * on a non-zero exit.
     */
    public function finish(): void
    {
        $this->guard?->assertSuccess();
    }
}
