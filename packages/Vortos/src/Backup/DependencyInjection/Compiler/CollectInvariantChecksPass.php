<?php

declare(strict_types=1);

namespace Vortos\Backup\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Backup\Drill\DrillRunner;
use Vortos\Backup\PgBackRest\PgBackRestDrill;

final class CollectInvariantChecksPass implements CompilerPassInterface
{
    public const TAG = 'vortos.backup.invariant_check';

    public function process(ContainerBuilder $container): void
    {
        $refs = [];
        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            $refs[] = new Reference($id);
        }

        // Every drill proves the restored database with the same checks, whichever restore path it took.
        foreach ([DrillRunner::class, PgBackRestDrill::class] as $drill) {
            if ($container->hasDefinition($drill)) {
                $container->getDefinition($drill)->setArgument('$invariantChecks', $refs);
            }
        }
    }
}
