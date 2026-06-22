<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Exposure;

use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\Metrics\FlagEvaluationMetrics;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

/**
 * Normalises, validates, dedupes, and forwards SDK exposure events to metrics (Block 8).
 *
 * ## Security — this is fed from a hostile, public-facing endpoint
 *
 *  - **Unknown-flag rejection (cardinality-DoS guard):** an exposure for a flag that does
 *    not exist is dropped. Without this, an attacker could POST arbitrary `name` values and
 *    mint unbounded Prometheus series via the `flag` label — the sharpest abuse vector on
 *    this path. Only names in the known flag set ever become a metric label.
 *  - **Dedup:** the SDK already dedupes client-side; we defensively dedupe within a request
 *    by `(contextKey, flag, variant)` so a single POST cannot inflate counts. The context
 *    fingerprint is a dedup *key only* — it is never emitted as a label.
 */
final class ExposureIngestService
{
    public function __construct(
        private readonly FlagStorageInterface $storage,
        private readonly FlagEvaluationMetrics $metrics,
    ) {}

    /**
     * @param list<ExposureEvent> $events
     * @return int number of exposures accepted (known flag, not a duplicate)
     */
    public function ingest(array $events, string $contextKey): int
    {
        $known    = $this->knownFlagNames();
        $seen     = [];
        $accepted = 0;

        foreach ($events as $event) {
            if (!isset($known[$event->name])) {
                continue; // unknown flag → never create a metric series for it
            }

            $dedupeKey = $contextKey . '|' . $event->name . '|' . ($event->variant ?? '');
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $this->metrics->exposure($event->name, $event->variant);
            $accepted++;
        }

        return $accepted;
    }

    /**
     * @return array<string,true>
     */
    private function knownFlagNames(): array
    {
        $names = [];
        foreach ($this->storage->findAll() as $flag) {
            /** @var FeatureFlag $flag */
            $names[$flag->name] = true;
        }

        return $names;
    }
}
