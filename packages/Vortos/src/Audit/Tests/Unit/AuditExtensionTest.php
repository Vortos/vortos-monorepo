<?php

declare(strict_types=1);

namespace Vortos\Audit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Audit\AuditTrail;
use Vortos\Audit\AuditTrailInterface;
use Vortos\Audit\DependencyInjection\AuditExtension;

/**
 * Guards the DI wiring across every phase: a missing `use` import makes `Foo::class`
 * silently resolve to the wrong FQN, which unit tests that never build the container
 * would not catch. This asserts every registered audit service maps to a real class.
 */
final class AuditExtensionTest extends TestCase
{
    private function load(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('vortos.db.framework_table_prefix', 'vortos_');
        (new AuditExtension())->load([], $container);

        return $container;
    }

    public function test_every_registered_audit_service_maps_to_a_real_class(): void
    {
        $container = $this->load();

        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass();
            if ($class === null || !str_starts_with($class, 'Vortos\\Audit')) {
                continue;
            }
            self::assertTrue(
                class_exists($class) || interface_exists($class),
                "Service '{$id}' references missing class '{$class}' (likely a missing use-import).",
            );
        }
    }

    public function test_core_services_and_facade_are_wired(): void
    {
        $container = $this->load();

        self::assertTrue($container->hasDefinition(AuditTrail::class));
        self::assertTrue($container->hasAlias(AuditTrailInterface::class));
        self::assertTrue($container->getDefinition(AuditTrail::class)->isPublic());
    }
}
