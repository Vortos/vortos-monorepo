<?php

namespace Fortizan\Tekton\DependencyInjection\Compiler\Bus;

use Exception;
use Reflection;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Transport\TransportInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service_locator;

class EventAttributeCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('messenger.sender_locator')) {
            return;
        }

        $senderLocatorDefinition = $container->getDefinition('messenger.sender_locator');

        $eventIds = $container->findTaggedServiceIds('tekton.event');

        // building the event map with the transports
        $eventMap = [];
        $transports = [];
        $topicNamesMap = [];
        foreach ($eventIds as $id => $tags) {
// same transport could have muti topics in several event class, get them all
            $eventTransports = explode(',', $tags[0]['channel']); #this is for fan out ( multi transport per event)

            $topic =  $tags[0]['topic'];
            $version =  $tags[0]['version'];
            $transportIds = [];

            if($topic === null){
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
            }else{
                $topic = preg_replace('/v\d+$/', $version, $topic);
            }

            // dd($topic);

            foreach ($eventTransports as $eventTransport) {

                $transportDsnEnvName = "MESSENGER_TRANSPORT_" . strtoupper(str_replace(['-', ' '], '_', $eventTransport)) . "_PRODUCER_DSN";

                // $transportId = 'tekton.transport.' . $eventTransport . '.' . $topic;
                $producerTransportId = "tekton.transport." . strtolower(str_replace(['-', ' '], '_', $eventTransport)) . ".producer";

                $topicNamesMap[$eventTransport][] = $topic;
                
                $transportIds[] = $producerTransportId;

                $transports[$producerTransportId] = [$transportDsnEnvName, $topic];
            }
            $eventMap[$id] = $transportIds;
        }

        // build transports map assuming convention is followed
        $transportMap = [];
        foreach ($transports as $transportId => [$dsnEnvName, $topic]) {

            if (!$container->hasDefinition($transportId)) {
                $container->register($transportId, TransportInterface::class)
                    ->setFactory([new Reference('tekton.transport.factory'), 'createTransport'])
                    ->setArguments([
                        $dsnEnvName,
                    [
                        // --- 1. ROUTING FALLBACK ---
                        'topic' => ["{$topic}_default"],

                        // --- 2. PHP SENDER BEHAVIOR (Shutdown) ---
                        // How long to wait for the memory buffer to empty when script ends?
                        'flushTimeout' => 10000, // 10 Seconds
                        'flushRetries' => 3,     // Try 3 times

                        // --- 3. LIBRDKAFKA PRODUCER TUNING (Performance & Safety) ---
                        'kafka_conf' => [
                            // DATA SAFETY: Ensures "Exactly Once" delivery. 
                            // Automatically sets acks=all and max.in.flight.requests=5
                            'enable.idempotence' => 'true',

                            // COMPRESSION: Saves network bandwidth and disk space on broker.
                            // 'snappy' is the best balance of CPU vs Size.
                            'compression.codec' => 'snappy',

                            // LATENCY VS THROUGHPUT:
                            // Wait 5ms to bundle messages together. 
                            // 0 = Instant (Low latency, High CPU). 
                            // 5 = Batched (High Throughput, Low CPU).
                            'queue.buffering.max.ms' => '5',

                            // RETRIES: 
                            // How many times to retry sending if the broker is unreachable.
                            'message.send.max.retries' => '10',

                            // TIMEOUTS:
                            // How long to wait for an ack before considering it a failure.
                            'request.timeout.ms' => '30000', // 30s
                            'delivery.timeout.ms' => '45000', // 45s (Must be > request.timeout)
                        ],

                        'topic_conf' => [
                            // Force leader to wait for replicas to acknowledge (Data Safety)
                            // 'request.required.acks' => '-1', // Implied by enable.idempotence=true
                        ],

                        // --- 4. CONSTRUCTOR SATISFACTION (Dummy Values) ---
                        // These are required by the KafkaTransport constructor but 
                        // unused because we never call get() on this object.
                        'receiveTimeout' => 0,
                        'batch_size' => 0,
                        'commitAsync' => false,
                    ],
                        new Reference('tekton.messenger.serializer')
                    ]);
            }

            $transportMap[$transportId] = new Reference($transportId);
        }

        $senderLocatorDefinition->setArguments([
            $eventMap,
            service_locator($transportMap)
        ]);
    }
}
