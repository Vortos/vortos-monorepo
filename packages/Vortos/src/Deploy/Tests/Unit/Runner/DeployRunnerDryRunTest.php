<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Runner;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Plan\PlanRenderer;
use Vortos\Deploy\Preflight\DeployDoctor;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightContextFactory;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\Deploy\Rollback\RollbackGuard;
use Vortos\Deploy\Runner\DeployOutcomeStatus;
use Vortos\Deploy\Runner\DeployRequest;
use Vortos\Deploy\Runner\DeployRunner;
use Vortos\Deploy\Runner\RollbackRunner;
use Vortos\Deploy\Target\DeployTargetRegistry;
use Vortos\Deploy\Tests\Fixtures\FakeAppliedMigrationSetReader;
use Vortos\Deploy\Tests\Fixtures\FakeManifestReadModel;
use Vortos\Deploy\Tests\Fixtures\InMemoryServiceLocator;
use Vortos\Deploy\Tests\Fixtures\PreflightTestFactory;
use Vortos\Deploy\Tests\Fixtures\RecordingDeployTarget;
use Vortos\Release\Schema\SchemaFingerprint;

/**
 * §15.2 mandatory: `--dry-run` mutates nothing, proven by a recording target that
 * counts every mutating call. The plan is still built and previewed; the outcome is
 * dry-run with exit 0.
 */
final class DeployRunnerDryRunTest extends TestCase
{
    use PreflightTestFactory;

    public function test_dry_run_builds_plan_but_mutates_nothing(): void
    {
        $target = new RecordingDeployTarget();
        $targets = new DeployTargetRegistry(new InMemoryServiceLocator(['fake-target' => $target]));

        $manifest = $this->manifest(['m001']);
        $manifests = new FakeManifestReadModel(
            latest: $manifest,
            previous: $manifest,
            applied: new SchemaFingerprint(['m001']),
            knownIds: ['m001'],
        );

        $factory = new PreflightContextFactory($this->resolver(), $manifests, $this->stateBuilder(['m001']), $targets);
        $rollbackGuard = new RollbackGuard(new FakeAppliedMigrationSetReader(new SchemaFingerprint(['m001'])), $manifests);
        $rollback = new RollbackRunner($this->resolver(), $manifests, $rollbackGuard, $targets, $this->stateBuilder(['m001']), new PlanRenderer());

        $runner = new DeployRunner($factory, $targets, new PlanRenderer(), $this->clearDoctor(), $rollback);

        $outcome = $runner->run(DeployRequest::dryRun('production'));

        $this->assertSame(DeployOutcomeStatus::DryRun, $outcome->status);
        $this->assertSame(0, $outcome->exitCode());
        $this->assertNotNull($outcome->plan, 'dry-run must still build the plan');
        $this->assertNotNull($outcome->preview);
        $this->assertNotSame('', (string) $outcome->preview);

        // The whole point: zero mutation.
        $this->assertSame(0, $target->assertImageAvailableCalls);
        $this->assertSame(0, $target->releaseCalls);
        $this->assertSame(0, $target->rollbackCalls);
    }

    private function clearDoctor(): DeployDoctor
    {
        $check = new class implements PreflightCheckInterface {
            public function id(): string
            {
                return 'stub';
            }

            public function category(): PreflightCategory
            {
                return PreflightCategory::DriverSet;
            }

            public function check(PreflightContext $context): PreflightFinding
            {
                return PreflightFinding::pass('stub', PreflightCategory::DriverSet, 'ok');
            }
        };

        return new DeployDoctor([$check]);
    }
}
