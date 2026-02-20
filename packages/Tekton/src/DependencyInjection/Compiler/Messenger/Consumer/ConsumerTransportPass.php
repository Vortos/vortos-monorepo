<?php

namespace Fortizan\Tekton\DependencyInjection\Compiler\Messenger\Consumer;

use BackedEnum;
use Fortizan\Tekton\Bus\Event\Attribute\AsEvent;
use Fortizan\Tekton\Messenger\Consumer;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Transport\TransportInterface;

class ConsumerTransportPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(Consumer::class)) {
            return;
        }

        $consumerDefinition = $container->getDefinition(Consumer::class);
        $handlersMap = $consumerDefinition->getArgument('$globalHandlerMap');

        $groupsToTopicsMap = [];
        foreach ($handlersMap as $groupId => $groupData) {

            $currentGroupTopics = [];
            foreach ($groupData as $eventClass => $handlerData) {
                $reflectionEventClass = new ReflectionClass($eventClass);
                $asEventAttributes = $reflectionEventClass->getAttributes(AsEvent::class);

                if (empty($asEventAttributes)) {
                    throw new \RuntimeException(sprintf(
                        'The event class "%s" is missing the #[AsEvent] attribute. Every handled event must define its topic.',
                        $eventClass
                    ));
                }

                $attributeArgs = $asEventAttributes[0]->getArguments();

                if (empty($attributeArgs['topic'])) {
                    throw new \RuntimeException(sprintf(
                        'The #[AsEvent] attribute on class "%s" is missing the "topic" argument.',
                        $eventClass
                    ));
                }

                $topic = $attributeArgs['topic'];
                $version = $attributeArgs['version'];

                if($topic instanceof BackedEnum){
                    $topic = $topic->value;
                }

                if (preg_match('/v\d+$/', $topic) !== 1) {
                    $topic = $topic . '.' . $version;
                } else {
                    $topic = preg_replace('/v\d+$/', $version, $topic);
                }

                $currentGroupTopics[] = $topic;
            }

            $groupsToTopicsMap[$groupId] = array_unique($currentGroupTopics);
        }

        foreach ($groupsToTopicsMap as $groupId => $topics) {
            $consumerTransport = "MESSENGER_TRANSPORT_" . strtoupper(str_replace(['-', ' '], '_', $groupId)) . "_CONSUMER_DSN";
            $consumerTransportId = "tekton.transport." . strtolower(str_replace(['-', ' '], '_', $groupId)) . ".consumer";

            $container->register($consumerTransportId, TransportInterface::class)
                ->setFactory([new Reference('tekton.transport.factory'), 'createTransport'])
                ->setArguments([
                    $consumerTransport,
                    [
                        // --- 1. Framework Logic Options ---
                        // Who to listen to
                        'topic' => $topics,

                        // How long to block waiting for a message (ms). 
                        // 10000ms (10s) is good to prevent CPU spinning.
                        'receiveTimeout' => 10000,

                        // How many messages to fetch before yielding (Prepare for batching)
                        'batch_size' => 10,

                        // Commit logic: 
                        // false = Block until broker confirms (Safe/Slow). 
                        // true = Fire and forget (Fast/Unsafe).
                        'commitAsync' => false,

                        // --- 2. Sender (Producer) Options ---
                        // If this transport is also used to send messages (e.g. retries/DLQ)
                        'flushTimeout' => 10000,
                        'flushRetries' => 3,

                        // --- 3. Driver Configuration (passed to RdKafka\Conf) ---
                        'kafka_conf' => [
                            'group.id' => $groupId,

                            // CRITICAL: We handle commits manually in ack(). 
                            // If true, you lose data on crash.
                            'enable.auto.commit' => 'false',

                            // If no offset exists (new consumer), start at the BEGINNING.
                            // 'latest' means you miss messages sent before you started.
                            'auto.offset.reset' => 'earliest',

                            // Optimizations (Optional but recommended for production)
                            // 'fetch.min.bytes' => '1',
                            // 'heartbeat.interval.ms' => '3000',
                        ],

                        // --- 4. Topic Configuration (Specific to topics) ---
                        'topic_conf' => [
                            // 'auto.commit.interval.ms' => '100', // Irrelevant if auto.commit is false
                        ]
                    ],
                    new Reference('tekton.messenger.serializer')
                ])->setPublic(true);
        }
    }
}
