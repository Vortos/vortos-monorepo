<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Exposure;

/**
 * An additive, optional extension point on {@see ExposureIngestService}: notified
 * once per *accepted* exposure (known flag, not a duplicate) — i.e. only after the
 * existing unknown-flag and dedupe guards already ran, so an observer never opens a
 * new cardinality-DoS surface and never sees a parallel/duplicate stream.
 *
 * Implementations MUST NOT throw: a failing observer must never break ingestion.
 * `ExposureIngestService` calls every observer inside a try/catch that swallows and
 * counts failures.
 */
interface ExposureObserverInterface
{
    /** Called once per accepted exposure. Best-effort; never throws. */
    public function onExposure(string $flag, ?string $variant, string $contextKey): void;
}
