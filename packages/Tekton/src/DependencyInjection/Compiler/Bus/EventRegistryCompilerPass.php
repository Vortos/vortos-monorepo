<?php

namespace Fortizan\Tekton\DependencyInjection\Compiler\Bus;

use Fortizan\Tekton\Bus\Event\Attribute\AsPartitionKey;
use Fortizan\Tekton\Bus\Event\Interface\PartitionKeyAwareInterface;
use Fortizan\Tekton\Bus\Event\Registry\Producer\ProducerRegistry;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class EventRegistryCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if(!$container->hasDefinition(ProducerRegistry::class)){
            return;
        }

        $topicRegistry = $container->getDefinition(ProducerRegistry::class);

        $eventIds = $container->findTaggedServiceIds('tekton.event');

        // building the event map with the transports
        $eventMap = [];
        $transports = [];
        $topicNamesMap = [];
        foreach ($eventIds as $id => $eventAttributes) {
            // same transport could have muti topics in several event class, get them all
            $eventClassReflection = new ReflectionClass($id);
            $asPartitionKeyAttribute = $eventClassReflection->getAttributes(AsPartitionKey::class);

            foreach($eventAttributes as $attribute){

                $channel = $attribute['channel'];
                $topic =  $attribute['topic'];
                $version =  $attribute['version'];
                $partitionKeyMethod =  $attribute['partitionKey'];
                $partitionKey =  null;

               

                if(!is_subclass_of($id, PartitionKeyAwareInterface::class) && empty($asPartitionKeyAttribute)){

                    $partitionKey = null;
                }
                
                if(is_subclass_of($id, PartitionKeyAwareInterface::class) && !empty($asPartitionKeyAttribute)){

                    throw new RuntimeException(
                        sprintf(
                            "Event class '%s' have both '#[AsPartitionKey]' and 'PartitionAwareInterface', but it cant have both",
                            $id
                        )
                    );
                }

                if(is_subclass_of($id, PartitionKeyAwareInterface::class)){

                    if($partitionKeyMethod === null){
                        $partitionKeyMethod = 'getPartitionKey';
                    }

                    if(!method_exists($id, $partitionKeyMethod)){
                        throw new RuntimeException(
                            sprintf(
                                "Event class '%s' must have method '%s' because it is defined in '#[AsEvent]' or it have implemented 'PartitionAwareInterface'",
                                $id,
                                $partitionKeyMethod
                            )
                        );  
                    }

                    $event = $container->get($id);
                    
                }

                if(!empty($asPartitionKeyAttribute)){

                    // take partition key from attribute
                }
                


            }
            $transportIds = [];

            if ($topic === null) {
                $namespaceParts = explode('\\', $id);

                $eventDomain = $namespaceParts[1];

                $classNameRaw = end($namespaceParts);
                $eventClassName = preg_replace('/Event$/', '', $classNameRaw); // "UserCreated"

                // Convert "UserCreated" -> "User.Created"
                $actionParts = preg_replace(
                    '/(?<=\p{Lu})(?=\p{Lu}\p{Ll})|(?<=\p{Ll})(?=\p{Lu})/u',
                    '.',
                    $eventClassName
                );

                $topic = strtolower($eventDomain . '.' . $actionParts);
            }

            if (preg_match('/v\d+$/', $topic) !== 1) {
                $topic = $topic . '.' . $version;
            } else {
                $topic = preg_replace('/v\d+$/', $version, $topic);
            }

            $eventIds[$id][0]['topic'] = $topic;
        }

        dd($eventIds);
    }
}
