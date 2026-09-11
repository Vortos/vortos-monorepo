<?php

declare(strict_types=1);

namespace Vortos\Analytics\Tests\Unit\Bridge;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\ResolveInstanceofConditionalsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Analytics\Bridge\AnalyticsExposureObserver;
use Vortos\Analytics\DependencyInjection\AnalyticsExtension;
use Vortos\FeatureFlags\DependencyInjection\FeatureFlagsExtension;

/**
 * The bridge is only ever called through a tagged iterator. An untagged observer is not a
 * degraded bridge — it is an absent one, and absolutely nothing reports that.
 *
 * This existed as an assumption in a comment ("No explicit tag needed: FeatureFlagsExtension
 * registers registerForAutoconfiguration(...), which tags any implementing service in the
 * container") and the assumption was false: `registerForAutoconfiguration` applies only to
 * definitions whose `autoconfigured` flag is set, and `ContainerBuilder::register()` leaves it
 * false. So the observer was registered, wired to a working analytics backend, gated by an
 * env var an operator had dutifully set to 1 — and never invoked, for any exposure, ever.
 */
final class AnalyticsExposureObserverIsWiredTest extends TestCase
{
    public function test_the_observer_carries_the_tag_the_registry_collects(): void
    {
        $container = $this->compile();

        self::assertTrue(
            $container->hasDefinition(AnalyticsExposureObserver::class),
            'The bridge observer should be registered whenever feature-flags is installed.',
        );

        $tagged = array_keys($container->findTaggedServiceIds(FeatureFlagsExtension::EXPOSURE_OBSERVER_TAG));

        self::assertContains(
            AnalyticsExposureObserver::class,
            $tagged,
            'The bridge observer is not tagged, so ExposureReportingFlagRegistry and '
            . 'ExposureIngestService will never call it and no exposure will ever reach the '
            . 'analytics backend.',
        );
    }

    public function test_autoconfiguration_is_enabled_on_it(): void
    {
        // The mechanism the comment relies on. Pinned separately from the outcome above so a
        // future reader can see *why* the tag appears, and cannot "simplify" this away.
        $container = $this->compile();

        self::assertTrue(
            $container->getDefinition(AnalyticsExposureObserver::class)->isAutoconfigured(),
            'register() leaves autoconfigured false, so registerForAutoconfiguration() does '
            . 'not apply and the interface-based tagging silently does nothing.',
        );
    }

    private function compile(): ContainerBuilder
    {
        $container = new ContainerBuilder();

        // Order matters as little as it should: FeatureFlagsExtension declares the
        // autoconfiguration, AnalyticsExtension registers the implementing service.
        (new FeatureFlagsExtension())->load([], $container);
        (new AnalyticsExtension())->load([], $container);

        // registerForAutoconfiguration() does nothing at load time — the interface-to-tag
        // mapping is applied during compilation, by this pass. Asserting on tags without
        // running it would pass for a definition that is autoconfigured and fail for one that
        // is already correctly tagged, which is exactly backwards.
        (new ResolveInstanceofConditionalsPass())->process($container);

        return $container;
    }
}
