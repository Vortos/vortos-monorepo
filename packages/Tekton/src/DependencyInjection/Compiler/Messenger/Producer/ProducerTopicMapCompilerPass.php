<?php

namespace Fortizan\Tekton\DependencyInjection\Compiler\Messenger\Producer;

use Fortizan\Tekton\Messenger\Transport\Kafka\Middleware\TopicResolverMiddleware;
use RuntimeException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class ProducerTopicMapCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container):void
    {
        if(!$container->hasDefinition(TopicResolverMiddleware::class)){
            return;
        }

        $topicResolverMiddleware = $container->getDefinition(TopicResolverMiddleware::class);

        $eventClassIds = $container->findTaggedServiceIds('tekton.event');

        $eventToTopicMap = [];
        foreach($eventClassIds as $eventClass => $metaData){

            if(!isset($metaData[0]['topic'])){
                throw new RuntimeException(sprintf("Invalid topic in event class %s", $eventClass));
            }

            $topic = $metaData[0]['topic'];
            $version = $metaData[0]['version'];

            $eventToTopicMap[$eventClass] = [$topic, $version];
        }

        $topicResolverMiddleware->setArgument('$eventToTopicMap', $eventToTopicMap);

    }
}