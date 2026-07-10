<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Driver;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Compose\ComposeProjectFactory;
use Vortos\Deploy\Definition\DeploymentDefinition;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Driver\SshCompose\SshComposeTarget;
use Vortos\Deploy\Driver\SshCompose\StepExecutor;
use Vortos\Deploy\Exception\DeployException;
use Vortos\Deploy\Plan\CurrentDeployState;
use Vortos\Deploy\Plan\DeployContext;
use Vortos\Deploy\Plan\DeployPlanner;
use Vortos\Deploy\Driver\Docker\ImageReclaimer;
use Vortos\Deploy\Runtime\RuntimeServiceSpec;
use Vortos\Deploy\Strategy\BlueGreenStrategy;
use Vortos\Deploy\Strategy\DeployStrategyRegistry;
use Vortos\Deploy\Tests\Fixtures\FakeCommandRunner;
use Vortos\Deploy\Tests\Fixtures\FakeContainerRegistry;
use Vortos\Deploy\Tests\Fixtures\FakeDeployStateStore;
use Vortos\Deploy\Tests\Fixtures\FakeReadinessGate;
use Vortos\Deploy\Tests\Fixtures\FakeSmokeRunner;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Schema\SchemaFingerprint;

/**
 * The core of the failed-deploy image-leak fix: reclaim must run on the FAILURE path too, so a
 * failed deploy's freshly-pulled-but-never-promoted image is reclaimed instead of lingering until
 * some future green deploy. Proven by driving a deploy whose health gate fails and asserting the
 * reclaimer still issued its docker sweep — while the deploy exception still propagates.
 */
final class SshComposeReclaimOnFailureTest extends TestCase
{
    public function test_reclaim_runs_and_exception_propagates_when_deploy_fails(): void
    {
        $strategies = new DeployStrategyRegistry();
        $strategies->register(new BlueGreenStrategy());

        $stateStore = new FakeDeployStateStore();
        $registry = new FakeContainerRegistry();

        $gate = new FakeReadinessGate();
        $gate->shouldPass = false; // health gate fails → execute() throws mid-plan

        $executor = new StepExecutor(
            stateStore: $stateStore,
            registry: $registry,
            readinessGate: $gate,
            smokeRunner: new FakeSmokeRunner(),
            composeFactory: new ComposeProjectFactory(new RuntimeServiceSpec()),
            localRunner: new FakeCommandRunner(),
        );

        $reclaimRunner = new FakeCommandRunner();
        $target = new SshComposeTarget(
            planner: new DeployPlanner($strategies),
            executor: $executor,
            registry: $registry,
            stateStore: $stateStore,
            releaseStore: $stateStore,
            reclaimer: new ImageReclaimer($reclaimRunner),
        );

        $plan = $target->plan($this->context());

        $threw = false;
        try {
            $target->release($plan, new EnvironmentName('production'));
        } catch (DeployException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'a failed deploy must still surface its exception');

        // The finally-block reclaim ran despite the failure: the reclaimer issued its image sweep.
        $argvs = array_map(static fn (array $c): array => $c['argv'], $reclaimRunner->calls);
        $this->assertNotEmpty($argvs, 'reclaim must run on the failure path');
        $this->assertContains(
            ['docker', 'images', 'ghcr.io/acme/app', '--no-trunc', '--format', '{{.ID}}|{{.Digest}}'],
            $argvs,
        );
    }

    private function context(): DeployContext
    {
        $manifest = new BuildManifest(
            'build-1',
            'abc1234',
            'ghcr.io/acme/app',
            'sha256:' . str_repeat('ab', 32),
            Arch::Arm64,
            'production',
            SchemaFingerprint::empty(),
            new \DateTimeImmutable(),
        );

        return new DeployContext(
            DeploymentDefinition::build(),
            $manifest,
            CurrentDeployState::firstDeploy(),
        );
    }
}
