<?php

declare(strict_types=1);

namespace Vortos\AwsSes\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\AwsSes\Bounce\AutoSuppressionBounceHandler;
use Vortos\AwsSes\Bounce\BounceHandlerRunner;

/**
 * Collects all services tagged 'vortos_aws_ses.bounce_handler', prepends
 * AutoSuppressionBounceHandler, and injects the full list into BounceHandlerRunner.
 */
final class BounceHandlerDiscoveryPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(BounceHandlerRunner::class)) {
            return;
        }

        $tagged = $container->findTaggedServiceIds('vortos_aws_ses.bounce_handler');
        $userRefs = array_map(fn($id) => new Reference($id), array_keys($tagged));

        // AutoSuppression always runs first
        $allRefs = [new Reference(AutoSuppressionBounceHandler::class), ...$userRefs];

        $container->getDefinition(BounceHandlerRunner::class)
            ->setArgument('$handlers', $allRefs);
    }
}
