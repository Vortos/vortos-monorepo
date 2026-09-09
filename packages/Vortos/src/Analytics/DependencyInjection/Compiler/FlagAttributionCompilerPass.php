<?php

declare(strict_types=1);

namespace Vortos\Analytics\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Analytics\AnalyticsInterface;
use Vortos\Analytics\Bridge\FlagEnrichingAnalytics;

/**
 * Puts flag attribution at the head of the analytics chain — but only when the
 * feature-flags package is actually wired into *this* container.
 *
 * ## Why a compiler pass rather than a check at load time
 *
 * `AnalyticsExtension` cannot decide this on its own. `class_exists()` answers whether the
 * feature-flags code is on the autoloader, which is a different question from whether its
 * extension has been loaded and registered its services — and the two disagree in exactly
 * the case that matters: a monorepo where every package is installed but an app has only
 * enabled some of them. Deciding at load time also depends on extension ordering, which no
 * package may assume.
 *
 * By the time compiler passes run, every extension has loaded and the container is the
 * final word. If the collector is there, attribution goes on; if not, the definition is
 * removed and the chain is byte-for-byte what it was before this feature existed.
 */
final class FlagAttributionCompilerPass implements CompilerPassInterface
{
    /** @var class-string */
    private const COLLECTOR = 'Vortos\\FeatureFlags\\Exposure\\ActiveFlagCollector';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(FlagEnrichingAnalytics::class)) {
            return;
        }

        if (!$container->has(self::COLLECTOR)) {
            // Feature flags are not wired here. Drop the decorator entirely rather than leave
            // an inert one in the chain: app code should resolve exactly what it resolved
            // before, and a no-op decorator in a stack trace is a puzzle nobody needs.
            $container->removeDefinition(FlagEnrichingAnalytics::class);

            return;
        }

        $container->setAlias(AnalyticsInterface::class, FlagEnrichingAnalytics::class)->setPublic(true);
    }
}
