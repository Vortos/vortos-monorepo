<?php

namespace Fortizan\Tekton\DependencyInjection\Compiler\Messenger;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Transport\TransportInterface;

class ConsumerTransportPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $context = $container->getParameter('kernel.context');

        if ($context !== 'console') {
            return;
        }

        $groupId = $container->getParameter('messenger.consumer.async.group_id');

        if ($groupId === null || $groupId === '') {
            throw new \RuntimeException(
                'messenger.consumer.async.group_id must be set for console context. ' .
                    'Use $runner->setParameter("messenger.consumer.async.group_id", $groupId)'
            );
        }

        $definition = new Definition(TransportInterface::class);
        $definition->setFactory([new Reference('messenger.transport_factory'), 'createTransport']);
        $definition->setArguments([
            '%MESSENGER_TRANSPORT_DSN%',
            [
                'topic' => ['name' => 'events'],
                'kafka_conf' => [
                    'group.id' => $groupId,
                    'auto.offset.reset' => 'earliest'
                ]
            ],
            new Reference('tekton.messenger.serializer')
        ]);

        $container->setDefinition('tekton.transport.consumer', $definition);
    }
}
