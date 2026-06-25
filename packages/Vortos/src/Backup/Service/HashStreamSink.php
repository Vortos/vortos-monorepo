<?php

declare(strict_types=1);

namespace Vortos\Backup\Service;

use HashContext;
use RuntimeException;
use Vortos\Backup\Domain\BackupChecksum;

/**
 * Accumulates a streaming hash + byte count as a backup flows through the
 * {@see ChecksumStreamFilter}, so the checksum is computed in the *same single pass*
 * that uploads the bytes — no second read, no buffering of the whole artifact.
 */
final class HashStreamSink
{
    private HashContext $context;
    private int $bytes = 0;
    private ?string $hex = null;

    public function __construct(private readonly string $algorithm = BackupChecksum::DEFAULT_ALGORITHM)
    {
        $this->context = hash_init($algorithm);
    }

    public function update(string $data): void
    {
        if ($this->hex !== null) {
            throw new RuntimeException('Cannot update a finalized HashStreamSink.');
        }
        hash_update($this->context, $data);
        $this->bytes += strlen($data);
    }

    public function finalize(): void
    {
        $this->hex ??= hash_final($this->context);
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    public function checksum(): BackupChecksum
    {
        if ($this->hex === null) {
            throw new RuntimeException('HashStreamSink must be finalized before reading the checksum.');
        }

        return BackupChecksum::of($this->algorithm, $this->hex);
    }
}
