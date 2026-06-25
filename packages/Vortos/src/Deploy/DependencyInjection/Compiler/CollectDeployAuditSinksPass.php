<?php

declare(strict_types=1);

namespace Vortos\Deploy\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Deploy\Audit\DeployAuditRecorder;

/**
 * Collects every service tagged {@see self::TAG} (autoconfigured from
 * DeployAuditSinkInterface, regardless of which package registers it — see
 * that interface's docblock) into an ordered list and injects it into
 * {@see DeployAuditRecorder}. Zero-sink is a valid, common case (Observability
 * not installed) — the recorder is then a pure no-op.
 */
final class CollectDeployAuditSinksPass implements CompilerPassInterface
{
    public const TAG = 'vortos.deploy.audit_sink';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(DeployAuditRecorder::class)) {
            return;
        }

        $refs = [];
        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            $refs[] = new Reference($id);
        }

        $container->getDefinition(DeployAuditRecorder::class)
            ->setArgument('$sinks', $refs);
    }
}
