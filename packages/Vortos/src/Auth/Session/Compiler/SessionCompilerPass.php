<?php
declare(strict_types=1);

namespace Vortos\Auth\Session\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Auth\Session\Contract\SessionPolicyInterface;
use Vortos\Auth\Session\SessionEnforcer;

/**
 * Discovers a SessionPolicyInterface implementation and wires it into SessionEnforcer.
 * If none is registered, session limiting is disabled (policy stays null).
 */
final class SessionCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(SessionEnforcer::class)) {
            return;
        }

        foreach ($container->getDefinitions() as $serviceId => $definition) {
            $class = $definition->getClass();
            if (!$class || !class_exists($class)) {
                continue;
            }

            if (is_a($class, SessionPolicyInterface::class, true)) {
                $container->getDefinition(SessionEnforcer::class)
                    ->setArgument('$policy', new Reference($serviceId));
                return;
            }
        }
    }
}
