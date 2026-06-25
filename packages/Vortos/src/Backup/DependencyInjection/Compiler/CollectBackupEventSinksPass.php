<?php

declare(strict_types=1);

namespace Vortos\Backup\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Backup\Event\CompositeBackupEventSink;

/**
 * Collects every service tagged as a backup event sink and injects them into the
 * {@see CompositeBackupEventSink}. This is the seam Block 17 uses: an alerting sink
 * simply adds the tag and is fanned out alongside the in-core logging sink.
 */
final class CollectBackupEventSinksPass implements CompilerPassInterface
{
    public const TAG = 'vortos.backup.event_sink';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(CompositeBackupEventSink::class)) {
            return;
        }

        $refs = [];
        foreach (array_keys($container->findTaggedServiceIds(self::TAG)) as $serviceId) {
            $refs[] = new Reference($serviceId);
        }

        $container->getDefinition(CompositeBackupEventSink::class)->setArgument('$sinks', $refs);
    }
}
