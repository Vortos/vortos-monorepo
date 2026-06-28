<?php

declare(strict_types=1);

namespace Vortos\Foundation\DependencyInjection\Compiler;

use ReflectionClass;
use ReflectionNamedType;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Foundation\DependencyInjection\Attribute\AsDecorator;

/**
 * Processes #[AsDecorator] attributes and wires decorator chains.
 *
 * For each service tagged 'vortos.decorator':
 *   1. Read the #[AsDecorator] attribute — $decorates (target) and $priority (chain order).
 *   2. Validate: target exists, no self-decoration, interface implemented, inner param present.
 *   3. Validate: no two decorators share the same priority on the same target.
 *   4. Wire chains — lower priority = innermost; higher priority = outermost.
 *
 * Inner service ID format: "{decorates}.vortos_inner_{n}"
 *
 * All errors are collected and thrown as a single exception.
 *
 * Runs at TYPE_BEFORE_OPTIMIZATION priority -5 — after DefaultImplCompilerPass (5)
 * and OverrideImplCompilerPass (0), so all aliases are settled before decoration.
 */
final class DecoratorCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $errors = [];
        $groups = [];

        foreach ($container->findTaggedServiceIds('vortos.decorator') as $serviceId => $_) {
            $definition = $container->getDefinition($serviceId);
            $className  = $definition->getClass() ?? $serviceId;

            if (!class_exists($className)) {
                continue;
            }

            $reflClass = new ReflectionClass($className);
            $attrs     = $reflClass->getAttributes(AsDecorator::class);

            if (empty($attrs)) {
                continue;
            }

            /** @var AsDecorator $attr */
            $attr      = $attrs[0]->newInstance();
            $decorates = $attr->decorates;
            $priority  = $attr->priority;

            if (!$container->hasAlias($decorates) && !$container->hasDefinition($decorates)) {
                $errors[] = $this->formatError(
                    $className,
                    $reflClass,
                    sprintf(
                        'Target "%s" has no alias or definition in the container. '
                        . 'Cannot decorate a service that does not exist.',
                        $decorates,
                    ),
                );
                continue;
            }

            if ($className === $decorates || $serviceId === $decorates) {
                $errors[] = $this->formatError(
                    $className,
                    $reflClass,
                    'A class cannot decorate itself.',
                );
                continue;
            }

            if (interface_exists($decorates) && !$reflClass->implementsInterface($decorates)) {
                $errors[] = $this->formatError(
                    $className,
                    $reflClass,
                    sprintf(
                        'Target "%s" is an interface but "%s" does not implement it. '
                        . 'The decorator must implement the same interface as the service it wraps.',
                        $decorates,
                        $className,
                    ),
                );
                continue;
            }

            $innerParam = $this->findInnerParam($reflClass, $decorates);

            if ($innerParam === null) {
                $errors[] = $this->formatError(
                    $className,
                    $reflClass,
                    sprintf(
                        'No constructor parameter typed to "%s" found on "%s". '
                        . 'Add a constructor argument with type "%s" to receive the inner service.',
                        $decorates,
                        $className,
                        $decorates,
                    ),
                );
                continue;
            }

            $groups[$decorates][] = [
                'serviceId'  => $serviceId,
                'className'  => $className,
                'priority'   => $priority,
                'reflClass'  => $reflClass,
                'innerParam' => $innerParam,
            ];
        }

        foreach ($groups as $decorates => $entries) {
            $byPriority = [];
            foreach ($entries as $entry) {
                $p = $entry['priority'];
                if (isset($byPriority[$p])) {
                    $errors[] = $this->formatError(
                        $entry['className'],
                        $entry['reflClass'],
                        sprintf(
                            'Priority conflict on "%s": both "%s" and "%s" have priority %d. '
                            . 'Assign distinct priorities to control chain order.',
                            $decorates,
                            $byPriority[$p],
                            $entry['className'],
                            $p,
                        ),
                    );
                } else {
                    $byPriority[$p] = $entry['className'];
                }
            }
        }

        if ($errors !== []) {
            throw new \LogicException($this->formatCombinedError($errors));
        }

        foreach ($groups as $decorates => $entries) {
            usort($entries, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);

            $n = 0;

            foreach ($entries as $entry) {
                $currentTarget = $this->resolveCurrentTarget($container, $decorates);

                $innerId = sprintf('%s.vortos_inner_%d', $decorates, $n);
                $container->setAlias($innerId, $currentTarget)->setPublic(false);

                $container
                    ->getDefinition($entry['serviceId'])
                    ->setArgument('$' . $entry['innerParam'], new Reference($innerId));

                $container->setAlias($decorates, $entry['serviceId'])->setPublic(true);

                $n++;
            }
        }
    }

    private function resolveCurrentTarget(ContainerBuilder $container, string $id): string
    {
        $visited = [];

        while ($container->hasAlias($id)) {
            if (isset($visited[$id])) {
                throw new \LogicException(sprintf('Circular alias chain detected for "%s".', $id));
            }
            $visited[$id] = true;
            $id = (string) $container->getAlias($id);
        }

        return $id;
    }

    private function findInnerParam(ReflectionClass $reflClass, string $decorates): ?string
    {
        $constructor = $reflClass->getConstructor();

        if ($constructor === null) {
            return null;
        }

        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();

            if (!$type instanceof ReflectionNamedType) {
                continue;
            }

            if ($type->getName() === $decorates) {
                return $param->getName();
            }
        }

        return null;
    }

    private function formatError(string $className, ReflectionClass $reflClass, string $reason): string
    {
        return sprintf(
            '#[AsDecorator] on "%s" (%s:%d)' . "\n" . '    → %s',
            $className,
            $reflClass->getFileName() ?: 'unknown',
            $reflClass->getStartLine(),
            $reason,
        );
    }

    private function formatCombinedError(array $errors): string
    {
        $count = count($errors);
        $lines = sprintf('%d container configuration error(s) found:', $count) . "\n\n";

        foreach ($errors as $i => $error) {
            $lines .= sprintf('  [%d] ', $i + 1) . str_replace("\n", "\n  ", $error) . "\n\n";
        }

        return rtrim($lines);
    }
}
