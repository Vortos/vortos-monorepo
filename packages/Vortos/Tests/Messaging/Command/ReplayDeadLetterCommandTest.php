<?php

declare(strict_types=1);

namespace Vortos\Tests\Messaging\Command;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Messaging\Command\ReplayDeadLetterCommand;
use Vortos\Messaging\Contract\ProducerInterface;
use Vortos\Messaging\DeadLetter\DeadLetterRepositoryInterface;
use Vortos\Messaging\Registry\HandlerRegistry;
use Vortos\Messaging\Registry\TransportRegistry;
use Vortos\Messaging\Serializer\SerializerLocator;

final class ReplayDeadLetterCommandTest extends TestCase
{
    private DeadLetterRepositoryInterface&MockObject $repository;
    private ProducerInterface&MockObject $producer;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(DeadLetterRepositoryInterface::class);
        $this->producer   = $this->createMock(ProducerInterface::class);
    }

    private function tester(): CommandTester
    {
        $command = new ReplayDeadLetterCommand(
            repository: $this->repository,
            producer: $this->producer,
            serializerLocator: new SerializerLocator([]),
            handlerRegistry: new HandlerRegistry(),
            transportRegistry: new TransportRegistry([]),
            logger: new NullLogger(),
        );
        $app = new Application();
        $app->add($command);
        return new CommandTester($command);
    }

    private function row(string $id = 'row-1'): array
    {
        return [
            'id'             => $id,
            'event_class'    => 'App\\Order\\Domain\\Event\\OrderPlaced',
            'transport_name' => 'orders',
            'handler_id'     => 'order.placed.notify',
            'payload'        => '{"orderId":"123"}',
            'headers'        => '{"event_id":"evt-1"}',
            'failure_reason' => 'handler threw',
            'failed_at'      => '2026-05-01 10:00:00',
        ];
    }

    public function test_no_failed_messages_returns_success(): void
    {
        $this->repository->method('fetchFailed')->willReturn([]);

        $tester = $this->tester();
        $tester->execute(['--force' => true]);

        $this->assertStringContainsString('No failed messages found.', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_dry_run_lists_messages_without_replaying(): void
    {
        $this->repository->method('fetchFailed')->willReturn([$this->row()]);
        $this->repository->expects($this->never())->method('markReplayed');
        $this->producer->expects($this->never())->method('produce');

        $tester = $this->tester();
        $tester->execute(['--dry-run' => true]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Dry run', $display);
        $this->assertStringContainsString('row-1', $display);
        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_aborts_when_confirmation_declined(): void
    {
        $this->repository->method('fetchFailed')->willReturn([$this->row()]);
        $this->repository->expects($this->never())->method('markReplayed');
        $this->producer->expects($this->never())->method('produce');

        $tester = $this->tester();
        $tester->setInputs(['no']);
        $tester->execute([]);

        $this->assertStringContainsString('Aborted', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_force_skips_prompt(): void
    {
        $this->repository->method('fetchFailed')->willReturn([]);

        $tester = $this->tester();
        $tester->execute(['--force' => true]);

        $this->assertSame(0, $tester->getStatusCode());
    }
}
