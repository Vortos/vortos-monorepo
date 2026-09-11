<?php

declare(strict_types=1);

namespace Vortos\Analytics\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Analytics\AnalyticsInterface;
use Vortos\Analytics\Bridge\AnalyticsExposureObserver;
use Vortos\Analytics\Bridge\FlagEnrichingAnalytics;
use Vortos\Analytics\Bridge\FlagExposureSampler;
use Vortos\Analytics\Command\AnalyticsDoctorCheck;
use Vortos\Analytics\Command\AnalyticsFlushCommand;
use Vortos\Analytics\DependencyInjection\Compiler\CollectAnalyticsDriversPass;
use Vortos\Analytics\Driver\Null\NullAnalytics;
use Vortos\Analytics\Privacy\ConsentGate;
use Vortos\Analytics\Privacy\ConsentResolverInterface;
use Vortos\Analytics\Privacy\DenyAllConsentResolver;
use Vortos\Analytics\Privacy\PiiRedactor;
use Vortos\Analytics\Privacy\PropertyAllowlist;
use Vortos\Analytics\Privacy\PrivacyFilter;
use Vortos\Analytics\Registry\AnalyticsDriverRegistry;
use Vortos\Analytics\Runtime\AnalyticsSpool;
use Vortos\Analytics\Runtime\BatchingAnalytics;
use Vortos\Analytics\Runtime\FlushOnTerminateSubscriber;
use Vortos\Analytics\Runtime\HttpTerminateFlush;
use Vortos\Analytics\Runtime\IdentityDedupeCache;
use Vortos\Analytics\Runtime\PrivacyFilteringAnalytics;
use Vortos\Observability\Buffer\BoundedSpool;

final class AnalyticsExtension extends Extension
{
    private const EVENT_ALLOWLIST_ID = 'vortos.analytics.event_allowlist';
    private const TRAIT_ALLOWLIST_ID = 'vortos.analytics.trait_allowlist';
    private const SELECTED_DRIVER_ID = 'vortos.analytics.selected_driver';
    private const SPOOL_BUFFER_ID = 'vortos.analytics.spool_buffer';

    public function getAlias(): string
    {
        return 'vortos_analytics';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $this->registerDriverSeam($container);
        $this->registerPrivacyServices($container);
        $this->registerRuntime($container);
        $this->registerBridge($container);
        $this->registerDeployIntegration($container);
        $this->registerCommands($container);
    }

    private function registerDriverSeam(ContainerBuilder $container): void
    {
        $container->register(CollectAnalyticsDriversPass::LOCATOR_ID)
            ->addTag('container.service_locator')
            ->setArgument(0, []);

        $container->register(AnalyticsDriverRegistry::class, AnalyticsDriverRegistry::class)
            ->setArgument('$drivers', new Reference(CollectAnalyticsDriversPass::LOCATOR_ID))
            ->setPublic(true); // app config may select a driver by key directly

        $container->registerForAutoconfiguration(AnalyticsInterface::class)
            ->addTag(CollectAnalyticsDriversPass::TAG);

        $container->register(NullAnalytics::class, NullAnalytics::class)
            ->addTag(CollectAnalyticsDriversPass::TAG)
            ->setPublic(false);

        $driverKey = (string) ($_ENV['ANALYTICS_DRIVER'] ?? 'null');

        $container->register(self::SELECTED_DRIVER_ID, AnalyticsInterface::class)
            ->setFactory([new Reference(AnalyticsDriverRegistry::class), 'driver'])
            ->setArguments([$driverKey])
            ->setPublic(false);
    }

    private function registerPrivacyServices(ContainerBuilder $container): void
    {
        $container->register(DenyAllConsentResolver::class, DenyAllConsentResolver::class)
            ->setPublic(false);
        // Privacy-by-default: nothing is sent until the app overrides this alias with
        // its own consent source.
        $container->setAlias(ConsentResolverInterface::class, DenyAllConsentResolver::class)->setPublic(false);

        $container->register(ConsentGate::class, ConsentGate::class)
            ->setArgument('$resolver', new Reference(ConsentResolverInterface::class))
            ->setPublic(false);

        $eventAllowedKeys = self::envList('ANALYTICS_EVENT_PROPERTY_ALLOWLIST');
        $traitAllowedKeys = self::envList('ANALYTICS_TRAIT_ALLOWLIST');

        $container->register(self::EVENT_ALLOWLIST_ID, PropertyAllowlist::class)
            ->setArgument('$allowedKeys', $eventAllowedKeys)
            ->setPublic(false);

        // Identify/group traits are the most PII-prone surface: empty (deny-all) by
        // default — opt-in widening only, never opt-out.
        $container->register(self::TRAIT_ALLOWLIST_ID, PropertyAllowlist::class)
            ->setArgument('$allowedKeys', $traitAllowedKeys)
            ->setPublic(false);

        $container->register(PiiRedactor::class, PiiRedactor::class)
            ->setArgument('$salt', (string) ($_ENV['ANALYTICS_PII_SALT'] ?? ''))
            ->setArgument('$rawAllowedKeys', self::envList('ANALYTICS_PII_RAW_ALLOWED_KEYS'))
            ->setPublic(false);

        $container->register(PrivacyFilter::class, PrivacyFilter::class)
            ->setArgument('$consentGate', new Reference(ConsentGate::class))
            ->setArgument('$eventAllowlist', new Reference(self::EVENT_ALLOWLIST_ID))
            ->setArgument('$traitAllowlist', new Reference(self::TRAIT_ALLOWLIST_ID))
            ->setArgument('$redactor', new Reference(PiiRedactor::class))
            ->setPublic(false);
    }

    private function registerRuntime(ContainerBuilder $container): void
    {
        $container->register(PrivacyFilteringAnalytics::class, PrivacyFilteringAnalytics::class)
            ->setArgument('$inner', new Reference(self::SELECTED_DRIVER_ID))
            ->setArgument('$filter', new Reference(PrivacyFilter::class))
            ->setPublic(false);

        $container->register(IdentityDedupeCache::class, IdentityDedupeCache::class)
            ->setArgument('$maxEntries', (int) ($_ENV['ANALYTICS_DEDUPE_MAX_ENTRIES'] ?? 1000))
            ->setPublic(false);

        $spoolDir = (string) ($_ENV['ANALYTICS_SPOOL_DIR'] ?? sys_get_temp_dir() . '/vortos-analytics');
        $container->register(self::SPOOL_BUFFER_ID, BoundedSpool::class)
            ->setArgument('$path', $spoolDir . '/events.spool')
            ->setArgument('$maxBytes', (int) ($_ENV['ANALYTICS_SPOOL_MAX_BYTES'] ?? 64 * 1024 * 1024))
            ->setPublic(false);

        $container->register(AnalyticsSpool::class, AnalyticsSpool::class)
            ->setArgument('$spool', new Reference(self::SPOOL_BUFFER_ID))
            ->setPublic(false);

        $spoolEnabled = (string) ($_ENV['ANALYTICS_SPOOL'] ?? '0') === '1';

        $batching = $container->register(BatchingAnalytics::class, BatchingAnalytics::class)
            ->setArgument('$inner', new Reference(PrivacyFilteringAnalytics::class))
            ->setArgument('$dedupeCache', new Reference(IdentityDedupeCache::class))
            ->setArgument('$bufferMax', (int) ($_ENV['ANALYTICS_BATCH_MAX'] ?? 500))
            ->setArgument('$flushAt', (int) ($_ENV['ANALYTICS_BATCH_FLUSH_AT'] ?? 100))
            ->setPublic(false);

        if ($spoolEnabled) {
            $batching->setArgument('$spool', new Reference(AnalyticsSpool::class));
        }

        // App code always receives the outermost decorator — privacy + batching can
        // never be bypassed.
        $container->setAlias(AnalyticsInterface::class, BatchingAnalytics::class)->setPublic(true);

        // Console-side flush: commands, consumers, scheduled jobs.
        $container->register(FlushOnTerminateSubscriber::class, FlushOnTerminateSubscriber::class)
            ->setArgument('$analytics', new Reference(AnalyticsInterface::class))
            ->addTag('kernel.event_subscriber')
            ->setPublic(false);

        // HTTP-side flush. Registered only when vortos-http is installed, because analytics
        // must stay usable in an application with no HTTP layer — the same reason the
        // FeatureFlags bridge below is guarded.
        //
        // This is what actually ships a request's events. Vortos\Http\Kernel::terminate()
        // only iterates terminable middleware and dispatches no events, so the
        // KernelEvents::TERMINATE subscriber this replaced had never run: delivery fell back
        // to BatchingAnalytics' own flush at 100 buffered events, which meant events arrived
        // in round hundreds or, in a quiet period, never.
        if (interface_exists(\Vortos\Http\Contract\TerminableMiddlewareInterface::class)) {
            $container->register(HttpTerminateFlush::class, HttpTerminateFlush::class)
                ->setArgument('$analytics', new Reference(AnalyticsInterface::class))
                ->setPublic(false);
        }
    }

    private function registerBridge(ContainerBuilder $container): void
    {
        if (!interface_exists(\Vortos\FeatureFlags\Exposure\ExposureObserverInterface::class)) {
            return;
        }

        $rate = (float) ($_ENV['ANALYTICS_FLAG_EXPOSURE_SAMPLE_RATE'] ?? 0.1);
        $enabled = (string) ($_ENV['ANALYTICS_FLAG_EXPOSURE_BRIDGE'] ?? '0') === '1';


        $container->register(FlagExposureSampler::class, FlagExposureSampler::class)
            ->setArgument('$rate', $rate)
            ->setPublic(false);

        // Flag attribution on every event, stamped centrally so no call site has to remember.
        // Registered OUTSIDE the privacy + batching chain and re-pointing the public alias, so
        // app-issued events are enriched before the privacy filter runs — which is exactly why
        // the property it writes is reserved there rather than left to each app's allowlist.
        //
        // Defaults on when the bridge is on: an exposure event alone cannot answer whether a
        // flag moved a metric, because the metric lives on a different event. Separately
        // switchable for the case where an app wants exposures but not attribution.
        $enrich = (string) ($_ENV['ANALYTICS_FLAG_ATTRIBUTION'] ?? ($enabled ? '1' : '0')) === '1';

        // The alias is NOT re-pointed here. FlagAttributionCompilerPass does that, and only if
        // the feature-flags services are genuinely in this container — see that pass for why
        // `class_exists()` is the wrong question to ask at load time.
        $container->register(FlagEnrichingAnalytics::class, FlagEnrichingAnalytics::class)
            ->setArgument('$inner', new Reference(BatchingAnalytics::class))
            ->setArgument('$collector', new Reference(\Vortos\FeatureFlags\Exposure\ActiveFlagCollector::class))
            ->setArgument('$enabled', $enrich)
            ->setPublic(false);

        // FeatureFlagsExtension registers registerForAutoconfiguration(
        // ExposureObserverInterface::class), which tags any implementing service regardless of
        // which package registered it — but ONLY for definitions whose autoconfigured flag is
        // set, and ContainerBuilder::register() leaves it false.
        //
        // This previously relied on that tagging without calling setAutoconfigured(true), so
        // the observer was registered, wired to a working analytics backend, gated by an env
        // var operators had set to 1 — and never invoked, for any exposure, ever. The registry
        // and the SDK ingest path both reach observers exclusively through the tagged
        // iterator, so an untagged observer is not a degraded bridge but an absent one, and
        // nothing anywhere reports it. Pinned by AnalyticsExposureObserverIsWiredTest.
        $container->register(AnalyticsExposureObserver::class, AnalyticsExposureObserver::class)
            ->setArgument('$analytics', new Reference(AnalyticsInterface::class))
            ->setArgument('$sampler', new Reference(FlagExposureSampler::class))
            ->setArgument('$enabled', $enabled)
            ->setAutoconfigured(true)
            ->setPublic(false);
    }

    private function registerDeployIntegration(ContainerBuilder $container): void
    {
        if (!interface_exists(\Vortos\Deploy\Preflight\PreflightCheckInterface::class)) {
            return;
        }

        $container->register(AnalyticsDoctorCheck::class, AnalyticsDoctorCheck::class)
            ->setArgument('$registry', new Reference(AnalyticsDriverRegistry::class))
            ->setArgument('$configuredDriverKey', (string) ($_ENV['ANALYTICS_DRIVER'] ?? 'null'))
            ->setPublic(false);
    }

    private function registerCommands(ContainerBuilder $container): void
    {
        $container->register(AnalyticsFlushCommand::class, AnalyticsFlushCommand::class)
            ->setArgument('$spool', new Reference(AnalyticsSpool::class))
            ->setArgument('$analytics', new Reference(PrivacyFilteringAnalytics::class))
            ->setArgument('$drainBatch', (int) ($_ENV['ANALYTICS_FLUSH_DRAIN_BATCH'] ?? 500))
            ->setPublic(true)
            ->addTag('console.command');
    }

    /** @return list<string> */
    private static function envList(string $envVar): array
    {
        $raw = (string) ($_ENV[$envVar] ?? '');
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $v): bool => $v !== ''));
    }
}
