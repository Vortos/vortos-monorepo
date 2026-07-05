<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Compose\ComposeProjectFactory;
use Vortos\Deploy\Runtime\RuntimeServiceSpec;
use Vortos\Deploy\Cutover\CutoverCoordinator;
use Vortos\Deploy\Cutover\NullCutoverEventRecorder;
use Vortos\Deploy\Driver\LocalFile\FileDeployStateStore;
use Vortos\Deploy\Driver\SshCompose\SshComposeTarget;
use Vortos\Deploy\Driver\SshCompose\StepExecutor;
use Vortos\Deploy\Plan\DeployPlanner;
use Vortos\Deploy\Plan\PhaseGate;
use Vortos\Deploy\Plan\PlanRenderer;
use Vortos\Deploy\Preflight\Check\CapabilityDescriptorCheck;
use Vortos\Deploy\Preflight\Check\CredentialCheck;
use Vortos\Deploy\Preflight\Check\DriverSetCheck;
use Vortos\Deploy\Preflight\Check\SchemaCompatibilityCheck;
use Vortos\Deploy\Preflight\Check\TargetArchCheck;
use Vortos\Deploy\Preflight\DeployDoctor;
use Vortos\Deploy\Preflight\PreflightContextFactory;
use Vortos\Deploy\Rollback\RollbackGuard;
use Vortos\Deploy\Runner\DeployOutcomeStatus;
use Vortos\Deploy\Runner\DeployRequest;
use Vortos\Deploy\Runner\DeployRunner;
use Vortos\Deploy\Runner\RollbackRunner;
use Vortos\Deploy\Strategy\BlueGreenStrategy;
use Vortos\Deploy\Strategy\DeployStrategyRegistry;
use Vortos\Deploy\Target\DeployTargetRegistry;
use Vortos\Deploy\Tests\Fixtures\FakeAppliedMigrationSetReader;
use Vortos\Deploy\Tests\Fixtures\FakeCommandRunner;
use Vortos\Deploy\Tests\Fixtures\FakeContainerRegistry;
use Vortos\Deploy\Tests\Fixtures\FakeEdgeRouter;
use Vortos\Deploy\Tests\Fixtures\FakeManifestReadModel;
use Vortos\Deploy\Tests\Fixtures\FakeReadinessGate;
use Vortos\Deploy\Tests\Fixtures\FakeSmokeRunner;
use Vortos\Deploy\Tests\Fixtures\InMemoryServiceLocator;
use Vortos\Deploy\Tests\Fixtures\PreflightTestFactory;
use Vortos\Release\Schema\SchemaFingerprint;

/**
 * §15.2 mandatory: `--dry-run` mutates nothing — proven against a real on-disk state
 * store (the directory stays empty) and a spy command runner (zero docker commands).
 */
final class DeployDryRunIntegrationTest extends TestCase
{
    use PreflightTestFactory;

    private string $stateDir;

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/vortos-deploy-dryrun-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->stateDir)) {
            foreach (glob($this->stateDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->stateDir);
        }
    }

    public function test_dry_run_writes_no_state_and_runs_no_commands(): void
    {
        $store = new FileDeployStateStore($this->stateDir);
        $localRunner = new FakeCommandRunner();
        $registry = new FakeContainerRegistry();

        $executor = new StepExecutor(
            stateStore: $store,
            registry: $registry,
            readinessGate: new FakeReadinessGate(),
            smokeRunner: new FakeSmokeRunner(),
            composeFactory: new ComposeProjectFactory(new RuntimeServiceSpec()),
            localRunner: $localRunner,
            cutoverCoordinator: new CutoverCoordinator(new FakeEdgeRouter(), $store, new NullCutoverEventRecorder()),
        );

        $strategies = new DeployStrategyRegistry();
        $strategies->register(new BlueGreenStrategy());

        $target = new SshComposeTarget(
            planner: new DeployPlanner($strategies),
            executor: $executor,
            registry: $registry,
            stateStore: $store,
            releaseStore: $store,
        );

        $targets = new DeployTargetRegistry(new InMemoryServiceLocator(['ssh-compose' => $target]));

        $manifest = $this->manifest(['m001'], env: 'production');
        $manifests = new FakeManifestReadModel(
            latest: $manifest,
            previous: $manifest,
            applied: new SchemaFingerprint(['m001']),
            knownIds: ['m001'],
        );

        $resolver = $this->resolver(host: 'ssh-compose', registry: 'fake-registry', credential: 'fake-credential');
        $factory = new PreflightContextFactory($resolver, $manifests, $this->stateBuilder(['m001']), $targets);

        $doctor = new DeployDoctor([
            new DriverSetCheck($targets, $this->registryRegistry(), $this->credentialRegistry(), $strategies),
            new CapabilityDescriptorCheck($targets, $strategies),
            new CredentialCheck($this->credentialRegistry()),
            new TargetArchCheck($targets),
            new SchemaCompatibilityCheck(new PhaseGate(), $manifests),
        ]);

        $guard = new RollbackGuard(new FakeAppliedMigrationSetReader(new SchemaFingerprint(['m001'])), $manifests);
        $rollback = new RollbackRunner($resolver, $manifests, $guard, $targets, $this->stateBuilder(['m001']), new PlanRenderer());

        $runner = new DeployRunner($factory, $targets, new PlanRenderer(), $doctor, $rollback);

        $outcome = $runner->run(DeployRequest::dryRun('production'));

        $this->assertSame(DeployOutcomeStatus::DryRun, $outcome->status);
        $this->assertSame([], $localRunner->calls, 'dry-run must issue no container commands');

        $files = glob($this->stateDir . '/*') ?: [];
        $this->assertSame([], $files, 'dry-run must not write any deploy-state file');
    }
}
