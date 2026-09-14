<?php

declare(strict_types=1);

namespace Vortos\Messaging\Driver\InMemory\Runtime;

use Vortos\Messaging\Contract\PollableConsumerInterface;
use Vortos\Messaging\ValueObject\ReceivedMessage;
use Psr\Log\LoggerInterface;

/**
 * In-memory consumer. Dequeues messages from InMemoryBroker and passes them to the handler.
 *
 * Unlike KafkaConsumer, this does not run an infinite polling loop.
 * It processes all available messages and exits — making tests fast and deterministic.
 * Call stop() to exit early if needed.
 *
 * Polled one message at a time it behaves the same way: once its queue is empty it stops running,
 * so a shared loop over several in-memory consumers ends when every queue is drained.
 *
 * acknowledge() is a no-op — messages are already consumed on dequeue().
 * reject() with requeue=true re-enqueues the message for reprocessing.
 */
final class InMemoryConsumer implements PollableConsumerInterface
{
    private bool $running = false;

    public function __construct(
        private InMemoryBroker $broker,
        private LoggerInterface $logger
    ) {}

    public function consume(string $consumerName, callable $handler): void
    {
        $this->open($consumerName);

        while ($this->running) {
            $this->poll($consumerName, $handler, 0);
        }

        $this->close($consumerName);
    }

    public function open(string $consumerName): void
    {
        $this->running = true;
    }

    public function poll(string $consumerName, callable $handler, int $timeoutMs): bool
    {
        if (!$this->running) {
            return false;
        }

        $message = $this->broker->dequeue($consumerName);

        if ($message === null) {
            $this->running = false;

            return false;
        }

        $handler($message);

        return true;
    }

    public function close(string $consumerName): void
    {
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function hasFailed(): bool
    {
        return false;
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function acknowledge(ReceivedMessage $message): void
    {
    }

    public function reject(ReceivedMessage $message, bool $requeue = false): void
    {
        if($requeue){
            $this->broker->enqueue($message->transportName, $message);
        }
    }
}
