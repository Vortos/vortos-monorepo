<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Exposure;

/**
 * An additive, optional extension point: notified once per *accepted* exposure —
 * i.e. only after the unknown-flag and dedupe guards already ran, so an observer
 * never opens a new cardinality-DoS surface and never sees a parallel/duplicate
 * stream.
 *
 * Both exposure producers call this: {@see ExposureIngestService} for exposures a
 * client SDK reported, and {@see ExposureReportingFlagRegistry} for the ones the
 * server produced while gating a request.
 *
 * Implementations MUST NOT throw: a failing observer must never break evaluation or
 * ingestion. Both producers call every observer inside a try/catch that swallows and
 * counts failures.
 */
interface ExposureObserverInterface
{
    /** Called once per accepted exposure. Best-effort; never throws. */
    public function onExposure(ExposureRecord $record): void;
}
