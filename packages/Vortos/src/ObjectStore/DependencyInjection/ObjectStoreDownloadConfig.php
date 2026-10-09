<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\DependencyInjection;

/**
 * How a whole-object read is split into byte-range requests (see S3RangedDownloader).
 *
 * Every store reads this way, so the part size is what bounds the work any one request carries: it
 * must finish a part inside the client's request timeout, and a retry repeats at most one part.
 */
final class ObjectStoreDownloadConfig
{
    private int $partSizeBytes = 16_777_216;
    private int $concurrency = 4;

    public function partSizeBytes(int $bytes): static
    {
        $this->partSizeBytes = $bytes;
        return $this;
    }

    /**
     * Parts in flight at once. Each one in flight holds up to one part on local temp storage.
     */
    public function concurrency(int $parts): static
    {
        $this->concurrency = $parts;
        return $this;
    }

    /**
     * @internal
     *
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'part_size_bytes' => $this->partSizeBytes,
            'concurrency'     => $this->concurrency,
        ];
    }
}
