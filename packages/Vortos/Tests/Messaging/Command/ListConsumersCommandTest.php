<?php

declare(strict_types=1);

namespace Vortos\Tests\Messaging\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Messaging\Command\ListConsumersCommand;
use Vortos\Messaging\Registry\ConsumerRegistry;
use Vortos\Messaging\Registry\HandlerRegistry;

final class ListConsumersCommandTest extends TestCase
{
    private function makeCommand(array $consumers, array $handlers): ListConsumersCommandTest|CommandTester
    {
        $consumerRegistry = new ConsumerRegistry($consumers);
        $handlerRegistry  = new HandlerRegistry($handlers);
        $command          = new ListConsumersCommand($handlerRegistry, $consumerRegistry);

        return new CommandTester($command);
    }

    public function test_shows_no_consumers_message_when_empty(): void
    {
        $tester = $this->makeCommand([], []);
        $tester->execute([]);

        $this->assertStringContainsString('No consumers registered.', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_shows_consumer_count(): void
    {
        $tester = $this->makeCommand(
            ['user.events' => $this->kafkaConfig(), 'orders' => $this->kafkaConfig('orders')],
            [],
        );
        $tester->execute([]);

        $this->assertStringContainsString('Found 2 consumer(s).', $tester->getDisplay());
    }

    public function test_shows_kafka_badge_for_kafka_consumer(): void
    {
        $tester = $this->makeCommand(['user.events' => $this->kafkaConfig()], []);
        $tester->execute([]);

        $this->assertStringContainsString('[kafka]', $tester->getDisplay());
    }

    public function test_shows_in_process_badge_for_in_process_consumer(): void
    {
        $config = $this->kafkaConfig();
        $config['inProcess'] = true;
        $config['groupId']   = '';

        $tester = $this->makeCommand(['internal' => $config], []);
        $tester->execute([]);

        $this->assertStringContainsString('[in-process]', $tester->getDisplay());
    }

    public function test_shows_config_line_with_parallelism_batch_and_ttl(): void
    {
        $config = $this->kafkaConfig();
        $config['parallelism']    = 4;
        $config['batchSize']      = 50;
        $config['idempotencyTtl'] = 3600;

        $tester = $this->makeCommand(['user.events' => $config], []);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('parallelism: 4', $display);
        $this->assertStringContainsString('batch: 50', $display);
        $this->assertStringContainsString('ttl: 3600s', $display);
    }

    public function test_shows_dash_for_missing_ttl(): void
    {
        $config = $this->kafkaConfig();
        $config['idempotencyTtl'] = null;

        $tester = $this->makeCommand(['user.events' => $config], []);
        $tester->execute([]);

        $this->assertStringContainsString('ttl: —', $tester->getDisplay());
    }

    public function test_shows_retry_policy(): void
    {
        $config = $this->kafkaConfig();
        $config['retry'] = [
            'backoff'      => 'exponential',
            'maxAttempts'  => 3,
            'initialDelay' => 500,
            'maxDelay'     => 30000,
        ];

        $tester = $this->makeCommand(['user.events' => $config], []);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('exponential', $display);
        $this->assertStringContainsString('3 attempts', $display);
        $this->assertStringContainsString('500ms initial', $display);
        $this->assertStringContainsString('30000ms cap', $display);
    }

    public function test_shows_dlq_when_configured(): void
    {
        $config        = $this->kafkaConfig();
        $config['dlq'] = 'user.events.dlq';

        $tester = $this->makeCommand(['user.events' => $config], []);
        $tester->execute([]);

        $this->assertStringContainsString('user.events.dlq', $tester->getDisplay());
    }

    public function test_shows_no_handlers_message_when_none_registered(): void
    {
        $tester = $this->makeCommand(['user.events' => $this->kafkaConfig()], []);
        $tester->execute([]);

        $this->assertStringContainsString('No handlers registered.', $tester->getDisplay());
    }

    public function test_shows_event_short_name_and_fqcn(): void
    {
        $handlers = [
            'user.events' => [
                'App\\User\\Domain\\Event\\UserRegistered' => [$this->handlerDescriptor('user.registered.email')],
            ],
        ];

        $tester = $this->makeCommand(['user.events' => $this->kafkaConfig()], $handlers);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('UserRegistered', $display);
        $this->assertStringContainsString('App\\User\\Domain\\Event\\UserRegistered', $display);
    }

    public function test_shows_handler_id_and_priority(): void
    {
        $handlers = [
            'user.events' => [
                'App\\User\\Domain\\Event\\UserRegistered' => [
                    $this->handlerDescriptor('user.registered.email', priority: 10),
                ],
            ],
        ];

        $tester = $this->makeCommand(['user.events' => $this->kafkaConfig()], $handlers);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('user.registered.email', $display);
        $this->assertStringContainsString('10', $display);
    }

    public function test_idempotent_false_shows_dedup_on(): void
    {
        $handlers = [
            'user.events' => [
                'App\\User\\Domain\\Event\\UserRegistered' => [
                    $this->handlerDescriptor('user.registered.email', idempotent: false),
                ],
            ],
        ];

        $tester = $this->makeCommand(['user.events' => $this->kafkaConfig()], $handlers);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('idempotent: no', $display);
        $this->assertStringContainsString('Redis dedup active', $display);
    }

    public function test_idempotent_true_shows_always_runs(): void
    {
        $handlers = [
            'user.events' => [
                'App\\User\\Domain\\Event\\UserRegistered' => [
                    $this->handlerDescriptor('user.registered.upsert', idempotent: true),
                ],
            ],
        ];

        $tester = $this->makeCommand(['user.events' => $this->kafkaConfig()], $handlers);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('idempotent: yes', $display);
        $this->assertStringContainsString('idempotent: yes', $display);
    }

    public function test_multiple_handlers_for_same_event_all_shown(): void
    {
        $handlers = [
            'user.events' => [
                'App\\User\\Domain\\Event\\UserRegistered' => [
                    $this->handlerDescriptor('user.registered.email', priority: 10),
                    $this->handlerDescriptor('user.registered.audit', priority: 0),
                ],
            ],
        ];

        $tester = $this->makeCommand(['user.events' => $this->kafkaConfig()], $handlers);
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('user.registered.email', $display);
        $this->assertStringContainsString('user.registered.audit', $display);
        $this->assertStringContainsString('Handlers (2):', $display);
    }

    private function kafkaConfig(string $transport = 'user.events'): array
    {
        return [
            'transport'      => $transport,
            'groupId'        => 'user-service',
            'parallelism'    => 1,
            'batchSize'      => 1,
            'retry'          => [],
            'dlq'            => '',
            'idempotencyTtl' => null,
            'inProcess'      => false,
        ];
    }

    private function handlerDescriptor(
        string $handlerId,
        int $priority = 0,
        bool $idempotent = false,
    ): array {
        return [
            'handlerId'  => $handlerId,
            'serviceId'  => 'App\\Handler\\' . $handlerId,
            'method'     => '__invoke',
            'priority'   => $priority,
            'idempotent' => $idempotent,
            'version'    => null,
        ];
    }
}
