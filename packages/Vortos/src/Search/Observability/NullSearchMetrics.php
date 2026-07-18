<?php

declare(strict_types=1);

namespace Vortos\Search\Observability;

/** No-op metrics — the default when vortos-observability isn't wired. */
final class NullSearchMetrics implements SearchMetricsInterface
{
    public function indexUpserted(string $type, int $count = 1): void
    {
    }

    public function indexDeleted(string $type, int $count = 1): void
    {
    }

    public function queryObserved(bool $hit, bool $fromCache, float $seconds): void
    {
    }
}
