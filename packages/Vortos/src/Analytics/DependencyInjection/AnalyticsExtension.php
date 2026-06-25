<?php

declare(strict_types=1);

namespace Vortos\Analytics\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Analytics\AnalyticsInterface;
use Vortos\Analytics\Bridge\AnalyticsExposureObserver;
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

        $container->register(FlushOnTerminateSubscriber::class, FlushOnTerminateSubscriber::class)
            ->setArgument('$analytics', new Reference(AnalyticsInterface::class))
            ->addTag('kernel.event_subscriber')
            ->setPublic(false);
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

        // No explicit tag needed: FeatureFlagsExtension registers
        // registerForAutoconfiguration(ExposureObserverInterface::class), which tags
        // any implementing service in the container regardless of which package
        // registered it (the same pattern as a driver's #[AsDriver] autoconfig tag).
        $container->register(AnalyticsExposureObserver::class, AnalyticsExposureObserver::class)
            ->setArgument('$analytics', new Reference(AnalyticsInterface::class))
            ->setArgument('$sampler', new Reference(FlagExposureSampler::class))
            ->setArgument('$enabled', $enabled)
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
