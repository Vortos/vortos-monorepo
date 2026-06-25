<?php

declare(strict_types=1);

namespace Vortos\Backup\Port;

use DateTimeImmutable;
use Vortos\Backup\Domain\BackupChecksum;

/**
 * The result of persisting a stream to a backup store: where it landed, how big it
 * is, and the checksum computed while it streamed through.
 */
final readonly class StoredBackup
{
    public function __construct(
        public string $storeKey,
        public int $sizeBytes,
        public BackupChecksum $checksum,
        public DateTimeImmutable $storedAt,
    ) {}
}
