<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Observability\Command\EmitHeartbeatCommand;
use Vortos\Observability\Heartbeat\HeartbeatEmitterInterface;
use Vortos\Observability\Heartbeat\HeartbeatPing;

/**
 * The dead-man contract from the emit side: a non-acknowledged check-in (the failure
 * that, sustained, becomes the *absence* an external monitor pages on) exits non-zero
 * so the scheduler records the miss; an acknowledged one exits zero. Absence detection
 * itself is off-host by design.
 */
final class HeartbeatCommandTest extends TestCase
{
    public function test_acknowledged_heartbeat_succeeds(): void
    {
        $tester = $this->tester(new FakeEmitter(ack: true));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
    }

    public function test_unacknowledged_heartbeat_fails_so_absence_is_recorded(): void
    {
        $tester = $this->tester(new FakeEmitter(ack: false));
        $tester->execute([]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('not acknowledged', $tester->getDisplay());
    }

    public function test_invalid_status_fails(): void
    {
        $tester = $this->tester(new FakeEmitter(ack: true));
        $tester->execute(['--status' => 'bogus']);

        self::assertSame(1, $tester->getStatusCode());
    }

    public function test_passes_status_and_monitor_to_emitter(): void
    {
        $emitter = new FakeEmitter(ack: true);
        $tester = $this->tester($emitter);
        $tester->execute(['--status' => 'fail', '--monitor' => 'worker']);

        self::assertNotNull($emitter->last);
        self::assertSame('worker', $emitter->last->monitorKey);
        self::assertSame('fail', $emitter->last->status->value);
    }

    private function tester(HeartbeatEmitterInterface $emitter): CommandTester
    {
        return new CommandTester(new EmitHeartbeatCommand($emitter, 'vortos-app'));
    }
}

final class FakeEmitter implements HeartbeatEmitterInterface
{
    public ?HeartbeatPing $last = null;

    public function __construct(private readonly bool $ack) {}

    public function emit(HeartbeatPing $ping): bool
    {
        $this->last = $ping;

        return $this->ack;
    }
}
