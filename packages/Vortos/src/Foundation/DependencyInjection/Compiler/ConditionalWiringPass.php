<?php

declare(strict_types=1);

namespace Vortos\Foundation\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Foundation\DependencyInjection\MissingCapabilityException;

/**
 * Base class for compiler passes that perform CROSS-PACKAGE conditional wiring.
 *
 * The rule: Extension::load() registers only a package's own services and reads only its own
 * config. Any decision that depends on ANOTHER package's services must happen in a compiler
 * pass, because load() runs during MergeExtensionConfigurationPass where $container->has() on a
 * foreign service is unreliable (it depends on extension load order). A compiler pass runs after
 * every load(), so has() here reflects the fully-merged container and is order-independent.
 *
 * Subclasses implement {@see wire()} and use {@see requireCapability()} (hard dep → fail loud)
 * or {@see optionalCapability()} (soft dep → graceful) to gate registrations.
 */
abstract class ConditionalWiringPass implements CompilerPassInterface
{
    final public function process(ContainerBuilder $container): void
    {
        $this->wire($container);
    }

    /**
     * Perform the cross-package wiring. has()/hasDefinition()/hasAlias() are reliable here.
     */
    abstract protected function wire(ContainerBuilder $container): void;

    /**
     * Assert a HARD capability is present, or throw a fail-loud MissingCapabilityException.
     *
     * Use for library services that genuinely cannot function without the collaborator. For
     * console commands prefer register-always + runtime FAILURE instead (keep them visible).
     */
    final protected function requireCapability(
        ContainerBuilder $container,
        string $capabilityId,
        string $consumer,
        string $installPackage,
    ): void {
        if (!$this->hasCapability($container, $capabilityId)) {
            throw new MissingCapabilityException($consumer, $capabilityId, $installPackage);
        }
    }

    /**
     * Return whether an OPTIONAL capability is present. Callers wire the collaborator when true
     * and degrade gracefully when false — never throwing.
     */
    final protected function optionalCapability(ContainerBuilder $container, string $capabilityId): bool
    {
        return $this->hasCapability($container, $capabilityId);
    }

    private function hasCapability(ContainerBuilder $container, string $capabilityId): bool
    {
        return $container->has($capabilityId)
            || $container->hasDefinition($capabilityId)
            || $container->hasAlias($capabilityId);
    }
}
