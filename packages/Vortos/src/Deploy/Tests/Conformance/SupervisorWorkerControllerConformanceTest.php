<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Conformance;

use Vortos\Deploy\Driver\Supervisor\SupervisorWorkerController;
use Vortos\Deploy\Execution\CommandResult;
use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\Testing\WorkerControllerConformanceTestCase;
use Vortos\Deploy\Tests\Fixtures\FakeCommandRunner;
use Vortos\Deploy\Tests\Fixtures\FakeSshTransport;
use Vortos\Deploy\Worker\DrainBudget;
use Vortos\Deploy\Worker\WorkerControllerCapability;
use Vortos\Deploy\Worker\WorkerControllerInterface;
use Vortos\Deploy\Worker\WorkerHandle;
use Vortos\Deploy\Worker\WorkerRuntimeStatus;

final class SupervisorWorkerControllerConformanceTest extends WorkerControllerConformanceTestCase
{
    private FakeCommandRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new FakeCommandRunner();
        // Pre-seed results: drain (stop) succeeds, launch (start) succeeds, status returns RUNNING
        $this->runner->addResult(new CommandResult(0, '', '', 0.1));
        $this->runner->addResult(new CommandResult(0, '', '', 0.1));
        $this->runner->addResult(new CommandResult(0, 'test-worker                      RUNNING   pid 1234, uptime 0:00:01', '', 0.1));
    }

    protected function createController(): WorkerControllerInterface
    {
        return new SupervisorWorkerController($this->runner);
    }

    protected function expectedKey(): string
    {
        return 'supervisor';
    }

    public function test_drain_sends_supervisorctl_stop(): void
    {
        $runner = new FakeCommandRunner();
        $runner->addResult(new CommandResult(0, '', '', 0.1));

        $controller = new SupervisorWorkerController($runner);
        $handle = new WorkerHandle('my-worker', 1, 25);
        $budget = new DrainBudget(25);

        $outcome = $controller->drain($handle, $budget);

        $this->assertTrue($outcome->inFlightCompleted);
        $this->assertSame(['supervisorctl', 'stop', 'my-worker'], $runner->calls[0]['argv']);
    }

    public function test_launch_sends_supervisorctl_start(): void
    {
        $runner = new FakeCommandRunner();
        $runner->addResult(new CommandResult(0, '', '', 0.1));

        $controller = new SupervisorWorkerController($runner);
        $handle = new WorkerHandle('my-worker', 1, 25);
        $image = new ImageReference('app', digest: 'sha256:' . str_repeat('ab', 32));

        $controller->launch($handle, $image);

        $this->assertSame(['supervisorctl', 'start', 'my-worker'], $runner->calls[0]['argv']);
    }

    public function test_status_parses_running(): void
    {
        $runner = new FakeCommandRunner();
        $runner->addResult(new CommandResult(0, 'my-worker                        RUNNING   pid 1234, uptime 0:05:01', '', 0.05));

        $controller = new SupervisorWorkerController($runner);
        $handle = new WorkerHandle('my-worker', 1, 25);

        $status = $controller->status($handle);
        $this->assertSame(WorkerRuntimeStatus::Running, $status);
    }

    public function test_status_parses_stopped(): void
    {
        $runner = new FakeCommandRunner();
        $runner->addResult(new CommandResult(0, 'my-worker                        STOPPED', '', 0.05));

        $controller = new SupervisorWorkerController($runner);
        $handle = new WorkerHandle('my-worker', 1, 25);

        $status = $controller->status($handle);
        $this->assertSame(WorkerRuntimeStatus::Stopped, $status);
    }

    public function test_status_parses_fatal(): void
    {
        $runner = new FakeCommandRunner();
        $runner->addResult(new CommandResult(0, 'my-worker                        FATAL     Exited too quickly (0)', '', 0.05));

        $controller = new SupervisorWorkerController($runner);
        $handle = new WorkerHandle('my-worker', 1, 25);

        $status = $controller->status($handle);
        $this->assertSame(WorkerRuntimeStatus::Fatal, $status);
    }

    public function test_drain_failure_returns_forced_outcome(): void
    {
        $runner = new FakeCommandRunner();
        $runner->addResult(new CommandResult(1, '', 'error: process not found', 25.0));

        $controller = new SupervisorWorkerController($runner);
        $handle = new WorkerHandle('my-worker', 1, 25);
        $budget = new DrainBudget(25);

        $outcome = $controller->drain($handle, $budget);
        $this->assertTrue($outcome->forced);
    }

    public function test_ssh_transport_takes_precedence_over_local(): void
    {
        $runner = new FakeCommandRunner();
        $ssh = new FakeSshTransport();
        $ssh->addResult(new CommandResult(0, '', '', 0.1));

        $controller = new SupervisorWorkerController($runner, $ssh);
        $handle = new WorkerHandle('my-worker', 1, 25);
        $budget = new DrainBudget(25);

        $controller->drain($handle, $budget);

        $this->assertNotEmpty($ssh->commands, 'SSH transport should be used when present');
        $this->assertEmpty($runner->calls, 'Local runner should not be used when SSH is available');
    }

    public function test_declares_remote_control_when_ssh_present(): void
    {
        $runner = new FakeCommandRunner();
        $ssh = new FakeSshTransport();
        $controller = new SupervisorWorkerController($runner, $ssh);

        $this->assertTrue($controller->capabilities()->supports(WorkerControllerCapability::RemoteControl));
    }

    public function test_no_remote_control_without_ssh(): void
    {
        $runner = new FakeCommandRunner();
        $controller = new SupervisorWorkerController($runner);

        $this->assertHonestlyUnsupported($controller->capabilities(), WorkerControllerCapability::RemoteControl);
    }
}
