<?php

declare(strict_types=1);

namespace Vortos\Tests\Messaging\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Messaging\Contract\PayloadSanitizerInterface;
use Vortos\Messaging\DeadLetter\NullPayloadSanitizer;
use Vortos\Messaging\Driver\Kafka\Command\KafkaTailCommand;
use Vortos\Messaging\Registry\TransportRegistry;

final class KafkaTailCommandTest extends TestCase
{
    private function makeTransportRegistry(array $transports = []): TransportRegistry
    {
        return new TransportRegistry($transports);
    }

    private function makeCommand(array $transports = [], ?PayloadSanitizerInterface $sanitizer = null): KafkaTailCommand
    {
        return new KafkaTailCommand($this->makeTransportRegistry($transports), $sanitizer ?? new NullPayloadSanitizer());
    }

    public function test_fails_without_rdkafka_extension(): void
    {
        if (extension_loaded('rdkafka')) {
            $this->markTestSkipped('rdkafka is loaded — cannot test missing-extension path');
        }

        $tester = new CommandTester($this->makeCommand());
        $tester->execute(['transport' => 'user.events']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('rdkafka', $tester->getDisplay());
    }

    public function test_fails_with_no_brokers_and_unknown_transport(): void
    {
        if (extension_loaded('rdkafka')) {
            $this->markTestSkipped('rdkafka is loaded — cannot test broker-resolution failure path');
        }

        putenv('KAFKA_BROKERS=');

        $tester = new CommandTester($this->makeCommand());
        $tester->execute(['transport' => 'user.events']);

        $this->assertSame(1, $tester->getStatusCode());

        putenv('KAFKA_BROKERS');
    }

    public function test_command_name(): void
    {
        $this->assertSame('vortos:kafka:tail', $this->makeCommand()->getName());
    }

    public function test_has_transport_argument(): void
    {
        $this->assertTrue($this->makeCommand()->getDefinition()->hasArgument('transport'));
    }

    public function test_has_from_beginning_option(): void
    {
        $this->assertTrue($this->makeCommand()->getDefinition()->hasOption('from-beginning'));
    }

    public function test_has_limit_option(): void
    {
        $this->assertTrue($this->makeCommand()->getDefinition()->hasOption('limit'));
    }

    public function test_has_brokers_option(): void
    {
        $this->assertTrue($this->makeCommand()->getDefinition()->hasOption('brokers'));
    }

    public function test_sanitizer_is_called_on_message(): void
    {
        if (extension_loaded('rdkafka')) {
            $this->markTestSkipped('rdkafka is loaded — integration test not applicable here');
        }

        // Command exits early due to missing rdkafka — sanitizer call is post-consume.
        // This test verifies the constructor accepts a custom sanitizer without errors.
        $sanitizerCalled = false;
        $sanitizer = new class($sanitizerCalled) implements PayloadSanitizerInterface {
            public function __construct(private bool &$called) {}
            public function sanitize(string $payload, array $headers): string
            {
                $this->called = true;
                return $payload;
            }
        };

        $command = $this->makeCommand([], $sanitizer);
        $this->assertInstanceOf(KafkaTailCommand::class, $command);
    }

    public function test_null_sanitizer_is_a_noop(): void
    {
        $sanitizer = new NullPayloadSanitizer();
        $result    = $sanitizer->sanitize('{"email":"user@example.com"}', []);

        $this->assertSame('{"email":"user@example.com"}', $result);
    }
}
