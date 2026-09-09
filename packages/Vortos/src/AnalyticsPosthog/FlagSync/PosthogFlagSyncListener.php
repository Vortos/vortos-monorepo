<?php

declare(strict_types=1);

namespace Vortos\AnalyticsPosthog\FlagSync;

use Psr\Log\LoggerInterface;
use Throwable;
use Vortos\FeatureFlags\Domain\Event\FlagArchivedEvent;
use Vortos\FeatureFlags\Domain\Event\FlagCreatedEvent;
use Vortos\FeatureFlags\Domain\Event\FlagDisabledEvent;
use Vortos\FeatureFlags\Domain\Event\FlagEnabledEvent;
use Vortos\FeatureFlags\Domain\Event\FlagLifecycleChangedEvent;
use Vortos\FeatureFlags\Domain\Event\FlagReconfiguredEvent;
use Vortos\FeatureFlags\Domain\Event\FlagVariantsChangedEvent;
use Vortos\Messaging\Attribute\AsEventHandler;

/**
 * Mirrors a flag into PostHog the moment it is created or changed, so "add a flag" and
 * "see the flag in PostHog" are the same action rather than two.
 *
 * One registered method per event class, not a single `object`-typed handler: the bus routes
 * on the first typed parameter, so a generic handler is unroutable and would leave this
 * listener looking complete while never running — the exact failure mode that left the flag
 * webhooks inert.
 *
 * Fails soft and loudly-enough: the flag change itself is already committed and must never be
 * rolled back because a third-party API was down, so every failure is swallowed and logged at
 * **warning** (not info — info is invisible in production, which would make a persistently
 * broken mirror undetectable).
 */
final readonly class PosthogFlagSyncListener
{
    public function __construct(
        private PosthogFlagSynchronizer $synchronizer,
        private ?LoggerInterface $logger = null,
        private bool $enabled = false,
    ) {}

    #[AsEventHandler(handlerId: 'vortos.analytics_posthog.flag_sync.created', consumer: PosthogFlagSyncMessagingConfig::CONSUMER)]
    public function onCreated(FlagCreatedEvent $event): void { $this->sync($event->name); }

    #[AsEventHandler(handlerId: 'vortos.analytics_posthog.flag_sync.enabled', consumer: PosthogFlagSyncMessagingConfig::CONSUMER)]
    public function onEnabled(FlagEnabledEvent $event): void { $this->sync($event->name); }

    #[AsEventHandler(handlerId: 'vortos.analytics_posthog.flag_sync.disabled', consumer: PosthogFlagSyncMessagingConfig::CONSUMER)]
    public function onDisabled(FlagDisabledEvent $event): void { $this->sync($event->name); }

    #[AsEventHandler(handlerId: 'vortos.analytics_posthog.flag_sync.variants_changed', consumer: PosthogFlagSyncMessagingConfig::CONSUMER)]
    public function onVariantsChanged(FlagVariantsChangedEvent $event): void { $this->sync($event->name); }

    #[AsEventHandler(handlerId: 'vortos.analytics_posthog.flag_sync.reconfigured', consumer: PosthogFlagSyncMessagingConfig::CONSUMER)]
    public function onReconfigured(FlagReconfiguredEvent $event): void { $this->sync($event->name); }

    #[AsEventHandler(handlerId: 'vortos.analytics_posthog.flag_sync.lifecycle_changed', consumer: PosthogFlagSyncMessagingConfig::CONSUMER)]
    public function onLifecycleChanged(FlagLifecycleChangedEvent $event): void { $this->sync($event->name); }

    #[AsEventHandler(handlerId: 'vortos.analytics_posthog.flag_sync.archived', consumer: PosthogFlagSyncMessagingConfig::CONSUMER)]
    public function onArchived(FlagArchivedEvent $event): void { $this->sync($event->name); }

    private function sync(string $flagName): void
    {
        if (!$this->enabled || $flagName === '') {
            return;
        }

        try {
            if (!$this->synchronizer->isConfigured()) {
                return;
            }

            $report = $this->synchronizer->syncOne($flagName);

            foreach ($report->failed as $flag => $reason) {
                $this->logger?->warning('PostHog flag mirror skipped a flag', [
                    'flag' => $flag,
                    'reason' => $reason,
                ]);
            }
        } catch (Throwable $e) {
            $this->logger?->warning('PostHog flag mirror failed', [
                'flag' => $flagName,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
