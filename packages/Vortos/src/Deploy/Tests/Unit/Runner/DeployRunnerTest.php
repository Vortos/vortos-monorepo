<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Runner;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Exception\DeployAbortedException;
use Vortos\Deploy\Plan\PlanRenderer;
use Vortos\Deploy\Preflight\DeployDoctor;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightContextFactory;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\Rollback\RollbackGuard;
use Vortos\Deploy\Runner\DeployOutcomeStatus;
use Vortos\Deploy\Runner\DeployRequest;
use Vortos\Deploy\Runner\DeployRunner;
use Vortos\Deploy\Runner\RollbackRunner;
use Vortos\Deploy\Target\DeployTargetRegistry;
use Vortos\Deploy\Tests\Fixtures\FakeAppliedMigrationSetReader;
use Vortos\Deploy\Tests\Fixtures\FakeManifestReadModel;
use Vortos\Deploy\Tests\Fixtures\PreflightTestFactory;
use Vortos\Deploy\Tests\Fixtures\RecordingDeployTarget;
use Vortos\Release\Schema\SchemaFingerprint;

final class DeployRunnerTest extends TestCase
{
    use PreflightTestFactory;

    public function test_doctor_not_clear_refuses_and_never_mutates(): void
    {
        $target = new RecordingDeployTarget();
        $runner = $this->runner($target, doctorClear: false);

        $outcome = $runner->run(DeployRequest::live('production', assumeYes: true));

        $this->assertSame(DeployOutcomeStatus::Refused, $outcome->status);
        $this->assertSame(1, $outcome->exitCode());
        $this->assertSame(0, $target->assertImageAvailableCalls);
        $this->assertSame(0, $target->releaseCalls);
        $this->assertSame(0, $target->planCalls, 'a refused deploy must not even build the plan path that mutates');
    }

    public function test_clear_live_deploy_pushes_and_releases_once(): void
    {
        $target = new RecordingDeployTarget();
        $runner = $this->runner($target, doctorClear: true);

        $outcome = $runner->run(DeployRequest::live('production', assumeYes: true));

        $this->assertSame(DeployOutcomeStatus::Deployed, $outcome->status);
        $this->assertSame(0, $outcome->exitCode());
        $this->assertSame(1, $target->assertImageAvailableCalls);
        $this->assertSame(1, $target->releaseCalls);
        $this->assertSame(0, $target->rollbackCalls);
    }

    public function test_missing_manifest_aborts(): void
    {
        $target = new RecordingDeployTarget();
        $runner = $this->runner($target, doctorClear: true, latestManifest: false);

        $this->expectException(DeployAbortedException::class);
        $runner->run(DeployRequest::live('production', assumeYes: true));
    }

    public function test_release_failure_with_auto_rollback_rolls_back(): void
    {
        $target = new RecordingDeployTarget(failRelease: true);
        $runner = $this->runner($target, doctorClear: true, autoRollback: true);

        $outcome = $runner->run(DeployRequest::live('production', assumeYes: true));

        $this->assertSame(DeployOutcomeStatus::RolledBack, $outcome->status);
        $this->assertSame(1, $target->releaseCalls);
        $this->assertSame(1, $target->rollbackCalls, 'auto-rollback must go through the single rollback path');
    }

    public function test_release_failure_without_auto_rollback_propagates(): void
    {
        $target = new RecordingDeployTarget(failRelease: true);
        $runner = $this->runner($target, doctorClear: true, autoRollback: false);

        $this->expectException(\RuntimeException::class);
        $runner->run(DeployRequest::live('production', assumeYes: true));
    }

    private function runner(
        RecordingDeployTarget $target,
        bool $doctorClear,
        bool $latestManifest = true,
        bool $autoRollback = true,
    ): DeployRunner {
        $targets = new DeployTargetRegistry(
            new \Vortos\Deploy\Tests\Fixtures\InMemoryServiceLocator(['fake-target' => $target]),
        );

        $manifest = $this->manifest(['m001']);
        $manifests = new FakeManifestReadModel(
            latest: $latestManifest ? $manifest : null,
            previous: $manifest,
            applied: new SchemaFingerprint(['m001']),
            knownIds: ['m001'],
        );

        $factory = new PreflightContextFactory(
            $this->resolver(autoRollback: $autoRollback),
            $manifests,
            $this->stateBuilder(['m001']),
            $targets,
        );

        $rollbackGuard = new RollbackGuard(new FakeAppliedMigrationSetReader(new SchemaFingerprint(['m001'])), $manifests);
        $rollbackRunner = new RollbackRunner(
            $this->resolver(autoRollback: $autoRollback),
            $manifests,
            $rollbackGuard,
            $targets,
            $this->stateBuilder(['m001']),
            new PlanRenderer(),
        );

        return new DeployRunner(
            $factory,
            $targets,
            new PlanRenderer(),
            $this->doctor($doctorClear),
            $rollbackRunner,
        );
    }

    private function doctor(bool $clear): DeployDoctor
    {
        $finding = $clear
            ? PreflightFinding::pass('stub', PreflightCategory::DriverSet, 'ok')
            : PreflightFinding::fail('stub', PreflightCategory::DriverSet, 'blocked');

        $check = new class($finding) implements PreflightCheckInterface {
            public function __construct(private readonly PreflightFinding $finding) {}

            public function id(): string
            {
                return $this->finding->id;
            }

            public function category(): PreflightCategory
            {
                return $this->finding->category;
            }

            public function check(PreflightContext $context): PreflightFinding
            {
                return $this->finding;
            }
        };

        return new DeployDoctor([$check]);
    }
}
