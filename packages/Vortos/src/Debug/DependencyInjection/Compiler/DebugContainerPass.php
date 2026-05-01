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
                'args'   => $this->summarizeArgs($definition->getArguments()),
            ];
        }

        ksort($services);

        $aliases = [];

        foreach ($container->getAliases() as $alias => $target) {
            $aliases[$alias] = (string) $target;
        }

        ksort($aliases);

        $container->findDefinition(DebugContainerCommand::class)
            ->setArgument('$services', $services)
            ->setArgument('$aliases', $aliases);
    }

    private function summarizeArgs(array $args): array
    {
        $result = [];

        foreach ($args as $key => $arg) {
            $result[$key] = $this->summarizeArg($arg);
        }

        return $result;
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
