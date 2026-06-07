<?php

declare(strict_types=1);

namespace Vortos\Messaging\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Messaging\Command\ListTransportsCommand;
use Vortos\Messaging\Registry\ProducerRegistry;
use Vortos\Messaging\Registry\TransportRegistry;

final class ListTransportsCommandTest extends TestCase
{
    private function makeCommand(array $transports, array $producers): CommandTester
    {
        $transportRegistry = new TransportRegistry($transports);
        $producerRegistry  = new ProducerRegistry($producers);
        $command           = new ListTransportsCommand($transportRegistry, $producerRegistry);

        return new CommandTester($command);
    }

    public function test_shows_no_transports_message_when_empty(): void
    {
        $tester = $this->makeCommand([], []);
        $tester->execute([]);

        $this->assertStringContainsString('No transports registered.', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_shows_transport_count(): void
    {
        $tester = $this->makeCommand(
            [
                'user.events'   => $this->transportConfig('user-events'),
                'orders.placed' => $this->transportConfig('orders-placed'),
            ],
            [],
        );
        $tester->execute([]);

        $this->assertStringContainsString('Found 2 transport(s).', $tester->getDisplay());
    }

    public function test_shows_topic_and_serializer(): void
    {
        $tester = $this->makeCommand(
            ['user.events' => $this->transportConfig('user-events', serializer: 'json')],
            [],
        );
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('user-events', $display);
        $this->assertStringContainsString('json', $display);
    }

    public function test_dsn_masks_credentials(): void
    {
        $config        = $this->transportConfig('user-events');
        $config['dsn'] = 'kafka://user:secret@kafka:9092';

        $tester = $this->makeCommand(['user.events' => $config], []);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('kafka://kafka:9092', $display);
        $this->assertStringNotContainsString('secret', $display);
        $this->assertStringNotContainsString('user:secret', $display);
    }

    public function test_dsn_without_credentials_shown_as_is(): void
    {
        $config        = $this->transportConfig('user-events');
        $config['dsn'] = 'kafka://kafka:9092';

        $tester = $this->makeCommand(['user.events' => $config], []);
        $tester->execute([]);

        $this->assertStringContainsString('kafka://kafka:9092', $tester->getDisplay());
    }

    public function test_shows_credentials_redacted_note_when_security_configured(): void
    {
        $config             = $this->transportConfig('user-events');
        $config['security'] = ['sasl' => ['mechanism' => 'PLAIN', 'username' => 'u', 'password' => 'p']];

        $tester = $this->makeCommand(['user.events' => $config], []);
        $tester->execute([]);

        $this->assertStringContainsString('credentials redacted', $tester->getDisplay());
    }

    public function test_shows_no_credentials_note_when_no_security(): void
    {
        $tester = $this->makeCommand(['user.events' => $this->transportConfig('user-events')], []);
        $tester->execute([]);

        $this->assertStringNotContainsString('credentials redacted', $tester->getDisplay());
    }

    public function test_shows_none_when_no_producers_bound(): void
    {
        $tester = $this->makeCommand(['user.events' => $this->transportConfig('user-events')], []);
        $tester->execute([]);

        $this->assertStringContainsString('none', $tester->getDisplay());
    }

    public function test_shows_bound_producer_with_outbox_on(): void
    {
        $tester = $this->makeCommand(
            ['user.events' => $this->transportConfig('user-events')],
            ['user.events' => $this->producerConfig('user.events', outbox: true)],
        );
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('user.events', $display);
        $this->assertStringContainsString('outbox: on', $display);
    }

    public function test_shows_outbox_off_with_direct_label(): void
    {
        $tester = $this->makeCommand(
            ['user.events' => $this->transportConfig('user-events')],
            ['user.events' => $this->producerConfig('user.events', outbox: false)],
        );
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('outbox: off', $display);
        $this->assertStringContainsString('[direct]', $display);
    }

    public function test_shows_published_event_short_names(): void
    {
        $producer              = $this->producerConfig('user.events');
        $producer['publishes'] = [
            'App\\User\\Domain\\Event\\UserRegistered',
            'App\\User\\Domain\\Event\\UserDeleted',
        ];

        $tester = $this->makeCommand(
            ['user.events' => $this->transportConfig('user-events')],
            ['user.events' => $producer],
        );
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('UserRegistered', $display);
        $this->assertStringContainsString('UserDeleted', $display);
        $this->assertStringNotContainsString('App\\User\\Domain\\Event\\UserRegistered', $display);
    }

    public function test_shows_compression_when_enabled(): void
    {
        $producer                          = $this->producerConfig('user.events');
        $producer['compression']['enabled'] = true;
        $producer['compression']['type']    = 'snappy';

        $tester = $this->makeCommand(
            ['user.events' => $this->transportConfig('user-events')],
            ['user.events' => $producer],
        );
        $tester->execute([]);

        $this->assertStringContainsString('compression: snappy', $tester->getDisplay());
    }

    public function test_producer_bound_to_different_transport_not_shown(): void
    {
        $tester = $this->makeCommand(
            ['user.events' => $this->transportConfig('user-events')],
            ['orders.placed' => $this->producerConfig('orders.placed')],
        );
        $tester->execute([]);

        $this->assertStringContainsString('none', $tester->getDisplay());
        $this->assertStringNotContainsString('orders.placed', $tester->getDisplay());
    }

    private function transportConfig(string $topic, string $serializer = 'json'): array
    {
        return [
            'driver'       => 'kafka',
            'dsn'          => 'kafka://kafka:9092',
            'subscription' => ['topic' => $topic],
            'provisioning' => ['partitions' => 1, 'replication' => 1],
            'security'     => [],
            'serializer'   => $serializer,
        ];
    }

    private function producerConfig(string $transport, bool $outbox = true): array
    {
        return [
            'transport'    => $transport,
            'publishes'    => [],
            'compression'  => ['enabled' => false, 'type' => 'snappy'],
            'lingerMs'     => 5,
            'maxBatchBytes' => 1048576,
            'outbox'       => ['enabled' => $outbox],
            'headers'      => [],
        ];
    }
}
