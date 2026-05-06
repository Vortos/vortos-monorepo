<?php

declare(strict_types=1);

namespace Vortos\Tests\Messaging;

use PHPUnit\Framework\TestCase;
use Vortos\Messaging\DependencyInjection\VortosMessagingConfig;
use Vortos\Messaging\Driver\InMemory\Runtime\InMemoryConsumer;
use Vortos\Messaging\Driver\InMemory\Runtime\InMemoryProducer;
use Vortos\Messaging\Driver\Kafka\Runtime\LazyKafkaProducer;

final class VortosMessagingConfigTest extends TestCase
{
    private ?string $previousDriver;

    protected function setUp(): void
    {
        $this->previousDriver = $_ENV['VORTOS_MESSAGING_DRIVER'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->previousDriver === null) {
            unset($_ENV['VORTOS_MESSAGING_DRIVER']);
            return;
        }

        $_ENV['VORTOS_MESSAGING_DRIVER'] = $this->previousDriver;
    }

    public function test_defaults_to_in_memory_without_env_choice(): void
    {
        unset($_ENV['VORTOS_MESSAGING_DRIVER']);

        $driver = (new VortosMessagingConfig())->toArray()['driver'];

        $this->assertSame(InMemoryProducer::class, $driver['producer']);
        $this->assertSame(InMemoryConsumer::class, $driver['consumer']);
    }

    public function test_uses_kafka_when_env_selects_kafka(): void
    {
        $_ENV['VORTOS_MESSAGING_DRIVER'] = 'kafka';

        $driver = (new VortosMessagingConfig())->toArray()['driver'];

        $this->assertSame(LazyKafkaProducer::class, $driver['producer']);
        $this->assertNull($driver['consumer']);
    }
}
