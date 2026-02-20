<?php

namespace Fortizan\Tekton\Messenger\Transport\Kafka\Middleware;

use Fortizan\Tekton\Messenger\Transport\Kafka\Stamp\KafkaTopicStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

class TopicResolverMiddleware implements MiddlewareInterface
{
    public function __construct(
        private array $eventToTopicMap,
    ){
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();
        $eventClass = get_class($message);

        if (isset($this->eventToTopicMap[$eventClass])) {
            [$topic, $version] = $this->eventToTopicMap[$eventClass];
            $envelope = $envelope->with(new KafkaTopicStamp($topic, $version));
        }

        return $stack->next()->handle($envelope, $stack);
    }
}