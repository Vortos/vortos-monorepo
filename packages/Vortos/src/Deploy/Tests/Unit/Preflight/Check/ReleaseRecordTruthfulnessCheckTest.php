<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Preflight\Check;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Vortos\Deploy\Definition\DeploymentDefinition;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Plan\CurrentDeployState;
use Vortos\Deploy\Preflight\Check\ReleaseRecordTruthfulnessCheck;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightStatus;
use Vortos\Deploy\State\CurrentRelease;
use Vortos\Deploy\State\CurrentReleaseStoreInterface;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Deploy\Target\DeployTargetInterface;
use Vortos\Deploy\Target\DeployTargetRegistry;
use Vortos\Deploy\Target\TargetStatus;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Schema\SchemaFingerprint;

/**
 * A deploy re-run after a failed health gate completed in 27 seconds reporting
 * `status: deployed, health_status: ok` — while restaging nothing. It short-circuited on
 * idempotency and still rewrote `current_release` to the DESIRED digest, so the control plane
 * asserted an image was live that was running nowhere.
 *
 * That is worse than a failed deploy: CI is green, the new code is not running, and because the
 * recorded digest now equals the desired one, every later deploy of that digest also no-ops as
 * "already applied". Rollback, edge reconciliation and image reclamation all read that record.
 */
final class ReleaseRecordTruthfulnessCheckTest extends TestCase
{
    private const RECORDED = 'sha256:1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a';
    private const RUNNING  = 'sha256:2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b';

    private function context(): PreflightContext
    {
        return new PreflightContext(
            definition: DeploymentDefinition::build(),
            desiredManifest: new BuildManifest(
                buildId: 'b1',
                gitSha: 'abcdef1',
                imageRepository: 'ghcr.io/acme/app',
                imageDigest: self::RUNNING,
                targetArch: Arch::Arm64,
                environment: 'production',
                schemaFingerprint: SchemaFingerprint::empty(),
                createdAt: new \DateTimeImmutable(),
            ),
            currentState: CurrentDeployState::firstDeploy(),
            environment: new EnvironmentName('production'),
        );
    }

    private function releases(?CurrentRelease $release): CurrentReleaseStoreInterface
    {
        return new class($release) implements CurrentReleaseStoreInterface {
            public function __construct(private ?CurrentRelease $release) {}

            public function currentRelease(string $env): ?CurrentRelease
            {
                return $this->release;
            }

            public function recordCurrentRelease(CurrentRelease $release): void {}
        };
    }

    private function targets(?string $runningDigest, string $key = 'ssh-compose'): DeployTargetRegistry
    {
        $target = new class($runningDigest) implements DeployTargetInterface {
            public function __construct(private ?string $digest) {}

            public function status(EnvironmentName $env): TargetStatus
            {
                return new TargetStatus(ActiveColor::Green, $this->digest ?? '', 'ok', new \DateTimeImmutable());
            }

            public function plan(\Vortos\Deploy\Plan\DeployContext $context): \Vortos\Deploy\Plan\DeployPlan
            {
                throw new \LogicException('not used');
            }

            public function assertImageAvailable(\Vortos\Deploy\Registry\ImageReference $image): void {}

            public function migrate(\Vortos\Deploy\Plan\DeployPlan $plan): void {}

            public function release(\Vortos\Deploy\Plan\DeployPlan $plan, EnvironmentName $env): TargetStatus
            {
                throw new \LogicException('not used');
            }

            public function rollback(\Vortos\Deploy\Plan\DeployPlan $plan, EnvironmentName $env, ?BuildManifest $targetManifest = null): TargetStatus
            {
                throw new \LogicException('not used');
            }

            public function capabilities(): \Vortos\OpsKit\Driver\Capability\CapabilityDescriptor
            {
                return \Vortos\OpsKit\Driver\Capability\CapabilityDescriptor::create([]);
            }
        };

        $locator = new class([$key => $target]) implements ContainerInterface {
            /** @param array<string, object> $items */
            public function __construct(private array $items) {}

            public function get(string $id): mixed
            {
                return $this->items[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->items[$id]);
            }
        };

        return new DeployTargetRegistry($locator);
    }

    private function recorded(string $digest): CurrentRelease
    {
        return new CurrentRelease(
            env: 'production',
            activeColor: ActiveColor::Green,
            imageDigest: $digest,
            buildId: 'b0',
            planHash: 'p0',
            recordedAt: new \DateTimeImmutable(),
            generation: 72,
        );
    }

    public function test_it_fails_when_the_record_claims_an_image_that_is_not_running(): void
    {
        $check = new ReleaseRecordTruthfulnessCheck(
            $this->releases($this->recorded(self::RECORDED)),
            $this->targets(self::RUNNING),
        );

        $finding = $check->check($this->context());

        self::assertSame(PreflightStatus::Fail, $finding->status);
        self::assertStringContainsString('not match', $finding->summary);
        self::assertStringContainsString(self::RECORDED, $finding->detail);
        self::assertStringContainsString(self::RUNNING, $finding->detail);
    }

    public function test_it_passes_when_the_record_matches_reality(): void
    {
        $check = new ReleaseRecordTruthfulnessCheck(
            $this->releases($this->recorded(self::RUNNING)),
            $this->targets(self::RUNNING),
        );

        self::assertSame(PreflightStatus::Pass, $check->check($this->context())->status);
    }

    public function test_a_first_deploy_has_nothing_to_disagree_with(): void
    {
        $check = new ReleaseRecordTruthfulnessCheck($this->releases(null), $this->targets(self::RUNNING));

        self::assertSame(PreflightStatus::Pass, $check->check($this->context())->status);
    }

    /**
     * An absence of information is not a mismatch. Manufacturing a failure from "the target could
     * not tell us" would block deploys for a reason other gates already cover properly.
     */
    public function test_it_skips_when_the_target_cannot_report_a_running_digest(): void
    {
        $check = new ReleaseRecordTruthfulnessCheck(
            $this->releases($this->recorded(self::RECORDED)),
            $this->targets(null),
        );

        self::assertSame(PreflightStatus::Skip, $check->check($this->context())->status);
    }

    public function test_it_skips_when_the_target_driver_is_not_registered(): void
    {
        $check = new ReleaseRecordTruthfulnessCheck(
            $this->releases($this->recorded(self::RECORDED)),
            $this->targets(self::RUNNING, key: 'some-other-driver'),
        );

        self::assertSame(PreflightStatus::Skip, $check->check($this->context())->status);
    }
}
