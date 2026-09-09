<?php

declare(strict_types=1);

namespace Vortos\AnalyticsPosthog\DependencyInjection;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Analytics\Transport\AnalyticsTransportInterface;
use Vortos\AnalyticsPosthog\Command\FlagsPosthogSyncCommand;
use Vortos\AnalyticsPosthog\CurlAnalyticsTransport;
use Vortos\AnalyticsPosthog\FlagSync\FlagDefinitionMapper;
use Vortos\AnalyticsPosthog\FlagSync\PosthogFlagSyncListener;
use Vortos\AnalyticsPosthog\FlagSync\PosthogFlagSyncMessagingConfig;
use Vortos\AnalyticsPosthog\FlagSync\PosthogFlagSynchronizer;
use Vortos\AnalyticsPosthog\FlagSync\PosthogManagementClient;
use Vortos\AnalyticsPosthog\PosthogAnalytics;
use Vortos\AnalyticsPosthog\PosthogEventMapper;

final class AnalyticsPosthogExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_analytics_posthog';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $container->register(CurlAnalyticsTransport::class, CurlAnalyticsTransport::class)
            ->setPublic(false);

        // No other driver implements this core interface, so aliasing it directly
        // is safe — it only ever resolves to this split's own transport.
        $container->setAlias(AnalyticsTransportInterface::class, CurlAnalyticsTransport::class)->setPublic(false);

        $container->register(PosthogEventMapper::class, PosthogEventMapper::class)
            ->setPublic(false);

        // setAutoconfigured(true) is what makes core's
        // registerForAutoconfiguration(AnalyticsInterface::class) actually apply to
        // this service (Symfony only resolves instanceof-conditionals for
        // autoconfigured definitions) — the real mechanism behind "zero changes to
        // core": this package adds no addTag() call naming core's tag constant.
        $container->register(PosthogAnalytics::class, PosthogAnalytics::class)
            ->setArgument('$transport', new Reference(AnalyticsTransportInterface::class))
            ->setArgument('$mapper', new Reference(PosthogEventMapper::class))
            ->setArgument('$hostEnvVar', 'POSTHOG_HOST')
            ->setArgument('$apiKeyEnvVar', 'POSTHOG_PROJECT_API_KEY')
            ->setAutoconfigured(true)
            ->setPublic(false);

        $this->registerFlagDefinitionSync($container);
    }

    /**
     * Mirrors Vortos flag definitions into PostHog, so a flag exists there as a real entity
     * and can be seen in the Feature Flags UI and attached to an Experiment. Exposure events
     * alone create no entity, so without this the flags are invisible there.
     *
     * Only wired when the feature-flags package is installed — this split must stay usable as
     * a plain analytics driver.
     */
    private function registerFlagDefinitionSync(ContainerBuilder $container): void
    {
        if (!interface_exists(\Vortos\FeatureFlags\Storage\FlagStorageInterface::class)) {
            return;
        }

        $container->register(PosthogManagementClient::class, PosthogManagementClient::class)
            ->setArgument('$hostEnvVar', 'POSTHOG_HOST')
            ->setArgument('$projectIdEnvVar', 'POSTHOG_PROJECT_ID')
            ->setArgument('$personalApiKeyEnvVar', 'POSTHOG_PERSONAL_API_KEY')
            ->setPublic(false);

        $container->register(FlagDefinitionMapper::class, FlagDefinitionMapper::class)
            ->setPublic(false);

        $container->register(PosthogFlagSynchronizer::class, PosthogFlagSynchronizer::class)
            ->setArgument('$storage', new Reference(\Vortos\FeatureFlags\Storage\FlagStorageInterface::class))
            ->setArgument('$client', new Reference(PosthogManagementClient::class))
            ->setArgument('$mapper', new Reference(FlagDefinitionMapper::class))
            ->setPublic(true);

        $container->register(FlagsPosthogSyncCommand::class, FlagsPosthogSyncCommand::class)
            ->setArgument('$synchronizer', new Reference(PosthogFlagSynchronizer::class))
            ->addTag('console.command')
            ->setPublic(true);

        // Off by default: this writes to a third-party project with a broader credential than
        // anything else in the package, so it may not switch itself on. The command works
        // regardless — an operator can mirror by hand without ever enabling the listener.
        $autoSync = (string) ($_ENV['POSTHOG_FLAG_SYNC'] ?? '0') === '1';

        // Registered explicitly, with autoconfiguration on, so the #[MessagingConfig] and
        // #[AsEventHandler] attributes are actually resolved. An attribute on a class the
        // container never registers is inert — the precise way the flag webhooks shipped
        // complete and unreachable.
        $container->register(PosthogFlagSyncMessagingConfig::class, PosthogFlagSyncMessagingConfig::class)
            ->setAutoconfigured(true)
            ->setPublic(true);

        $container->register(PosthogFlagSyncListener::class, PosthogFlagSyncListener::class)
            ->setArgument('$synchronizer', new Reference(PosthogFlagSynchronizer::class))
            ->setArgument('$logger', new Reference(LoggerInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$enabled', $autoSync)
            ->setAutoconfigured(true)
            ->setPublic(false);
    }
}
