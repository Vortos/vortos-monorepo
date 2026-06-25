<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Lifecycle;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Lifecycle\Audit\NullIacAuditSink;
use Vortos\Iac\Lifecycle\IacApplyResult;
use Vortos\Iac\Lifecycle\IacChangeAction;
use Vortos\Iac\Lifecycle\IacDestroyResult;
use Vortos\Iac\Lifecycle\IacDriftAuditor;
use Vortos\Iac\Lifecycle\IacEngineInterface;
use Vortos\Iac\Lifecycle\IacExecutionContext;
use Vortos\Iac\Lifecycle\IacLifecycleService;
use Vortos\Iac\Lifecycle\IacPlan;
use Vortos\Iac\Lifecycle\IacPlanSummary;
use Vortos\Iac\Lifecycle\IacResourceChange;
use Vortos\Iac\Lifecycle\IacWorkspace;
use Vortos\Iac\Lifecycle\Policy\NullPlanPolicy;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

final class IacDriftAuditorTest extends TestCase
{
    public function test_no_drift_returns_clean(): void
    {
        $engine = $this->engineWithPlan(new IacPlan(
            new IacPlanSummary(0, 0, 0, 0),
            [],
            '/tmp/p.bin',
            'digest',
            'file-digest',
        ));

        $lifecycle = new IacLifecycleService($engine, new NullPlanPolicy(), new NullIacAuditSink());
        $auditor = new IacDriftAuditor($lifecycle, sys_get_temp_dir());

        $report = $auditor->audit('dev');
        $this->assertFalse($report->hasDrift);
        $this->assertFalse($report->unreachable);
    }

    public function test_drift_returns_drifted(): void
    {
        $engine = $this->engineWithPlan(new IacPlan(
            new IacPlanSummary(1, 0, 0, 0),
            [new IacResourceChange('r.a', 't', IacChangeAction::Create, 'p')],
            '/tmp/p.bin',
            'digest',
            'file-digest',
        ));

        $lifecycle = new IacLifecycleService($engine, new NullPlanPolicy(), new NullIacAuditSink());
        $auditor = new IacDriftAuditor($lifecycle, sys_get_temp_dir());

        $report = $auditor->audit('dev');
        $this->assertTrue($report->hasDrift);
        $this->assertFalse($report->unreachable);
    }

    public function test_engine_throws_returns_unreachable(): void
    {
        $engine = new class implements IacEngineInterface {
            public function init(IacWorkspace $ws, IacExecutionContext $ctx): void {}
            public function plan(IacWorkspace $ws, IacExecutionContext $ctx): IacPlan { throw new \RuntimeException('boom'); }
            public function apply(IacWorkspace $ws, IacPlan $plan, IacExecutionContext $ctx): IacApplyResult { throw new \RuntimeException('boom'); }
            public function destroy(IacWorkspace $ws, IacExecutionContext $ctx): IacDestroyResult { throw new \RuntimeException('boom'); }
            public function show(IacWorkspace $ws, IacExecutionContext $ctx): IacPlan { throw new \RuntimeException('boom'); }
            public function capabilities(): CapabilityDescriptor { return CapabilityDescriptor::create([]); }
        };

        $lifecycle = new IacLifecycleService($engine, new NullPlanPolicy(), new NullIacAuditSink());
        $auditor = new IacDriftAuditor($lifecycle, sys_get_temp_dir());

        $report = $auditor->audit('dev');
        $this->assertTrue($report->hasDrift);
        $this->assertTrue($report->unreachable);
    }

    private function engineWithPlan(IacPlan $plan): IacEngineInterface
    {
        return new class($plan) implements IacEngineInterface {
            public function __construct(private readonly IacPlan $plan) {}
            public function init(IacWorkspace $ws, IacExecutionContext $ctx): void {}
            public function plan(IacWorkspace $ws, IacExecutionContext $ctx): IacPlan { return $this->plan; }
            public function apply(IacWorkspace $ws, IacPlan $plan, IacExecutionContext $ctx): IacApplyResult { return new IacApplyResult(0, 0, 0, ''); }
            public function destroy(IacWorkspace $ws, IacExecutionContext $ctx): IacDestroyResult { return new IacDestroyResult(0, 0, 0); }
            public function show(IacWorkspace $ws, IacExecutionContext $ctx): IacPlan { return $this->plan; }
            public function capabilities(): CapabilityDescriptor { return CapabilityDescriptor::create([]); }
        };
    }
}
