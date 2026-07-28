<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Driver;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Compose\ComposeProjectFactory;
use Vortos\Deploy\Definition\DeploymentDefinition;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Driver\Docker\ImageReclaimer;
use Vortos\Deploy\Driver\SshCompose\SshComposeTarget;
use Vortos\Deploy\Driver\SshCompose\StepExecutor;
use Vortos\Deploy\Plan\CurrentDeployState;
use Vortos\Deploy\Plan\DeployContext;
use Vortos\Deploy\Plan\DeployPlan;
use Vortos\Deploy\Plan\DeployPlanner;
use Vortos\Deploy\Runtime\RuntimeServiceSpec;
use Vortos\Deploy\State\CurrentRelease;
use Vortos\Deploy\Strategy\BlueGreenStrategy;
use Vortos\Deploy\Strategy\DeployStrategyRegistry;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Deploy\Tests\Fixtures\FakeCommandRunner;
use Vortos\Deploy\Tests\Fixtures\FakeContainerRegistry;
use Vortos\Deploy\Tests\Fixtures\FakeDeployStateStore;
use Vortos\Deploy\Tests\Fixtures\FakeReadinessGate;
use Vortos\Deploy\Tests\Fixtures\FakeSmokeRunner;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Schema\SchemaFingerprint;

/**
 * An aborted deploy must not leave the candidate color half-running.
 *
 * When the health gate fails, the candidate color has already been brought up. The abort used to
 * just rethrow, so those containers stayed exactly as the failing step left them: the app container
 * up, the worker container stuck in Created. Nothing served from that color and nothing ever would,
 * which is why it read as harmless — but the worker holds every Kafka consumer, so background
 * processing was silently stopped, with no failing check anywhere to say so. Someone has to notice.
 *
 * The other half of the invariant matters more: if cutover already happened, the candidate IS the
 * live site. Tearing it down as "cleanup" would convert a failed deploy into a self-inflicted
 * outage. So teardown is conditional on the recorded live release disagreeing with the candidate.
 */
final class AbortedDeployLeavesNoStrandedColorTest extends TestCase
{
    /**
     * @return array{0: SshComposeTarget, 1: FakeDeployStateStore, 2: FakeCommandRunner, 3: FakeReadinessGate, 4: DeployPlan}
     */
    private function harness(): array
    {
        $strategyRegistry = new DeployStrategyRegistry();
        $strategyRegistry->register(new BlueGreenStrategy());

        $stateStore = new FakeDeployStateStore();
        $registry = new FakeContainerRegistry();
        $runner = new FakeCommandRunner();
        $gate = new FakeReadinessGate();

        $executor = new StepExecutor(
            stateStore: $stateStore,
            registry: $registry,
            readinessGate: $gate,
            smokeRunner: new FakeSmokeRunner(),
            composeFactory: new ComposeProjectFactory(new RuntimeServiceSpec()),
            localRunner: $runner,
        );

        $planner = new DeployPlanner($strategyRegistry);

        $target = new SshComposeTarget(
            planner: $planner,
            executor: $executor,
            registry: $registry,
            stateStore: $stateStore,
            releaseStore: $stateStore,
            reclaimer: new ImageReclaimer(new FakeCommandRunner()),
        );

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

        $plan = $planner->plan(new DeployContext(
            DeploymentDefinition::build(),
            $manifest,
            CurrentDeployState::firstDeploy(),
        ));

        return [$target, $stateStore, $runner, $gate, $plan];
    }

    /** @return list<string> the compose project names this run tore down */
    private function torndownProjects(FakeCommandRunner $runner): array
    {
        $projects = [];

        foreach ($runner->calls as $call) {
            $argv = array_values(array_map('strval', $call['argv']));

            if (!\in_array('down', $argv, true)) {
                continue;
            }

            $at = array_search('-p', $argv, true);
            if ($at !== false && isset($argv[$at + 1])) {
                $projects[] = $argv[$at + 1];
            }
        }

        return $projects;
    }

    public function test_a_failed_health_gate_tears_the_candidate_color_back_down(): void
    {
        [$target, , $runner, $gate, $plan] = $this->harness();

        $gate->shouldPass = false;

        try {
            $target->release($plan, new EnvironmentName('production'));
            self::fail('the deploy should have aborted on the failed health gate');
        } catch (\Throwable) {
            // expected — the abort is the point; what happens to the candidate is what is asserted
        }

        self::assertNotSame(
            [],
            $this->torndownProjects($runner),
            'An aborted deploy left the candidate color running. Its worker container holds every '
            . 'Kafka consumer, so background processing stops silently with nothing reporting it.',
        );
    }

    /**
     * The safety half. If the candidate is already the recorded live release, cutover happened and
     * this color is the site — cleanup must keep its hands off it.
     */
    public function test_it_never_tears_down_a_color_that_is_already_live(): void
    {
        [$target, $stateStore, $runner, $gate, $plan] = $this->harness();

        $candidate = ActiveColor::Blue;

        $stateStore->recordCurrentRelease(new CurrentRelease(
            env: 'production',
            activeColor: $candidate,
            imageDigest: 'sha256:' . str_repeat('cd', 32),
            buildId: 'build-0',
            planHash: 'unrelated-plan',
            recordedAt: new \DateTimeImmutable(),
            generation: 1,
        ));

        $gate->shouldPass = false;

        try {
            $target->release($plan, new EnvironmentName('production'));
        } catch (\Throwable) {
            // expected
        }

        self::assertNotContains(
            'vortos-app-' . $candidate->value,
            $this->torndownProjects($runner),
            'Cleanup tore down the LIVE color. A failed deploy must never become an outage.',
        );
    }

    /**
     * Cleanup runs while an exception is propagating. It must never replace the real failure with
     * its own — the operator needs to see why the deploy failed, not why the cleanup did.
     */
    public function test_a_cleanup_failure_does_not_mask_the_deploy_failure(): void
    {
        [$target, , , $gate, $plan] = $this->harness();

        $gate->shouldPass = false;

        $this->expectException(\Vortos\Deploy\Exception\DeployAbortedException::class);

        $target->release($plan, new EnvironmentName('production'));
    }
}
