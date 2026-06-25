<?php

declare(strict_types=1);

namespace Vortos\Observability\Collector;

/**
 * Outcome of publishing the collector sidecar assets: which relative paths were
 * written and which were left untouched (already up to date, or present without
 * --force).
 */
final readonly class CollectorPublishResult
{
    /**
     * @param list<string> $written
     * @param list<string> $skipped
     */
    public function __construct(
        public array $written,
        public array $skipped,
    ) {}
}
