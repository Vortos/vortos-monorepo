<?php

declare(strict_types=1);

namespace Vortos\Analytics\Bridge;

use Throwable;
use Vortos\Analytics\AnalyticsInterface;
use Vortos\Analytics\Event\AnalyticsEvent;
use Vortos\Analytics\Event\DistinctId;
use Vortos\FeatureFlags\Exposure\ExposureObserverInterface;

/**
 * Closes `FEATURE_FLAGS_PLATFORM.md` §9: bridges accepted flag exposures into an
 * agnostic `feature_flag_exposure` analytics event. **Opt-in** (`$enabled`, default
 * false — can't surprise a quota) and **deterministically sampled** (can't flood
 * the analytics quota). Only wired by the DI extension when FeatureFlags is
 * installed (`interface_exists(ExposureObserverInterface::class)`).
 *
 * Deliberately emits a provider-agnostic event shape — no PostHog naming here; the
 * PostHog split's `PosthogEventMapper` translates this into PostHog's native
 * `$feature_flag_called` shape. We never build a stats engine — PostHog does the
 * significance analysis.
 *
 * Reuses the existing FF exposure pipeline's unknown-flag + dedupe guards (this is
 * only ever called for an *accepted* exposure) — never a parallel path. Never
 * throws back into ingestion.
 */
final class AnalyticsExposureObserver implements ExposureObserverInterface
{
    public const EVENT_NAME = 'feature_flag_exposure';

    public function __construct(
        private readonly AnalyticsInterface $analytics,
        private readonly FlagExposureSampler $sampler,
        private readonly bool $enabled = false,
    ) {}

    public function onExposure(string $flag, ?string $variant, string $contextKey): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            if (!$this->sampler->isSampledIn($contextKey, $flag)) {
                return;
            }

            $event = new AnalyticsEvent(
                new DistinctId($contextKey),
                self::EVENT_NAME,
                ['flag' => $flag, 'variant' => $variant ?? ''],
            );

            $this->analytics->capture($event);
        } catch (Throwable) {
            // Intentionally swallowed: an observer can never break ingestion.
        }
    }
}
