<?php

declare(strict_types=1);

namespace Vortos\Analytics\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Analytics\AnalyticsInterface;
use Vortos\Analytics\Bridge\AnalyticsExposureObserver;
use Vortos\Analytics\Command\AnalyticsDoctorCheck;
use Vortos\Analytics\Command\AnalyticsFlushCommand;
use Vortos\Analytics\DependencyInjection\AnalyticsExtension;
use Vortos\Analytics\DependencyInjection\AnalyticsPackage;
use Vortos\Analytics\Registry\AnalyticsDriverRegistry;
use Vortos\Analytics\Runtime\BatchingAnalytics;
use Vortos\OpsKit\Driver\Exception\UnknownDriverException;

/**
 * DI smoke test (§10.4 / plan §10).
 *
 * Two container-building strategies, mirroring `AwsSesExtensionDriverRegistrationTest`:
 *  - {@see loadOnlyContainer()} calls `Extension::load()` directly (no compiler
 *    passes, no `compile()`) — the right way to assert *what got registered* by the
 *    guard conditions, since `compile()`'s `RemoveUnusedDefinitionsPass` would prune
 *    these private, tag-only services in an Analytics-only container (in the real
 *    app they stay alive because FeatureFlags'/Deploy's own `TaggedIteratorArgument`
 *    collectors reference them — that cross-package wiring is out of scope here).
 *  - {@see compiledContainer()} runs the full `AnalyticsPackage::build()` +
 *    `compile()` so the driver-collecting pass actually populates the registry —
 *    needed to assert real driver-selection *behavior*.
 */
final class ContainerWiringTest extends TestCase
{
    public function test_container_compiles_with_core_only(): void
    {
        $this->compiledContainer();
        $this->addToAssertionCount(1); // reaching here = compile() did not throw
    }

    public function test_null_is_selected_by_default(): void
    {
        $container = $this->compiledContainer();

        $analytics = $container->get(AnalyticsInterface::class);
        $this->assertInstanceOf(BatchingAnalytics::class, $analytics);
        $this->assertSame('null', $analytics->name());
    }

    public function test_app_code_always_receives_the_outermost_decorator(): void
    {
        // Checked pre-compile: a full compile() resolves (and removes) the alias,
        // replacing it with a definition directly under the interface id — the
        // runtime instanceof assertion in test_null_is_selected_by_default() proves
        // the same guarantee post-compile.
        $container = $this->loadOnlyContainer();

        $alias = (string) $container->getAlias(AnalyticsInterface::class);
        $this->assertSame(BatchingAnalytics::class, $alias, 'privacy + batching must never be bypassable');
    }

    public function test_posthog_key_is_absent_when_split_package_not_installed(): void
    {
        $container = $this->compiledContainer();
        $registry = $container->get(AnalyticsDriverRegistry::class);

        $this->assertTrue($registry->has('null'));
        $this->assertFalse($registry->has('posthog'), 'uninstalled split -> key absent, no runtime error from merely checking');
    }

    public function test_selecting_an_unregistered_key_fails_fast_on_first_use(): void
    {
        $container = $this->compiledContainer(['ANALYTICS_DRIVER' => 'posthog']);

        $this->expectException(UnknownDriverException::class);
        $container->get(AnalyticsInterface::class);
    }

    public function test_guarded_ff_bridge_is_registered_when_ff_interface_exists(): void
    {
        $container = $this->loadOnlyContainer();
        $this->assertTrue($container->hasDefinition(AnalyticsExposureObserver::class));
    }

    public function test_guarded_deploy_doctor_check_is_registered_when_deploy_interface_exists(): void
    {
        $container = $this->loadOnlyContainer();
        $this->assertTrue($container->hasDefinition(AnalyticsDoctorCheck::class));
    }

    public function test_flush_command_is_registered(): void
    {
        $container = $this->loadOnlyContainer();
        $this->assertTrue($container->hasDefinition(AnalyticsFlushCommand::class));
    }

    /** @param array<string,string> $env */
    private function loadOnlyContainer(array $env = []): ContainerBuilder
    {
        return $this->withEnv($env, function () {
            $container = new ContainerBuilder();
            $container->setParameter('kernel.project_dir', sys_get_temp_dir());
            (new AnalyticsExtension())->load([], $container);

            return $container;
        });
    }

    /** @param array<string,string> $env */
    private function compiledContainer(array $env = []): ContainerBuilder
    {
        return $this->withEnv($env, function () {
            $container = new ContainerBuilder();
            $container->setParameter('kernel.project_dir', sys_get_temp_dir());

            $package = new AnalyticsPackage();
            $package->build($container);
            $extension = new AnalyticsExtension();
            $container->registerExtension($extension);
            $container->loadFromExtension($extension->getAlias());
            $container->compile();

            return $container;
        });
    }

    /** @param array<string,string> $env */
    private function withEnv(array $env, callable $build): ContainerBuilder
    {
        $previous = [];
        foreach ($env as $key => $value) {
            $previous[$key] = $_ENV[$key] ?? null;
            $_ENV[$key] = $value;
        }

        try {
            return $build();
        } finally {
            foreach ($previous as $key => $value) {
                if ($value === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $value;
                }
            }
        }
    }
}
