<?php

declare(strict_types=1);

namespace Vortos\Audit\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Audit\Action\AuditActionProviderInterface;
use Vortos\Audit\Action\AuditActionRegistry;

/**
 * Collects every service tagged as an audit action provider and injects them into the
 * registry, so the controlled vocabulary is assembled once at compile time from all
 * framework modules + the app.
 */
final class AuditActionProviderPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(AuditActionRegistry::class)) {
            return;
        }

        $refs = [];
        foreach (array_keys($container->findTaggedServiceIds(AuditActionProviderInterface::TAG)) as $id) {
            $refs[] = new Reference($id);
        }

        $container->getDefinition(AuditActionRegistry::class)
            ->setArgument('$providers', $refs);
    }
}
