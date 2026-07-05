<?php

declare(strict_types=1);

namespace Vortos\Foundation\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Argument\AbstractArgument;
use Symfony\Component\DependencyInjection\Argument\ArgumentInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Parameter;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Variable;
use Symfony\Component\ExpressionLanguage\Expression;

/**
 * Fail-closed guard against non-dumpable service arguments (B21).
 *
 * Symfony's {@see \Symfony\Component\DependencyInjection\Dumper\PhpDumper} — used to cache the prod
 * HTTP container — cannot serialise a service whose argument is a raw object instance (it throws
 * "Unable to dump a service container if a parameter is an object or a resource, got ..."). The
 * dumper reveals offenders one at a time, only during a real prod HTTP boot, so a whole class of DI
 * bugs stayed invisible until the first production deploy.
 *
 * This pass runs on EVERY compile (dev, test, prod, CI). It walks every definition's arguments,
 * properties, method-call args, and factory, mirrors PhpDumper's own dumpability rules, and — if any
 * argument is a raw object instance rather than an inline Definition / Reference / scalar / enum —
 * fails the compile listing EVERY offender at once, before PhpDumper is ever reached. The fix is
 * always to pass an inline `Definition` (or scalar / Reference) instead of an instantiated object.
 *
 * Registered as late as possible ({@see \Symfony\Component\DependencyInjection\Compiler\PassConfig}
 * TYPE_AFTER_REMOVING) so it sees only live, fully-resolved definitions.
 */
final class ContainerDumpabilityPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $offenders = [];

        foreach ($container->getDefinitions() as $id => $definition) {
            if ($definition->isSynthetic() || $definition->isAbstract()) {
                continue;
            }

            $this->inspectDefinition($id, $definition, $offenders);
        }

        if ($offenders !== []) {
            sort($offenders);

            throw new \LogicException(
                "The service container cannot be dumped (PhpDumper rejects raw object instances as "
                . "service arguments — this breaks the prod HTTP boot, B21).\n\n"
                . "Pass an inline Definition (or a scalar / Reference / enum) instead of an instantiated "
                . "object, e.g. `new Definition(Foo::class, [\$scalar])` rather than `new Foo(\$scalar)`.\n\n"
                . "Non-dumpable arguments:\n  - " . implode("\n  - ", $offenders),
            );
        }
    }

    /**
     * @param list<string> $offenders
     */
    private function inspectDefinition(string $id, Definition $definition, array &$offenders): void
    {
        foreach ($definition->getArguments() as $key => $argument) {
            $this->inspectValue($id, $this->argPath($key), $argument, $offenders);
        }

        foreach ($definition->getProperties() as $name => $value) {
            $this->inspectValue($id, '$' . $name, $value, $offenders);
        }

        foreach ($definition->getMethodCalls() as $call) {
            $method = $call[0] ?? '?';
            foreach (($call[1] ?? []) as $key => $argument) {
                $this->inspectValue($id, $method . '()' . $this->argPath($key), $argument, $offenders);
            }
        }

        $factory = $definition->getFactory();
        if (is_array($factory) && isset($factory[0]) && is_object($factory[0])
            && !$factory[0] instanceof Reference && !$factory[0] instanceof Definition) {
            $offenders[] = sprintf('%s [factory]: %s', $id, get_debug_type($factory[0]));
        }
    }

    /**
     * @param list<string> $offenders
     */
    private function inspectValue(string $id, string $path, mixed $value, array &$offenders): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $this->inspectValue($id, $path . $this->argPath($key), $item, $offenders);
            }

            return;
        }

        if (!is_object($value)) {
            // Scalars, null, resources-as-args don't occur here; is_resource is caught by PhpDumper
            // too but cannot be produced by the DI config surface.
            return;
        }

        // Inline definitions are dumpable, but their own arguments must be too — recurse.
        if ($value instanceof Definition) {
            $this->inspectDefinition($id . ' → inline(' . ($value->getClass() ?? 'mixed') . ')', $value, $offenders);

            return;
        }

        // Value objects PhpDumper knows how to serialise as arguments.
        if ($value instanceof Reference
            || $value instanceof Parameter
            || $value instanceof Expression
            || $value instanceof Variable
            || $value instanceof ArgumentInterface   // Tagged/Iterator/ServiceLocator/ServiceClosure/…
            || $value instanceof AbstractArgument
            || $value instanceof \UnitEnum) {
            return;
        }

        $offenders[] = sprintf('%s %s: %s', $id, $path, get_debug_type($value));
    }

    private function argPath(int|string $key): string
    {
        return is_int($key) ? sprintf('[%d]', $key) : sprintf("['%s']", $key);
    }
}
