<?php

declare(strict_types=1);

namespace Vortos\AnalyticsPosthog\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Analytics\Transport\AnalyticsTransportInterface;
use Vortos\AnalyticsPosthog\CurlAnalyticsTransport;
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
    }
}
