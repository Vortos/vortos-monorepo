<?php

declare(strict_types=1);

namespace Vortos\Backup\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Backup\Drill\DrillRunner;

final class CollectInvariantChecksPass implements CompilerPassInterface
{
    public const TAG = 'vortos.backup.invariant_check';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(DrillRunner::class)) {
            return;
        }

        $refs = [];
        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            $refs[] = new Reference($id);
        }

        $container->getDefinition(DrillRunner::class)->setArgument('$invariantChecks', $refs);
    }
}
