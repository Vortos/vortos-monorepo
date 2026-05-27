<?php

declare(strict_types=1);

namespace Vortos\Ses\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Ses\Bounce\AutoSuppressionBounceHandler;
use Vortos\Ses\Bounce\BounceHandlerRunner;

/**
 * Collects all services tagged 'vortos_ses.bounce_handler', prepends
 * AutoSuppressionBounceHandler, and injects the full list into BounceHandlerRunner.
 */
final class BounceHandlerDiscoveryPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(BounceHandlerRunner::class)) {
            return;
        }

        $tagged = $container->findTaggedServiceIds('vortos_ses.bounce_handler');
        $userRefs = array_map(fn($id) => new Reference($id), array_keys($tagged));

        // AutoSuppression always runs first
        $allRefs = [new Reference(AutoSuppressionBounceHandler::class), ...$userRefs];

        $container->getDefinition(BounceHandlerRunner::class)
            ->setArgument('$handlers', $allRefs);
    }
}
