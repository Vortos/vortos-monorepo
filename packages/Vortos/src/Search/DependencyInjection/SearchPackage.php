<?php

declare(strict_types=1);

namespace Vortos\Search\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;

/**
 * vortos-search package entry point.
 *
 * No compiler pass is needed: projectors and backfill sources are collected via
 * autoconfiguration + tagged-iterator arguments ({@see SearchExtension}). The app's Kafka
 * handler (which carries the messaging event-handler tag) lives in app land, so this package
 * never participates in the discovery-ordering dance around the messaging pass.
 */
final class SearchPackage implements PackageInterface
{
    public function getContainerExtension(): ExtensionInterface
    {
        return new SearchExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        // Nothing to do — wiring is entirely declarative in the extension.
    }
}
