<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Iac\Command\IacPlanCommand;
use Vortos\Iac\Lifecycle\Audit\NullIacAuditSink;
use Vortos\Iac\Lifecycle\IacApplyResult;
use Vortos\Iac\Lifecycle\IacChangeAction;
use Vortos\Iac\Lifecycle\IacDestroyResult;
use Vortos\Iac\Lifecycle\IacEngineInterface;
use Vortos\Iac\Lifecycle\IacExecutionContext;
use Vortos\Iac\Lifecycle\IacLifecycleService;
use Vortos\Iac\Lifecycle\IacPlan;
use Vortos\Iac\Lifecycle\IacPlanSummary;
use Vortos\Iac\Lifecycle\IacResourceChange;
use Vortos\Iac\Lifecycle\IacWorkspace;
use Vortos\Iac\Lifecycle\Policy\NullPlanPolicy;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

final class IacPlanCommandTest extends TestCase
{
    public function test_plan_shows_diff(): void
    {
        $engine = new class implements IacEngineInterface {
            public function init(IacWorkspace $ws, IacExecutionContext $ctx): void {}
            public function plan(IacWorkspace $ws, IacExecutionContext $ctx): IacPlan
            {
                return new IacPlan(
                    new IacPlanSummary(2, 0, 0, 0),
                    [
                        new IacResourceChange('r.a', 't', IacChangeAction::Create, 'p'),
                        new IacResourceChange('r.b', 't', IacChangeAction::Create, 'p'),
                    ],
                    '/tmp/p.bin',
                    'digest',
                    'file-digest',
                );
            }
            public function apply(IacWorkspace $ws, IacPlan $plan, IacExecutionContext $ctx): IacApplyResult { return new IacApplyResult(0, 0, 0, ''); }
            public function destroy(IacWorkspace $ws, IacExecutionContext $ctx): IacDestroyResult { return new IacDestroyResult(0, 0, 0); }
            public function show(IacWorkspace $ws, IacExecutionContext $ctx): IacPlan { return $this->plan($ws, $ctx); }
            public function capabilities(): CapabilityDescriptor { return CapabilityDescriptor::create([]); }
        };

        $lifecycle = new IacLifecycleService($engine, new NullPlanPolicy(), new NullIacAuditSink());
        $cmd = new IacPlanCommand($lifecycle, sys_get_temp_dir());
        $tester = new CommandTester($cmd);

        $tester->execute(['--env' => 'dev']);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('2 to add', $tester->getDisplay());
    }

    public function test_detailed_exitcode_returns_1_on_changes(): void
    {
        $engine = new class implements IacEngineInterface {
            public function init(IacWorkspace $ws, IacExecutionContext $ctx): void {}
            public function plan(IacWorkspace $ws, IacExecutionContext $ctx): IacPlan
            {
                return new IacPlan(new IacPlanSummary(1, 0, 0, 0), [new IacResourceChange('r.a', 't', IacChangeAction::Create, 'p')], '/tmp/p.bin', 'digest', 'file-digest');
            }
            public function apply(IacWorkspace $ws, IacPlan $plan, IacExecutionContext $ctx): IacApplyResult { return new IacApplyResult(0, 0, 0, ''); }
            public function destroy(IacWorkspace $ws, IacExecutionContext $ctx): IacDestroyResult { return new IacDestroyResult(0, 0, 0); }
            public function show(IacWorkspace $ws, IacExecutionContext $ctx): IacPlan { return $this->plan($ws, $ctx); }
            public function capabilities(): CapabilityDescriptor { return CapabilityDescriptor::create([]); }
        };

        $lifecycle = new IacLifecycleService($engine, new NullPlanPolicy(), new NullIacAuditSink());
        $cmd = new IacPlanCommand($lifecycle, sys_get_temp_dir());
        $tester = new CommandTester($cmd);

        $tester->execute(['--env' => 'dev', '--detailed-exitcode' => true]);
        $this->assertSame(1, $tester->getStatusCode());
    }

    public function test_json_output(): void
    {
        $engine = new class implements IacEngineInterface {
            public function init(IacWorkspace $ws, IacExecutionContext $ctx): void {}
            public function plan(IacWorkspace $ws, IacExecutionContext $ctx): IacPlan
            {
                return new IacPlan(new IacPlanSummary(0, 0, 0, 0), [], '/tmp/p.bin', 'digest', 'file-digest');
            }
            public function apply(IacWorkspace $ws, IacPlan $plan, IacExecutionContext $ctx): IacApplyResult { return new IacApplyResult(0, 0, 0, ''); }
            public function destroy(IacWorkspace $ws, IacExecutionContext $ctx): IacDestroyResult { return new IacDestroyResult(0, 0, 0); }
            public function show(IacWorkspace $ws, IacExecutionContext $ctx): IacPlan { return $this->plan($ws, $ctx); }
            public function capabilities(): CapabilityDescriptor { return CapabilityDescriptor::create([]); }
        };

        $lifecycle = new IacLifecycleService($engine, new NullPlanPolicy(), new NullIacAuditSink());
        $cmd = new IacPlanCommand($lifecycle, sys_get_temp_dir());
        $tester = new CommandTester($cmd);

        $tester->execute(['--env' => 'dev', '--json' => true]);
        $decoded = json_decode($tester->getDisplay(), true);
        $this->assertFalse($decoded['has_changes']);
    }
}
