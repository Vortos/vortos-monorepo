<?php

declare(strict_types=1);

namespace Vortos\Metrics\Tests;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Metrics\Command\CollectMetricsCommand;
use Vortos\Metrics\Contract\FlushableMetricsInterface;
use Vortos\Metrics\Contract\MetricsCollectorInterface;
use Vortos\Metrics\Contract\MetricsInterface;

final class CollectMetricsCommandTest extends TestCase
{
    public function test_shows_each_collector_class_name(): void
    {
        $collector = $this->createMock(MetricsCollectorInterface::class);
        $metrics   = $this->createMock(MetricsInterface::class);

        $command = new CollectMetricsCommand([$collector], $metrics);
        $tester  = new CommandTester($command);
        $tester->execute([]);

        $shortName = (new \ReflectionClass($collector))->getShortName();
        $this->assertStringContainsString($shortName, $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_success_message_includes_collector_count(): void
    {
        $c1 = $this->createMock(MetricsCollectorInterface::class);
        $c2 = $this->createMock(MetricsCollectorInterface::class);
        $metrics = $this->createMock(MetricsInterface::class);

        $command = new CollectMetricsCommand([$c1, $c2], $metrics);
        $tester  = new CommandTester($command);
        $tester->execute([]);

        $this->assertStringContainsString('Collected 2 metrics collector(s).', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_flushes_when_metrics_is_flushable(): void
    {
        $metrics = $this->createMock(FlushableMetricsAndMetricsInterface::class);
        $metrics->expects($this->once())->method('flush');

        $command = new CollectMetricsCommand([], $metrics);
        $tester  = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_collects_zero_when_no_collectors(): void
    {
        $metrics = $this->createMock(MetricsInterface::class);
        $command = new CollectMetricsCommand([], $metrics);
        $tester  = new CommandTester($command);
        $tester->execute([]);

        $this->assertStringContainsString('Collected 0 metrics collector(s).', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }
}

interface FlushableMetricsAndMetricsInterface extends MetricsInterface, FlushableMetricsInterface {}
