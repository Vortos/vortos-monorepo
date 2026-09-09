<?php

declare(strict_types=1);

namespace Vortos\Analytics\Bridge;

use DateTimeImmutable;
use Throwable;
use Vortos\Analytics\AnalyticsInterface;
use Vortos\Analytics\Event\AnalyticsEvent;
use Vortos\Analytics\Event\DistinctId;
use Vortos\FeatureFlags\Exposure\ExposureObserverInterface;
use Vortos\FeatureFlags\Exposure\ExposureRecord;

/**
 * Bridges accepted flag exposures into an agnostic `feature_flag_exposure` analytics
 * event. **Opt-in** (`$enabled`, default false — can't surprise a quota) and
 * **deterministically sampled** (can't flood the analytics quota). Only wired by the DI
 * extension when FeatureFlags is installed (`interface_exists(ExposureObserverInterface::class)`).
 *
 * Deliberately emits a provider-agnostic event shape — no PostHog naming here; the
 * PostHog split's `PosthogEventMapper` translates this into PostHog's native
 * experimentation shape so PostHog's own significance analysis can run on it. We never
 * build a stats engine.
 *
 * Carries through everything the record knows, because each piece is something the
 * backend cannot reconstruct: the **timestamp** the exposure was actually observed (not
 * when the batch happened to flush), the **group** associations that make per-tenant
 * rollout breakdowns possible, and the **source** that distinguishes a browser-reported
 * exposure from a server-side gate. All three were previously dropped on the floor.
 *
 * Fed by both exposure producers — the SDK ingest endpoint and the server-side
 * evaluation decorator. Never throws back into either.
 */
final class AnalyticsExposureObserver implements ExposureObserverInterface
{
    public const EVENT_NAME = 'feature_flag_exposure';

    public function __construct(
        private readonly AnalyticsInterface $analytics,
        private readonly FlagExposureSampler $sampler,
        private readonly bool $enabled = false,
    ) {}

    public function onExposure(ExposureRecord $record): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $analyticsId = $record->analyticsId();

            // Sampled on the *subject*, not the context fingerprint. The fingerprint changes
            // when any targeting attribute does, so sampling on it would let the same person
            // drift in and out of the sample between requests — which biases an experiment
            // rather than merely shrinking it, and is exactly what this sampler exists to
            // prevent.
            if (!$this->sampler->isSampledIn($analyticsId, $record->flag)) {
                return;
            }

            $event = new AnalyticsEvent(
                distinctId: new DistinctId($analyticsId),
                name:       self::EVENT_NAME,
                properties: [
                    'flag'            => $record->flag,
                    'variant'         => $record->variant ?? '',
                    'exposure_source' => $record->source->value,
                ],
                timestamp:  $this->timestampOf($record),
                groups:     $record->groups,
            );

            $this->analytics->capture($event);
        } catch (Throwable) {
            // Intentionally swallowed: an observer can never break ingestion.
        }
    }

    /**
     * The moment the exposure was observed. A client SDK reports its own clock, which we
     * honour; when absent the event carries no timestamp and the backend stamps ingest
     * time, which is the same behaviour as before.
     */
    private function timestampOf(ExposureRecord $record): ?DateTimeImmutable
    {
        if ($record->timestamp === null) {
            return null;
        }

        return (new DateTimeImmutable())->setTimestamp($record->timestamp);
    }
}
