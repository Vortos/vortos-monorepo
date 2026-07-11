<?php

declare(strict_types=1);

namespace Vortos\Audit\Retention;

/**
 * Test/dev archive writer that keeps segments in memory. NOT for production — purge with
 * this configured would archive to nowhere durable.
 */
final class InMemoryArchiveWriter implements AuditArchiveWriterInterface
{
    /** @var array<string, string> objectKey => ndjson */
    public array $segments = [];

    public function write(string $chainKey, int $fromSequence, int $toSequence, string $ndjson): string
    {
        $key = sprintf('mem/%s/%d-%d.ndjson', str_replace(':', '/', $chainKey), $fromSequence, $toSequence);
        $this->segments[$key] = $ndjson;

        return $key;
    }
}
