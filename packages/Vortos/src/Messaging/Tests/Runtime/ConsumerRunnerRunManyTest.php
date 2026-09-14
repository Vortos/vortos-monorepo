<?php

declare(strict_types=1);

namespace Vortos\Messaging\Tests\Runtime;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Cache\Contract\AtomicCacheInterface;
use Vortos\Messaging\Contract\ConsumerInterface;
use Vortos\Messaging\Contract\ConsumerLocatorInterface;
use Vortos\Messaging\Contract\PollableConsumerInterface;
use Vortos\Messaging\Contract\ProducerInterface;
use Vortos\Messaging\Contract\SerializerInterface;
use Vortos\Messaging\DeadLetter\DeadLetterWriter;
use Vortos\Messaging\Middleware\MiddlewareStack;
use Vortos\Messaging\Registry\ConsumerRegistry;
use Vortos\Messaging\Registry\HandlerRegistry;
use Vortos\Messaging\Retry\RetryDecider;
use Vortos\Messaging\Retry\RetryDelayCalculator;
use Vortos\Messaging\Runtime\ConsumerRunner;
use Vortos\Messaging\Serializer\SerializerLocator;
use Vortos\Messaging\ValueObject\ReceivedMessage;

/**
 * A pollable fake that hands out a fixed queue one message per poll and records what happened to it,
 * into a log shared with the other fakes so cross-consumer ordering is observable.
 */
final class RecordingPollableConsumer implements PollableConsumerInterface
{
    public bool $opened = false;
    public bool $closed = false;
    public bool $stopped = false;
    private bool $running = false;
    private bool $failed = false;

    /**
     * @param list<ReceivedMessage> $queue
     * @param \ArrayObject<int, string> $log
     */
    public function __construct(
        private readonly string $name,
        private array $queue,
        private readonly \ArrayObject $log,
        private readonly bool $failOnFirstPoll = false,
    ) {}

    public function open(string $consumerName): void
    {
        $this->opened  = true;
        $this->running = true;
    }

    public function poll(string $consumerName, callable $handler, int $timeoutMs): bool
    {
        if ($this->failOnFirstPoll) {
            $this->failed  = true;
            $this->running = false;
            return false;
        }

        $message = array_shift($this->queue);
        if ($message === null) {
            $this->running = false;
            return false;
        }

        $handler($message);

        return true;
    }

    public function close(string $consumerName): void { $this->closed = true; }
    public function isRunning(): bool { return $this->running; }
    public function hasFailed(): bool { return $this->failed; }
    public function consume(string $consumerName, callable $handler): void {}
    public function stop(): void { $this->stopped = true; $this->running = false; }
    public function acknowledge(ReceivedMessage $message): void { $this->log[] = $this->name . ':' . $message->id; }
    public function reject(ReceivedMessage $message, bool $requeue = false): void { $this->log[] = $this->name . ':rejected:' . $message->id; }
}

final class ConsumerRunnerRunManyTest extends TestCase
{
    private const WIRE_NAME = 'messaging.runner_test_payload';

    public function test_consumers_are_served_in_turn_so_a_backlog_cannot_starve_a_neighbour(): void
    {
        $log = new \ArrayObject();
        $a   = new RecordingPollableConsumer('a', [$this->message('a1'), $this->message('a2'), $this->message('a3')], $log);
        $b   = new RecordingPollableConsumer('b', [$this->message('b1')], $log);

        $this->runner(['a' => $a, 'b' => $b])->runMany(['a', 'b']);

        self::assertSame(['a:a1', 'b:b1', 'a:a2', 'a:a3'], $log->getArrayCopy());
        self::assertTrue($a->opened && $a->closed && $b->opened && $b->closed);
    }

    public function test_max_messages_counts_across_every_consumer(): void
    {
        $log = new \ArrayObject();
        $a   = new RecordingPollableConsumer('a', [$this->message('a1'), $this->message('a2')], $log);
        $b   = new RecordingPollableConsumer('b', [$this->message('b1'), $this->message('b2')], $log);

        $this->runner(['a' => $a, 'b' => $b])->runMany(['a', 'b'], maxMessages: 3);

        self::assertCount(3, $log);
        self::assertTrue($a->stopped && $b->stopped, 'Reaching the limit stops every consumer, not only the one that hit it.');
        self::assertTrue($a->closed && $b->closed);
    }

    public function test_a_consumer_stopped_by_the_broker_fails_the_whole_process_after_closing_all(): void
    {
        $log     = new \ArrayObject();
        $healthy = new RecordingPollableConsumer('healthy', [$this->message('h1'), $this->message('h2')], $log);
        $broken  = new RecordingPollableConsumer('broken', [], $log, failOnFirstPoll: true);

        try {
            $this->runner(['healthy' => $healthy, 'broken' => $broken])->runMany(['healthy', 'broken']);
            self::fail('A failed consumer in a shared process must not be silently absorbed.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('broken', $e->getMessage());
        }

        self::assertTrue($healthy->closed && $broken->closed, 'Every consumer is closed before the failure is thrown.');
        self::assertTrue($healthy->stopped, 'The healthy neighbour is stopped so it drains rather than being killed mid-message.');
    }

    public function test_stop_drains_every_consumer_in_the_process(): void
    {
        $log = new \ArrayObject();
        $a   = new RecordingPollableConsumer('a', [$this->message('a1'), $this->message('a2')], $log);
        $b   = new RecordingPollableConsumer('b', [$this->message('b1'), $this->message('b2')], $log);

        $runner = $this->runner(['a' => $a, 'b' => $b]);
        // One message into the run, a signal arrives.
        $runner->runMany(['a', 'b'], maxMessages: 1);

        self::assertTrue($runner->isDraining());
        self::assertTrue($a->stopped && $b->stopped);
    }

    public function test_a_consumer_that_cannot_be_polled_is_refused_before_anything_opens(): void
    {
        $log      = new \ArrayObject();
        $pollable = new RecordingPollableConsumer('pollable', [$this->message('p1')], $log);
        $legacy   = $this->createMock(ConsumerInterface::class);

        $this->expectException(\LogicException::class);

        try {
            $this->runner(['pollable' => $pollable, 'legacy' => $legacy])->runMany(['pollable', 'legacy']);
        } finally {
            self::assertFalse($pollable->opened);
        }
    }

    private function message(string $id): ReceivedMessage
    {
        return new ReceivedMessage(
            id:            $id,
            payload:       '{"value":"test"}',
            headers:       [
                'payload_type'      => self::WIRE_NAME . '.v1',
                'event_id'          => 'evt-' . $id,
                'aggregate_id'      => 'agg-001',
                'aggregate_type'    => 'TestAggregate',
                'aggregate_version' => '1',
                'schema_version'    => '1',
                'occurred_at'       => '2026-01-15T10:00:00+00:00',
            ],
            transportName: 'test.transport',
            receivedAt:    new DateTimeImmutable(),
        );
    }

    /** @param array<string, ConsumerInterface> $consumers */
    private function runner(array $consumers): ConsumerRunner
    {
        $cache = new class implements AtomicCacheInterface {
            public function get($key, $default = null): mixed { return $default; }
            public function set($key, $value, $ttl = null): bool { return true; }
            public function delete($key): bool { return true; }
            public function clear(): bool { return true; }
            public function getMultiple($keys, $default = null): iterable { return []; }
            public function setMultiple($values, $ttl = null): bool { return true; }
            public function deleteMultiple($keys): bool { return true; }
            public function has($key): bool { return false; }
            public function setNx(string $key, mixed $value, int $ttl): bool { return true; }
        };

        $serializer = new class implements SerializerInterface {
            public function supports(string $format): bool { return true; }
            public function serialize(object $payload): string { return '{}'; }
            public function deserialize(string $payload, string $payloadClass): object { return new RunnerTestPayload('deserialized'); }
        };

        $connection = $this->createMock(Connection::class);
        $connection->method('insert')->willReturn(1);

        return new ConsumerRunner(
            handlerRegistry:   new HandlerRegistry([]),
            serializerLocator: new SerializerLocator([$serializer]),
            middlewareStack:   new MiddlewareStack([]),
            deadLetterWriter:  new DeadLetterWriter($connection, new NullLogger()),
            producer:          $this->createMock(ProducerInterface::class),
            cache:             $cache,
            consumerLocator:   new class($consumers) implements ConsumerLocatorInterface {
                /** @param array<string, ConsumerInterface> $consumers */
                public function __construct(private array $consumers) {}
                public function get(string $name): ConsumerInterface { return $this->consumers[$name]; }
            },
            logger:            new NullLogger(),
            handlerLocator:    new ServiceLocator([]),
            retryDecider:      new RetryDecider(new RetryDelayCalculator()),
            consumerRegistry:  new ConsumerRegistry([]),
            wireEventMap:      [self::WIRE_NAME => RunnerTestPayload::class],
        );
    }
}
