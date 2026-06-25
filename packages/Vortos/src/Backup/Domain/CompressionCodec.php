<?php

declare(strict_types=1);

namespace Vortos\Backup\Domain;

/**
 * The compression codec applied to a stored artifact.
 *
 * Recorded on every {@see BackupArtifact} so a restore knows exactly how to
 * reconstitute the stream — the pipeline is self-describing, never inferred from a
 * file extension.
 */
enum CompressionCodec: string
{
    case None = 'none';
    case Gzip = 'gzip';
    case Zstd = 'zstd';
}
