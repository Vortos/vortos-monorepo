<?php

declare(strict_types=1);

namespace Vortos\AnalyticsPosthog\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;

/**
 * Deliberately adds **no compiler pass**. Core's
 * `registerForAutoconfiguration(AnalyticsInterface::class)` (registered by
 * `Vortos\Analytics\DependencyInjection\AnalyticsExtension`) already tags any
 * `AnalyticsInterface` service present in the container — including
 * {@see \Vortos\AnalyticsPosthog\PosthogAnalytics}, once this package's extension
 * registers it as a service — so core's existing `CollectAnalyticsDriversPass`
 * picks it up by its `#[AsDriver('posthog')]` key with **zero changes to core**.
 * This is the §10.7 "build app's driver first, popular ones later, no core drift"
 * proof.
 */
final class AnalyticsPosthogPackage implements PackageInterface
{
    public function getContainerExtension(): ExtensionInterface
    {
        return new AnalyticsPosthogExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        // Intentionally empty — no new compiler pass.
    }
}
