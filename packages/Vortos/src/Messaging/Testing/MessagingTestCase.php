<?php

declare(strict_types=1);

namespace Vortos\Messaging\Testing;

use Vortos\Messaging\Definition\WireNaming;
use Vortos\Messaging\Driver\InMemory\Runtime\InMemoryBroker;
use Vortos\Messaging\Driver\InMemory\Runtime\InMemoryConsumer;
use Vortos\Messaging\Driver\InMemory\Runtime\InMemoryProducer;
use Vortos\Messaging\Serializer\JsonSerializer;
use Vortos\Messaging\Serializer\SerializerLocator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Base test case for messaging integration tests.
 * 
 * Provides a pre-wired InMemoryBroker, InMemoryProducer, and InMemoryConsumer.
 * The broker is reset between every test via tearDown() — no message leakage.
 */
abstract class MessagingTestCase extends TestCase
{
    protected InMemoryBroker $broker;
    protected InMemoryProducer $producer;
    protected InMemoryConsumer $consumer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->broker = new InMemoryBroker();
        $this->producer = new InMemoryProducer($this->broker, $this->buildSerializerLocator());
        $this->consumer = new InMemoryConsumer($this->broker, new NullLogger());
    }

    protected function tearDown(): void
    {
        $this->broker->reset();
        parent::tearDown();
    }

    private function buildSerializerLocator(): SerializerLocator
    {
        return new SerializerLocator(
            [
                'json' => new JsonSerializer() 
            ]
        );
    }

    /**
     * Asserts an event was produced to the transport.
     *
     * Accepts either the logical wire name ('registration.entry_approved' —
     * what is actually on the wire) or, as a convenience, the event class,
     * which is matched against its convention-derived wire name. Events
     * published with an explicit publish(..., as: '...') must be asserted by
     * that wire name.
     */
    protected function assertEventPublished(string $transportName, string $eventClassOrWireName, ?callable $matcher = null):void
    {
        $messages = $this->broker->all($transportName);

        foreach($messages as $message){
            if($this->matchesWireType($message->headers['payload_type'] ?? '', $eventClassOrWireName)){
                if($matcher === null || $matcher($message)){
                    $this->assertTrue(true);
                    return;
                }
            }
        }

        $this->fail(
            "Expected event '{$eventClassOrWireName}' was not found on transport '{$transportName}'"
        );
    }

    protected function assertEventNotPublished(string $transportName, string $eventClassOrWireName): void
    {
        $messages = $this->broker->all($transportName);

        foreach ($messages as $message) {
            if ($this->matchesWireType($message->headers['payload_type'] ?? '', $eventClassOrWireName)) {
                $this->fail(
                    "Event '{$eventClassOrWireName}' was found on transport '{$transportName}' but was not expected"
                );
            }
        }

        $this->assertTrue(true);
    }

    private function matchesWireType(string $payloadTypeHeader, string $eventClassOrWireName): bool
    {
        [$wireName] = WireNaming::parse($payloadTypeHeader);

        return $wireName === $eventClassOrWireName
            || $payloadTypeHeader === $eventClassOrWireName
            || $wireName === WireNaming::derive($eventClassOrWireName);
    }

    protected function consumeAll(string $consumerName, callable $handler): void
    {
        $this->consumer->consume($consumerName, $handler);
    }

    protected function publishedCount(string $transportName): int
    {
        return $this->broker->count($transportName);
    }
}