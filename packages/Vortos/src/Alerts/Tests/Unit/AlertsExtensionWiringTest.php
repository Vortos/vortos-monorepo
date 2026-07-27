<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Alerts\DependencyInjection\AlertsExtension;

/**
 * Guards the Alerts DI wiring.
 *
 * Nothing built this container in a test, and that gap has real cost. When `Dedupe`'s constructor
 * changed, the extension kept passing the removed `$digestEvery` argument — every unit test still
 * passed, because they construct `Dedupe` directly, and the failure would only have appeared when
 * the container compiled in production.
 *
 * A constructor-argument mismatch is not a subtle bug; it is one an entire test suite can simply
 * never see. These assertions are cheap and close that whole class.
 */
final class AlertsExtensionWiringTest extends TestCase
{
    private function load(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('vortos.db.framework_table_prefix', 'vortos_');
        (new AlertsExtension())->load([], $container);

        return $container;
    }

    public function test_every_registered_alerts_service_maps_to_a_real_class(): void
    {
        $container = $this->load();

        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass();

            if ($class === null || !str_starts_with($class, 'Vortos\\Alerts')) {
                continue;
            }

            self::assertTrue(
                class_exists($class) || interface_exists($class),
                "Service '{$id}' references missing class '{$class}' (likely a missing use-import).",
            );
        }
    }

    public function test_no_service_passes_a_named_argument_its_constructor_does_not_accept(): void
    {
        // The exact regression. `Dedupe` lost its `$digestEvery` parameter when reminders moved to
        // time-based backoff, and the extension went on passing it — invisible to every unit test,
        // fatal at container compile.
        $container = $this->load();

        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass();

            if ($class === null || !str_starts_with($class, 'Vortos\\Alerts') || !class_exists($class)) {
                continue;
            }

            $constructor = (new \ReflectionClass($class))->getConstructor();
            $accepted = [];

            foreach ($constructor?->getParameters() ?? [] as $parameter) {
                $accepted[] = '$' . $parameter->getName();
            }

            foreach (array_keys($definition->getArguments()) as $argument) {
                if (!is_string($argument) || !str_starts_with($argument, '$')) {
                    continue; // positional
                }

                self::assertContains(
                    $argument,
                    $accepted,
                    sprintf(
                        "Service '%s' (%s) is given '%s', which its constructor does not accept. "
                        . 'This compiles nowhere and fails only at container build time.',
                        $id,
                        $class,
                        $argument,
                    ),
                );
            }
        }
    }
}
