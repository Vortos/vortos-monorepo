<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Compose\ComposeProjectFactory;
use Vortos\Deploy\Runtime\RuntimeServiceSpec;
use Vortos\Deploy\Cutover\CutoverCoordinator;
use Vortos\Deploy\Cutover\NullCutoverEventRecorder;
use Vortos\Deploy\Driver\SshCompose\SshComposeTarget;
use Vortos\Deploy\Driver\Docker\ImageReclaimer;
use Vortos\Deploy\Driver\SshCompose\StepExecutor;
use Vortos\Deploy\Exception\RollbackRefusedException;
use Vortos\Deploy\Plan\DeployPlanner;
use Vortos\Deploy\Plan\PlanRenderer;
use Vortos\Deploy\Rollback\RollbackGuard;
use Vortos\Deploy\Runner\DeployOutcomeStatus;
use Vortos\Deploy\Runner\RollbackRunner;
use Vortos\Deploy\Strategy\BlueGreenStrategy;
use Vortos\Deploy\Strategy\DeployStrategyRegistry;
use Vortos\Deploy\Target\DeployTargetRegistry;
use Vortos\Deploy\Tests\Fixtures\FakeAppliedMigrationSetReader;
use Vortos\Deploy\Tests\Fixtures\FakeCommandRunner;
use Vortos\Deploy\Tests\Fixtures\FakeContainerRegistry;
use Vortos\Deploy\Tests\Fixtures\FakeDeployStateStore;
use Vortos\Deploy\Tests\Fixtures\FakeEdgeRouter;
use Vortos\Deploy\Tests\Fixtures\FakeManifestReadModel;
use Vortos\Deploy\Tests\Fixtures\FakeReadinessGate;
use Vortos\Deploy\Tests\Fixtures\FakeSmokeRunner;
use Vortos\Deploy\Tests\Fixtures\InMemoryServiceLocator;
use Vortos\Deploy\Tests\Fixtures\PreflightTestFactory;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Schema\SchemaFingerprint;

/**
 * Wired rollback against the real {@see RollbackGuard}: refuses an illegal target,
 * succeeds on a legal one.
 */
final class RollbackIntegrationTest extends TestCase
{
    use PreflightTestFactory;

    public function test_legal_rollback_succeeds(): void
    {
        $runner = $this->runner(
            previous: $this->manifest(['m001'], buildId: 'build-prev'),
            applied: ['m001', 'm002'],
            known: ['m001', 'm002'],
        );

        $outcome = $runner->rollback('production');

        $this->assertSame(DeployOutcomeStatus::RolledBack, $outcome->status);
    }

    public function test_illegal_rollback_is_refused(): void
    {
        $runner = $this->runner(
            previous: $this->manifest(['m001', 'm003'], buildId: 'build-prev'),
            applied: ['m001', 'm002'],
            known: ['m001', 'm002', 'm003'],
        );

        $this->expectException(RollbackRefusedException::class);
        $runner->rollback('production');
    }

    /**
     * @param list<string> $applied
     * @param list<string> $known
     */
    private function runner(BuildManifest $previous, array $applied, array $known): RollbackRunner
    {
        $store = new FakeDeployStateStore();
        $registry = new FakeContainerRegistry();
        $executor = new StepExecutor(
            stateStore: $store,
            registry: $registry,
            readinessGate: new FakeReadinessGate(),
            smokeRunner: new FakeSmokeRunner(),
            composeFactory: new ComposeProjectFactory(new RuntimeServiceSpec()),
            localRunner: new FakeCommandRunner(),
            cutoverCoordinator: new CutoverCoordinator(new FakeEdgeRouter(), $store, new NullCutoverEventRecorder()),
        );
        $strategies = new DeployStrategyRegistry();
        $strategies->register(new BlueGreenStrategy());

        $target = new SshComposeTarget(new DeployPlanner($strategies), $executor, $registry, $store, $store, new ImageReclaimer(new FakeCommandRunner()));
        $targets = new DeployTargetRegistry(new InMemoryServiceLocator(['ssh-compose' => $target]));

        $manifests = new FakeManifestReadModel(
            previous: $previous,
            applied: new SchemaFingerprint($applied),
            knownIds: $known,
        );
        $guard = new RollbackGuard(new FakeAppliedMigrationSetReader(new SchemaFingerprint($applied)), $manifests);

        return new RollbackRunner(
            $this->resolver(host: 'ssh-compose', registry: 'fake-registry', credential: 'fake-credential'),
            $manifests,
            $guard,
            $targets,
            $this->stateBuilder($applied),
            new PlanRenderer(),
        );
    }
}
