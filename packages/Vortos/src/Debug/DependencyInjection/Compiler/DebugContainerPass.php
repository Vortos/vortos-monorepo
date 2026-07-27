<?php

declare(strict_types=1);

namespace Vortos\Debug\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Debug\Command\DebugContainerCommand;

final class DebugContainerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $services = [];

        foreach ($container->getDefinitions() as $id => $definition) {
            $tags = array_keys($definition->getTags());

            $services[$id] = [
                'class'  => $definition->getClass() ?? $id,
                'public' => $definition->isPublic(),
                'shared' => $definition->isShared(),
                'lazy'   => $definition->isLazy(),
                'tags'   => $tags,
                'args'   => $this->summarizeArgs($definition->getArguments(), $definition->getClass()),
            ];
        }

        ksort($services);

        $aliases = [];

        foreach ($container->getAliases() as $alias => $target) {
            $aliases[$alias] = (string) $target;
        }

        ksort($aliases);

        // Positional, not named. This pass runs in AFTER_REMOVING, by which point
        // ResolveNamedArgumentsPass has already converted named arguments to indices — a '$services'
        // key here would simply never be resolved. 0 and 1 are $services and $aliases.
        $container->findDefinition(DebugContainerCommand::class)
            ->setArgument(0, $services)
            ->setArgument(1, $aliases);
    }

    /**
     * @param array<array-key, mixed> $args
     * @return array<string, string>
     */
    private function summarizeArgs(array $args, ?string $class): array
    {
        $names = $this->constructorParameterNames($class);
        $result = [];

        foreach ($args as $key => $arg) {
            // By AFTER_REMOVING the keys are positional. Recover the parameter name so the detail
            // view still reads as '$connection' rather than '0'.
            $label = is_int($key) && isset($names[$key]) ? '$' . $names[$key] : (string) $key;
            $result[$label] = $this->summarizeArg($arg);
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function constructorParameterNames(?string $class): array
    {
        if ($class === null || !class_exists($class)) {
            return [];
        }

        try {
            $ctor = (new \ReflectionClass($class))->getConstructor();
        } catch (\ReflectionException) {
            return [];
        }

        if ($ctor === null) {
            return [];
        }

        return array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $ctor->getParameters());
    }

    private function summarizeArg(mixed $arg): string
    {
        return match (true) {
            $arg instanceof Reference             => '@' . (string) $arg,
            $arg instanceof TaggedIteratorArgument => '(tagged: ' . $arg->getTag() . ')',
            $arg instanceof ServiceLocatorArgument => '(service-locator)',
            $arg instanceof Definition            => '(inline: ' . ($arg->getClass() ?? '?') . ')',
            is_array($arg)                        => '[' . implode(', ', array_map([$this, 'summarizeArg'], $arg)) . ']',
            is_bool($arg)                         => $arg ? 'true' : 'false',
            is_null($arg)                         => 'null',
            is_string($arg)                       => $arg,
            default                               => gettype($arg),
        };
    }
}
