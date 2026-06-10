<?php

declare(strict_types=1);

namespace Vortos\Messaging\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Messaging\Attribute\MessagingConfig;
use Vortos\Messaging\Attribute\RegisterConsumer;
use Vortos\Messaging\Attribute\RegisterProducer;
use Vortos\Messaging\Attribute\RegisterTransport;
use Vortos\Messaging\DependencyInjection\Compiler\MessagingConfigCompilerPass;
use Vortos\Messaging\Definition\Consumer\AbstractConsumerDefinition;
use Vortos\Messaging\Definition\Producer\AbstractProducerDefinition;
use Vortos\Messaging\Definition\Transport\AbstractTransportDefinition;
use Vortos\Messaging\Driver\Kafka\Definition\KafkaConsumerDefinition;
use Vortos\Messaging\Driver\Kafka\Definition\KafkaProducerDefinition;
use Vortos\Messaging\Driver\Kafka\Definition\KafkaTransportDefinition;

// Producer-side event (the "owning" module's class)
final readonly class WireMapEntryApproved
{
    public function __construct(public string $entryId) {}
}

// A consuming module's OWN contract class for the same wire event
final readonly class WireMapLocalContract
{
    public function __construct(public string $entryId) {}
}

final readonly class WireMapLegacyEvent
{
    public function __construct(public string $id) {}
}

#[MessagingConfig]
final class WireMapMessagingConfigFixture
{
    #[RegisterTransport]
    public function transport(): AbstractTransportDefinition
    {
        return KafkaTransportDefinition::create('wiremap.t')->dsn('kafka://x:9092')->topic('wiremap.t');
    }

    #[RegisterProducer]
    public function producer(): AbstractProducerDefinition
    {
        return KafkaProducerDefinition::create('wiremap.t')
            ->publishes(WireMapEntryApproved::class)
            ->publish(WireMapLegacyEvent::class, as: 'registration.entry_flagged', version: 2);
    }

    #[RegisterConsumer]
    public function consumer(): AbstractConsumerDefinition
    {
        return KafkaConsumerDefinition::create('wiremap.t')
            ->handles('messaging.wire_map_entry_approved', WireMapLocalContract::class);
    }
}

final class MessagingConfigWireMapTest extends TestCase
{
    private function compile(string $configClass): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register($configClass, $configClass)->addTag('vortos.messaging_config');
        (new MessagingConfigCompilerPass())->process($container);
        return $container;
    }

    public function test_publishes_derives_conventional_wire_name(): void
    {
        $map = $this->compile(WireMapMessagingConfigFixture::class)->getParameter('vortos.event_wire_map');

        $this->assertSame(
            ['name' => 'messaging.wire_map_entry_approved', 'version' => 1],
            $map[WireMapEntryApproved::class],
        );
    }

    public function test_publish_with_explicit_name_and_version(): void
    {
        $map = $this->compile(WireMapMessagingConfigFixture::class)->getParameter('vortos.event_wire_map');

        $this->assertSame(
            ['name' => 'registration.entry_flagged', 'version' => 2],
            $map[WireMapLegacyEvent::class],
        );
    }

    public function test_consumer_handles_overrides_producer_default_class(): void
    {
        $map = $this->compile(WireMapMessagingConfigFixture::class)->getParameter('vortos.wire_event_map');

        // The consumer declared its OWN local class for the producer's event —
        // the explicit handles() wins over the producer-derived default.
        $this->assertSame(WireMapLocalContract::class, $map['messaging.wire_map_entry_approved']);
        // The explicitly named event keeps the producer class as default contract.
        $this->assertSame(WireMapLegacyEvent::class, $map['registration.entry_flagged']);
    }

    public function test_invalid_explicit_wire_name_fails_build(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('invalid wire name');
        $this->compile(WireMapInvalidNameFixture::class);
    }

    public function test_duplicate_wire_name_fails_build(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('globally unique');
        $this->compile(WireMapDuplicateNameFixture::class);
    }
}

#[MessagingConfig]
final class WireMapInvalidNameFixture
{
    #[RegisterTransport]
    public function transport(): AbstractTransportDefinition
    {
        return KafkaTransportDefinition::create('bad.t')->dsn('kafka://x:9092')->topic('bad.t');
    }

    #[RegisterProducer]
    public function producer(): AbstractProducerDefinition
    {
        return KafkaProducerDefinition::create('bad.t')
            ->publish(WireMapEntryApproved::class, as: 'NotValid');
    }
}

#[MessagingConfig]
final class WireMapDuplicateNameFixture
{
    #[RegisterTransport]
    public function transport(): AbstractTransportDefinition
    {
        return KafkaTransportDefinition::create('dup.t')->dsn('kafka://x:9092')->topic('dup.t');
    }

    #[RegisterProducer]
    public function producer(): AbstractProducerDefinition
    {
        return KafkaProducerDefinition::create('dup.t')
            ->publish(WireMapEntryApproved::class, as: 'shared.name')
            ->publish(WireMapLegacyEvent::class, as: 'shared.name');
    }
}
