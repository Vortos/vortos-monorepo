<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Iac\Command\IacDestroyCommand;
use Vortos\Iac\Lifecycle\Audit\NullIacAuditSink;
use Vortos\Iac\Lifecycle\IacApplyResult;
use Vortos\Iac\Lifecycle\IacDestroyResult;
use Vortos\Iac\Lifecycle\IacEngineInterface;
use Vortos\Iac\Lifecycle\IacExecutionContext;
use Vortos\Iac\Lifecycle\IacLifecycleService;
use Vortos\Iac\Lifecycle\IacPlan;
use Vortos\Iac\Lifecycle\IacPlanSummary;
use Vortos\Iac\Lifecycle\IacWorkspace;
use Vortos\Iac\Lifecycle\Policy\NullPlanPolicy;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

final class IacDestroyCommandTest extends TestCase
{
    private function makeEngine(): IacEngineInterface
    {
        return new class implements IacEngineInterface {
            public function init(IacWorkspace $ws, IacExecutionContext $ctx): void {}
            public function plan(IacWorkspace $ws, IacExecutionContext $ctx): IacPlan
            {
                return new IacPlan(new IacPlanSummary(0, 0, 0, 0), [], '/tmp/p.bin', 'digest', 'file-digest');
            }
            public function apply(IacWorkspace $ws, IacPlan $plan, IacExecutionContext $ctx): IacApplyResult
            {
                return new IacApplyResult(0, 0, 0, '');
            }
            public function destroy(IacWorkspace $ws, IacExecutionContext $ctx): IacDestroyResult
            {
                return new IacDestroyResult(0, 0, 50);
            }
            public function show(IacWorkspace $ws, IacExecutionContext $ctx): IacPlan
            {
                return new IacPlan(new IacPlanSummary(0, 0, 0, 0), [], '/tmp/p.bin', 'digest', 'file-digest');
            }
            public function capabilities(): CapabilityDescriptor { return CapabilityDescriptor::create([]); }
        };
    }

    public function test_destroy_refuses_without_confirm(): void
    {
        $lifecycle = new IacLifecycleService($this->makeEngine(), new NullPlanPolicy(), new NullIacAuditSink());
        $cmd = new IacDestroyCommand($lifecycle, sys_get_temp_dir());
        $tester = new CommandTester($cmd);

        $tester->execute(['--env' => 'dev', '--confirm' => 'wrong']);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Confirmation mismatch', $tester->getDisplay());
    }

    public function test_destroy_refuses_prod_without_force(): void
    {
        $lifecycle = new IacLifecycleService($this->makeEngine(), new NullPlanPolicy(), new NullIacAuditSink());
        $cmd = new IacDestroyCommand($lifecycle, sys_get_temp_dir());
        $tester = new CommandTester($cmd);

        $tester->execute(['--env' => 'prod', '--confirm' => 'prod']);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Refusing to destroy production', $tester->getDisplay());
    }

    public function test_destroy_succeeds_with_confirm(): void
    {
        $lifecycle = new IacLifecycleService($this->makeEngine(), new NullPlanPolicy(), new NullIacAuditSink());
        $cmd = new IacDestroyCommand($lifecycle, sys_get_temp_dir());
        $tester = new CommandTester($cmd);

        $tester->execute(['--env' => 'dev', '--confirm' => 'dev']);
        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_destroy_prod_with_force_and_confirm(): void
    {
        $lifecycle = new IacLifecycleService($this->makeEngine(), new NullPlanPolicy(), new NullIacAuditSink());
        $cmd = new IacDestroyCommand($lifecycle, sys_get_temp_dir());
        $tester = new CommandTester($cmd);

        $tester->execute(['--env' => 'prod', '--confirm' => 'prod', '--force' => true]);
        $this->assertSame(0, $tester->getStatusCode());
    }
}
