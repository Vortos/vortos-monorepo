<?php

declare(strict_types=1);

namespace Vortos\AwsSes\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\AwsSes\Bounce\AutoSuppressionComplaintHandler;
use Vortos\AwsSes\Bounce\ComplaintHandlerRunner;

/**
 * Collects all services tagged 'vortos_aws_ses.complaint_handler', prepends
 * AutoSuppressionComplaintHandler, and injects the full list into ComplaintHandlerRunner.
 */
final class ComplaintHandlerDiscoveryPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ComplaintHandlerRunner::class)) {
            return;
        }

        $tagged = $container->findTaggedServiceIds('vortos_aws_ses.complaint_handler');
        $userRefs = array_map(fn($id) => new Reference($id), array_keys($tagged));

        // AutoSuppression always runs first
        $allRefs = [new Reference(AutoSuppressionComplaintHandler::class), ...$userRefs];

        $container->getDefinition(ComplaintHandlerRunner::class)
            ->setArgument('$handlers', $allRefs);
    }
}
