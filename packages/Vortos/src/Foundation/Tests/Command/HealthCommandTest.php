<?php

declare(strict_types=1);

namespace Vortos\Foundation\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Foundation\Command\HealthCommand;
use Vortos\Foundation\Health\HealthRegistry;
use Vortos\Foundation\Health\HealthResult;
use Vortos\Foundation\Health\Contract\HealthCheckInterface;

final class HealthCommandTest extends TestCase
{
    private function makeCheck(string $name, bool $healthy, ?string $error = null, bool $critical = true, float $latency = 1.5): HealthCheckInterface
    {
        $check = $this->createMock(HealthCheckInterface::class);
        $check->method('name')->willReturn($name);
        $check->method('check')->willReturn(new HealthResult($name, $healthy, $latency, $error));

        return $check;
    }

    private function makeRegistry(array $checks): HealthRegistry
    {
        return new HealthRegistry($checks);
    }

    public function test_shows_header(): void
    {
        $tester = new CommandTester(new HealthCommand($this->makeRegistry([])));
        $tester->execute([]);

        $this->assertStringContainsString('VORTOS HEALTH', $tester->getDisplay());
    }

    public function test_shows_no_checks_message_when_empty(): void
    {
        $tester = new CommandTester(new HealthCommand($this->makeRegistry([])));
        $tester->execute([]);

        $this->assertStringContainsString('No health checks registered.', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_returns_success_when_all_healthy(): void
    {
        $registry = $this->makeRegistry([
            $this->makeCheck('database', true),
            $this->makeCheck('redis', true),
        ]);

        $tester = new CommandTester(new HealthCommand($registry));
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('[OK]', $tester->getDisplay());
    }

    public function test_returns_failure_when_critical_check_fails(): void
    {
        $registry = $this->makeRegistry([
            $this->makeCheck('database', false, 'Connection refused', critical: true),
        ]);

        $tester = new CommandTester(new HealthCommand($registry));
        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('[FAIL]', $tester->getDisplay());
        $this->assertStringContainsString('Connection refused', $tester->getDisplay());
    }

    public function test_shows_check_names(): void
    {
        $registry = $this->makeRegistry([
            $this->makeCheck('write-db', true),
            $this->makeCheck('kafka', true),
        ]);

        $tester = new CommandTester(new HealthCommand($registry));
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('write-db', $display);
        $this->assertStringContainsString('kafka', $display);
    }

    public function test_shows_summary_counts(): void
    {
        $registry = $this->makeRegistry([
            $this->makeCheck('database', true),
            ['check' => $this->makeCheck('kafka', false, 'unreachable'), 'critical' => false],
        ]);

        $tester = new CommandTester(new HealthCommand($registry));
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('1 passed', $display);
        $this->assertStringContainsString('1 warned', $display);
    }

    public function test_json_format_outputs_valid_json(): void
    {
        $registry = $this->makeRegistry([
            $this->makeCheck('database', true),
        ]);

        $tester = new CommandTester(new HealthCommand($registry));
        $tester->execute(['--format' => 'json']);

        $json = json_decode($tester->getDisplay(), true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('status', $json);
        $this->assertArrayHasKey('checks', $json);
        $this->assertSame('ok', $json['status']);
    }

    public function test_json_format_shows_degraded_on_failure(): void
    {
        $registry = $this->makeRegistry([
            $this->makeCheck('database', false, 'down', critical: true),
        ]);

        $tester = new CommandTester(new HealthCommand($registry));
        $tester->execute(['--format' => 'json']);

        $json = json_decode($tester->getDisplay(), true);
        $this->assertSame('degraded', $json['status']);
        $this->assertSame(1, $tester->getStatusCode());
    }
}
