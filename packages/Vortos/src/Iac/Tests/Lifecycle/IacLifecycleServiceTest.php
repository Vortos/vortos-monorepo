<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Lifecycle;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Exception\DestructiveChangeRefusedException;
use Vortos\Iac\Exception\PolicyViolationException;
use Vortos\Iac\Exception\PlanStaleException;
use Vortos\Iac\Lifecycle\Audit\IacAuditSinkInterface;
use Vortos\Iac\Lifecycle\Audit\LifecycleEvent;
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
use Vortos\Iac\Lifecycle\Policy\PlanPolicyInterface;
use Vortos\Iac\Lifecycle\Policy\PolicyResult;
use Vortos\Iac\Lifecycle\Policy\PolicyViolation;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

final class IacLifecycleServiceTest extends TestCase
{
    private function makeEngine(?IacPlan $planResult = null, ?IacApplyResult $applyResult = null): IacEngineInterface
    {
        $plan = $planResult ?? new IacPlan(
            new IacPlanSummary(1, 0, 0, 0),
            [new IacResourceChange('r.a', 't', IacChangeAction::Create, 'p')],
            sys_get_temp_dir() . '/test-plan-' . bin2hex(random_bytes(4)) . '.bin',
            'digest123',
            'file-digest123',
        );

        $apply = $applyResult ?? new IacApplyResult(1, 0, 100, 'out-digest');

        return new class($plan, $apply) implements IacEngineInterface {
            public function __construct(
                private readonly IacPlan $plan,
                private readonly IacApplyResult $applyResult,
            ) {}
            public function init(IacWorkspace $ws, IacExecutionContext $ctx): void {}
            public function plan(IacWorkspace $ws, IacExecutionContext $ctx): IacPlan { return $this->plan; }
            public function apply(IacWorkspace $ws, IacPlan $plan, IacExecutionContext $ctx): IacApplyResult { return $this->applyResult; }
            public function destroy(IacWorkspace $ws, IacExecutionContext $ctx): IacDestroyResult { return new IacDestroyResult(1, 0, 50); }
            public function show(IacWorkspace $ws, IacExecutionContext $ctx): IacPlan { return $this->plan; }
            public function capabilities(): CapabilityDescriptor { return CapabilityDescriptor::create([]); }
        };
    }

    private function makeWs(): IacWorkspace
    {
        return IacWorkspace::forEnvironment('dev', sys_get_temp_dir());
    }

    public function test_plan_delegates_to_engine(): void
    {
        $service = new IacLifecycleService($this->makeEngine(), new NullPlanPolicy(), new NullIacAuditSink());
        $plan = $service->plan($this->makeWs(), new IacExecutionContext());
        $this->assertTrue($plan->hasChanges());
    }

    public function test_apply_with_missing_plan_file_throws(): void
    {
        $plan = new IacPlan(
            new IacPlanSummary(1, 0, 0, 0),
            [],
            '/nonexistent/path/plan.bin',
            'digest',
            'file-digest',
        );

        $engine = $this->makeEngine($plan);
        $service = new IacLifecycleService($engine, new NullPlanPolicy(), new NullIacAuditSink());

        $this->expectException(PlanStaleException::class);
        $service->apply($this->makeWs(), $plan, new IacExecutionContext());
    }

    public function test_blast_radius_guard_refuses_excess_destroys(): void
    {
        $planFile = sys_get_temp_dir() . '/test-blast-' . bin2hex(random_bytes(4)) . '.bin';
        file_put_contents($planFile, 'plan-data');

        $plan = new IacPlan(
            new IacPlanSummary(0, 0, 3, 0),
            [
                new IacResourceChange('r.a', 't', IacChangeAction::Delete, 'p'),
                new IacResourceChange('r.b', 't', IacChangeAction::Delete, 'p'),
                new IacResourceChange('r.c', 't', IacChangeAction::Delete, 'p'),
            ],
            $planFile,
            'digest',
            hash('sha256', 'plan-data'),
        );

        $engine = $this->makeEngine($plan);
        $service = new IacLifecycleService($engine, new NullPlanPolicy(), new NullIacAuditSink(), maxDestructiveNonProd: 2);

        try {
            $this->expectException(DestructiveChangeRefusedException::class);
            $service->apply($this->makeWs(), $plan, new IacExecutionContext());
        } finally {
            @unlink($planFile);
        }
    }

    public function test_blast_radius_guard_prod_default_zero(): void
    {
        $planFile = sys_get_temp_dir() . '/test-prod-blast-' . bin2hex(random_bytes(4)) . '.bin';
        file_put_contents($planFile, 'plan-data');

        $plan = new IacPlan(
            new IacPlanSummary(0, 0, 1, 0),
            [new IacResourceChange('r.a', 't', IacChangeAction::Delete, 'p')],
            $planFile,
            'digest',
            hash('sha256', 'plan-data'),
        );

        $engine = $this->makeEngine($plan);
        $service = new IacLifecycleService($engine, new NullPlanPolicy(), new NullIacAuditSink());
        $ws = IacWorkspace::forEnvironment('prod', sys_get_temp_dir());

        try {
            $this->expectException(DestructiveChangeRefusedException::class);
            $service->apply($ws, $plan, new IacExecutionContext());
        } finally {
            @unlink($planFile);
        }
    }

    public function test_allow_destructive_bypasses_guard(): void
    {
        $planFile = sys_get_temp_dir() . '/test-allow-' . bin2hex(random_bytes(4)) . '.bin';
        file_put_contents($planFile, 'plan-data');

        $plan = new IacPlan(
            new IacPlanSummary(0, 0, 5, 0),
            [
                new IacResourceChange('r.a', 't', IacChangeAction::Delete, 'p'),
                new IacResourceChange('r.b', 't', IacChangeAction::Delete, 'p'),
                new IacResourceChange('r.c', 't', IacChangeAction::Delete, 'p'),
                new IacResourceChange('r.d', 't', IacChangeAction::Delete, 'p'),
                new IacResourceChange('r.e', 't', IacChangeAction::Delete, 'p'),
            ],
            $planFile,
            'digest',
            hash('sha256', 'plan-data'),
        );

        $engine = $this->makeEngine($plan, new IacApplyResult(5, 0, 200, 'out'));
        $service = new IacLifecycleService($engine, new NullPlanPolicy(), new NullIacAuditSink(), maxDestructiveNonProd: 0);
        $ctx = new IacExecutionContext(allowDestructive: true);

        try {
            $result = $service->apply($this->makeWs(), $plan, $ctx);
            $this->assertTrue($result->isSuccess());
        } finally {
            @unlink($planFile);
        }
    }

    public function test_policy_gate_blocks_violating_plan(): void
    {
        $planFile = sys_get_temp_dir() . '/test-policy-' . bin2hex(random_bytes(4)) . '.bin';
        file_put_contents($planFile, 'plan-data');

        $plan = new IacPlan(
            new IacPlanSummary(1, 0, 0, 0),
            [new IacResourceChange('r.a', 't', IacChangeAction::Create, 'p')],
            $planFile,
            'digest',
            hash('sha256', 'plan-data'),
        );

        $policy = new class implements PlanPolicyInterface {
            public function evaluate(IacPlan $plan): PolicyResult
            {
                return PolicyResult::fail([new PolicyViolation('test-rule', 'r.a', 'Blocked')]);
            }
            public function capabilities(): CapabilityDescriptor { return CapabilityDescriptor::create([]); }
        };

        $engine = $this->makeEngine($plan);
        $service = new IacLifecycleService($engine, $policy, new NullIacAuditSink());

        try {
            $this->expectException(PolicyViolationException::class);
            $service->apply($this->makeWs(), $plan, new IacExecutionContext());
        } finally {
            @unlink($planFile);
        }
    }

    public function test_audit_sink_receives_events(): void
    {
        $events = [];
        $sink = new class($events) implements IacAuditSinkInterface {
            public function __construct(private array &$events) {}
            public function record(LifecycleEvent $event): void { $this->events[] = $event; }
        };

        $engine = $this->makeEngine();
        $service = new IacLifecycleService($engine, new NullPlanPolicy(), $sink);
        $service->plan($this->makeWs(), new IacExecutionContext());

        $this->assertCount(1, $events);
        $this->assertSame('plan', $events[0]->phase->value);
    }

    public function test_show_delegates_to_engine(): void
    {
        $service = new IacLifecycleService($this->makeEngine(), new NullPlanPolicy(), new NullIacAuditSink());
        $plan = $service->show($this->makeWs(), new IacExecutionContext());
        $this->assertInstanceOf(IacPlan::class, $plan);
    }

    public function test_apply_with_tampered_plan_file_throws_digest_mismatch(): void
    {
        $planFile = sys_get_temp_dir() . '/test-tamper-' . bin2hex(random_bytes(4)) . '.bin';
        file_put_contents($planFile, 'original-plan-data');

        $plan = new IacPlan(
            new IacPlanSummary(1, 0, 0, 0),
            [new IacResourceChange('r.a', 't', IacChangeAction::Create, 'p')],
            $planFile,
            'digest',
            hash('sha256', 'original-plan-data'),
        );

        file_put_contents($planFile, 'swapped-plan-data');

        $engine = $this->makeEngine($plan);
        $service = new IacLifecycleService($engine, new NullPlanPolicy(), new NullIacAuditSink());

        try {
            $this->expectException(PlanStaleException::class);
            $this->expectExceptionMessage('digest mismatch');
            $service->apply($this->makeWs(), $plan, new IacExecutionContext());
        } finally {
            @unlink($planFile);
        }
    }

    public function test_apply_with_unmodified_plan_file_passes_digest_guard(): void
    {
        $planFile = sys_get_temp_dir() . '/test-intact-' . bin2hex(random_bytes(4)) . '.bin';
        file_put_contents($planFile, 'plan-data');

        $plan = new IacPlan(
            new IacPlanSummary(1, 0, 0, 0),
            [new IacResourceChange('r.a', 't', IacChangeAction::Create, 'p')],
            $planFile,
            'digest',
            hash('sha256', 'plan-data'),
        );

        $engine = $this->makeEngine($plan, new IacApplyResult(1, 0, 100, 'out'));
        $service = new IacLifecycleService($engine, new NullPlanPolicy(), new NullIacAuditSink());

        try {
            $result = $service->apply($this->makeWs(), $plan, new IacExecutionContext());
            $this->assertTrue($result->isSuccess());
        } finally {
            @unlink($planFile);
        }
    }

    public function test_destroy_emits_audit_event(): void
    {
        $events = [];
        $sink = new class($events) implements IacAuditSinkInterface {
            public function __construct(private array &$events) {}
            public function record(LifecycleEvent $event): void { $this->events[] = $event; }
        };

        $service = new IacLifecycleService($this->makeEngine(), new NullPlanPolicy(), $sink);
        $service->destroy($this->makeWs(), new IacExecutionContext());

        $this->assertCount(1, $events);
        $this->assertSame('destroy', $events[0]->phase->value);
    }
}
