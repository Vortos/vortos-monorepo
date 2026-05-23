<?php

declare(strict_types=1);

namespace Vortos\Tests\Foundation\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Foundation\Command\DoctorCommand;
use Vortos\Foundation\Doctor\Contract\DoctorCheckInterface;
use Vortos\Foundation\Doctor\DoctorRegistry;
use Vortos\Foundation\Doctor\DoctorResult;
use Vortos\Foundation\Doctor\DoctorStatus;

final class DoctorCommandTest extends TestCase
{
    private function makeCheck(string $name, DoctorResult $result): DoctorCheckInterface
    {
        $check = $this->createMock(DoctorCheckInterface::class);
        $check->method('name')->willReturn($name);
        $check->method('run')->willReturn($result);
        return $check;
    }

    private function makeRegistry(array $checks): DoctorRegistry
    {
        return new DoctorRegistry($checks);
    }

    public function test_shows_header(): void
    {
        $tester = new CommandTester(new DoctorCommand($this->makeRegistry([])));
        $tester->execute([]);

        $this->assertStringContainsString('VORTOS DOCTOR', $tester->getDisplay());
    }

    public function test_shows_no_checks_when_empty(): void
    {
        $tester = new CommandTester(new DoctorCommand($this->makeRegistry([])));
        $tester->execute([]);

        $this->assertStringContainsString('No doctor checks registered.', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_returns_success_when_all_ok(): void
    {
        $registry = $this->makeRegistry([
            $this->makeCheck('messaging.replay-secret', DoctorResult::ok('messaging.replay-secret', 'Secret is set')),
        ]);

        $tester = new CommandTester(new DoctorCommand($registry));
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('[OK]', $tester->getDisplay());
    }

    public function test_returns_failure_on_error(): void
    {
        $registry = $this->makeRegistry([
            $this->makeCheck('some.check', DoctorResult::error('some.check', 'Something is broken', 'Fix it')),
        ]);

        $tester = new CommandTester(new DoctorCommand($registry));
        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('[ERROR]', $tester->getDisplay());
        $this->assertStringContainsString('Something is broken', $tester->getDisplay());
    }

    public function test_warning_does_not_fail_by_default(): void
    {
        $registry = $this->makeRegistry([
            $this->makeCheck('some.check', DoctorResult::warning('some.check', 'Optional thing missing')),
        ]);

        $tester = new CommandTester(new DoctorCommand($registry));
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('[WARN]', $tester->getDisplay());
    }

    public function test_fail_on_warning_option_makes_warning_exit_nonzero(): void
    {
        $registry = $this->makeRegistry([
            $this->makeCheck('some.check', DoctorResult::warning('some.check', 'Optional thing missing')),
        ]);

        $tester = new CommandTester(new DoctorCommand($registry));
        $tester->execute(['--fail-on-warning' => true]);

        $this->assertSame(1, $tester->getStatusCode());
    }

    public function test_shows_fix_hint_for_warning(): void
    {
        $registry = $this->makeRegistry([
            $this->makeCheck('some.check', DoctorResult::warning('some.check', 'Something is missing', 'Add FOO=bar to .env')),
        ]);

        $tester = new CommandTester(new DoctorCommand($registry));
        $tester->execute([]);

        $this->assertStringContainsString('Add FOO=bar to .env', $tester->getDisplay());
    }

    public function test_shows_check_names(): void
    {
        $registry = $this->makeRegistry([
            $this->makeCheck('messaging.replay-secret', DoctorResult::ok('messaging.replay-secret', 'OK')),
            $this->makeCheck('database.connection', DoctorResult::ok('database.connection', 'OK')),
        ]);

        $tester = new CommandTester(new DoctorCommand($registry));
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('messaging.replay-secret', $display);
        $this->assertStringContainsString('database.connection', $display);
    }

    public function test_shows_summary_counts(): void
    {
        $registry = $this->makeRegistry([
            $this->makeCheck('check.a', DoctorResult::ok('check.a', 'Fine')),
            $this->makeCheck('check.b', DoctorResult::warning('check.b', 'Warn')),
            $this->makeCheck('check.c', DoctorResult::error('check.c', 'Error')),
        ]);

        $tester = new CommandTester(new DoctorCommand($registry));
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('1 passed', $display);
        $this->assertStringContainsString('1 warned', $display);
        $this->assertStringContainsString('1 failed', $display);
    }

    public function test_check_exception_is_caught_and_reported_as_error(): void
    {
        $check = $this->createMock(DoctorCheckInterface::class);
        $check->method('name')->willReturn('bad.check');
        $check->method('run')->willThrowException(new \RuntimeException('Unexpected crash'));

        $registry = new DoctorRegistry([$check]);
        $tester   = new CommandTester(new DoctorCommand($registry));
        $tester->execute([]);

        $this->assertStringContainsString('Unexpected crash', $tester->getDisplay());
        $this->assertSame(1, $tester->getStatusCode());
    }
}
