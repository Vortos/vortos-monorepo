<?php

declare(strict_types=1);

namespace Vortos\Messaging\Tests\Command;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Messaging\Command\ConsumeCommand;
use Vortos\Messaging\Runtime\ConsumerRunnerInterface;

final class ConsumeCommandTest extends TestCase
{
    private ConsumerRunnerInterface&MockObject $runner;

    protected function setUp(): void
    {
        $this->runner = $this->createMock(ConsumerRunnerInterface::class);
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new ConsumeCommand($this->runner, new NullLogger()));
    }

    public function test_success_on_clean_run(): void
    {
        $this->runner->expects($this->once())->method('run')->with('orders.placed', 0);

        $tester = $this->tester();
        $tester->execute(['consumer' => 'orders.placed']);

        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_error_output_includes_consumer_name(): void
    {
        $this->runner->method('run')->willThrowException(new \RuntimeException('broker unavailable'));

        $tester = $this->tester();
        $tester->execute(['consumer' => 'orders.placed']);

        $display = $tester->getDisplay();
        $this->assertStringContainsString("Consumer 'orders.placed' failed", $display);
        $this->assertStringContainsString('broker unavailable', $display);
        $this->assertSame(1, $tester->getStatusCode());
    }

    public function test_passes_max_messages_to_runner(): void
    {
        $this->runner->expects($this->once())->method('run')->with('orders.placed', 100);

        $tester = $this->tester();
        $tester->execute(['consumer' => 'orders.placed', '--max-messages' => '100']);

        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_has_tail_option(): void
    {
        $this->assertTrue((new ConsumeCommand($this->runner, new NullLogger()))->getDefinition()->hasOption('tail'));
    }
}
