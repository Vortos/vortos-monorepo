<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Runner;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Exception\RollbackRefusedException;
use Vortos\Deploy\Exception\RollbackTargetNotFoundException;
use Vortos\Deploy\Plan\PlanRenderer;
use Vortos\Deploy\Rollback\RollbackGuard;
use Vortos\Deploy\Runner\DeployOutcomeStatus;
use Vortos\Deploy\Runner\RollbackRunner;
use Vortos\Deploy\Target\DeployTargetRegistry;
use Vortos\Deploy\Tests\Fixtures\FakeAppliedMigrationSetReader;
use Vortos\Deploy\Tests\Fixtures\FakeManifestReadModel;
use Vortos\Deploy\Tests\Fixtures\InMemoryServiceLocator;
use Vortos\Deploy\Tests\Fixtures\PreflightTestFactory;
use Vortos\Deploy\Tests\Fixtures\RecordingDeployTarget;
use Vortos\Release\Schema\SchemaFingerprint;

final class RollbackRunnerTest extends TestCase
{
    use PreflightTestFactory;

    public function test_legal_target_rolls_back(): void
    {
        $target = new RecordingDeployTarget();
        // previous fingerprint m001 ⊆ applied {m001,m002}; both known → legal.
        $runner = $this->runner(
            $target,
            previous: $this->manifest(['m001'], buildId: 'build-prev'),
            applied: ['m001', 'm002'],
            known: ['m001', 'm002'],
        );

        $outcome = $runner->rollback('production');

        $this->assertSame(DeployOutcomeStatus::RolledBack, $outcome->status);
        $this->assertSame(1, $target->rollbackCalls);
        $this->assertStringContainsString('build-prev', (string) $outcome->rollbackReason);
    }

    public function test_illegal_target_is_refused(): void
    {
        $target = new RecordingDeployTarget();
        // target wants m003 which is NOT applied → rollback would un-apply nothing legal.
        $runner = $this->runner(
            $target,
            previous: $this->manifest(['m001', 'm003'], buildId: 'build-prev'),
            applied: ['m001', 'm002'],
            known: ['m001', 'm002', 'm003'],
        );

        $this->expectException(RollbackRefusedException::class);
        try {
            $runner->rollback('production');
        } finally {
            $this->assertSame(0, $target->rollbackCalls, 'an illegal rollback must never execute');
        }
    }

    public function test_unknown_to_build_is_refused(): void
    {
        $runner = $this->runner(new RecordingDeployTarget(), previous: null, applied: ['m001'], known: ['m001']);

        $this->expectException(RollbackTargetNotFoundException::class);
        $runner->rollback('production', 'does-not-exist');
    }

    public function test_no_previous_and_no_to_is_refused(): void
    {
        $runner = $this->runner(new RecordingDeployTarget(), previous: null, applied: ['m001'], known: ['m001']);

        $this->expectException(RollbackTargetNotFoundException::class);
        $runner->rollback('production');
    }

    public function test_explicit_to_build_rolls_back(): void
    {
        $target = new RecordingDeployTarget();
        $explicit = $this->manifest(['m001'], buildId: 'build-explicit');
        $manifests = new FakeManifestReadModel(applied: new SchemaFingerprint(['m001']), knownIds: ['m001']);
        $manifests->register($explicit);

        $runner = $this->runnerWith($target, $manifests, ['m001']);

        $outcome = $runner->rollback('production', 'build-explicit');

        $this->assertSame(DeployOutcomeStatus::RolledBack, $outcome->status);
        $this->assertSame(1, $target->rollbackCalls);
    }

    /**
     * @param list<string> $applied
     * @param list<string> $known
     */
    private function runner(
        RecordingDeployTarget $target,
        ?\Vortos\Release\Manifest\BuildManifest $previous,
        array $applied,
        array $known,
    ): RollbackRunner {
        $manifests = new FakeManifestReadModel(
            previous: $previous,
            applied: new SchemaFingerprint($applied),
            knownIds: $known,
        );

        return $this->runnerWith($target, $manifests, $applied);
    }

    /** @param list<string> $applied */
    private function runnerWith(
        RecordingDeployTarget $target,
        FakeManifestReadModel $manifests,
        array $applied,
    ): RollbackRunner {
        $targets = new DeployTargetRegistry(new InMemoryServiceLocator(['fake-target' => $target]));
        $guard = new RollbackGuard(new FakeAppliedMigrationSetReader(new SchemaFingerprint($applied)), $manifests);

        return new RollbackRunner(
            $this->resolver(),
            $manifests,
            $guard,
            $targets,
            $this->stateBuilder($applied),
            new PlanRenderer(),
        );
    }
}
