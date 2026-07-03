<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Preflight;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Canary\CanaryAnalyzerInterface;
use Vortos\Deploy\Canary\CanaryAnalyzerRegistry;
use Vortos\Deploy\Canary\Driver\NullCanaryAnalyzer;
use Vortos\Deploy\Cutover\EdgeRouterCapability;
use Vortos\Deploy\Cutover\EdgeRouterInterface;
use Vortos\Deploy\Cutover\EdgeRouterRegistry;
use Vortos\Deploy\Definition\DeploymentDefinition;
use Vortos\Deploy\Plan\CurrentDeployState;
use Vortos\Deploy\Preflight\Check\CanaryAnalyzerReadyCheck;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightStatus;
use Vortos\Deploy\Strategy\DeployStrategy;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Schema\SchemaFingerprint;

final class CanaryAnalyzerReadyCheckTest extends TestCase
{
    private function makeDefinition(
        DeployStrategy $strategy = DeployStrategy::Canary,
        string $canaryAnalyzer = 'slo-prometheus',
        string $edgeRouter = 'caddy',
    ): DeploymentDefinition {
        return DeploymentDefinition::build(
            strategy: $strategy,
            canaryAnalyzer: $canaryAnalyzer,
            edgeRouter: $edgeRouter,
        );
    }

    private function makeContext(DeploymentDefinition $def): PreflightContext
    {
        return new PreflightContext(
            definition: $def,
            desiredManifest: new BuildManifest(
                buildId: 'build-1',
                gitSha: 'abc1234',
                imageRepository: 'ghcr.io/acme/app',
                imageDigest: 'sha256:' . str_repeat('a', 64),
                targetArch: Arch::Arm64,
                environment: 'production',
                schemaFingerprint: new SchemaFingerprint([]),
                createdAt: new \DateTimeImmutable('2026-01-01'),
            ),
            currentState: new CurrentDeployState(ActiveColor::Blue, 'sha256:' . str_repeat('a', 64), new SchemaFingerprint([])),
            environment: new \Vortos\Deploy\Definition\EnvironmentName('production'),
        );
    }

    private function makeRouterRegistry(bool $supportsWeighted): EdgeRouterRegistry
    {
        $router = new class($supportsWeighted) implements EdgeRouterInterface {
            public function __construct(private bool $weighted) {}

            public function capabilities(): CapabilityDescriptor
            {
                return CapabilityDescriptor::create([
                    EdgeRouterCapability::WeightedUpstreams->value => $this->weighted,
                ]);
            }

            public function cutover(\Vortos\Deploy\Cutover\DesiredRoute $d): \Vortos\Deploy\Cutover\CutoverResult { throw new \LogicException(); }
            public function liveRoute(): ?\Vortos\Deploy\Cutover\LiveRoute { return null; }
            public function reconcile(\Vortos\Deploy\Cutover\DesiredRoute $d): \Vortos\Deploy\Cutover\ReconcileResult { throw new \LogicException(); }
        };

        $locator = new \Vortos\Deploy\Tests\Fixtures\InMemoryServiceLocator(['caddy' => $router]);

        return new EdgeRouterRegistry($locator);
    }

    private function makeAnalyzerRegistry(?CanaryAnalyzerInterface $analyzer = null, string $key = 'slo-prometheus'): CanaryAnalyzerRegistry
    {
        if ($analyzer === null) {
            $analyzer = new class implements CanaryAnalyzerInterface {
                public function capabilities(): CapabilityDescriptor { return CapabilityDescriptor::create([]); }
                public function analyze(\Vortos\Deploy\Canary\CanaryAnalysisRequest $r): \Vortos\Deploy\Canary\CanaryVerdict { throw new \LogicException(); }
            };
        }

        $locator = new \Vortos\Deploy\Tests\Fixtures\InMemoryServiceLocator([$key => $analyzer]);

        return new CanaryAnalyzerRegistry($locator);
    }

    public function test_non_canary_strategy_is_skipped(): void
    {
        $check = new CanaryAnalyzerReadyCheck(
            $this->makeAnalyzerRegistry(),
            $this->makeRouterRegistry(true),
        );

        $finding = $check->check($this->makeContext($this->makeDefinition(DeployStrategy::BlueGreen)));

        self::assertSame(PreflightStatus::Skip, $finding->status);
    }

    public function test_canary_with_null_analyzer_key_fails(): void
    {
        $check = new CanaryAnalyzerReadyCheck(
            $this->makeAnalyzerRegistry(new NullCanaryAnalyzer(), 'null'),
            $this->makeRouterRegistry(true),
        );

        $finding = $check->check($this->makeContext($this->makeDefinition(canaryAnalyzer: 'null')));

        self::assertSame(PreflightStatus::Fail, $finding->status);
        self::assertStringContainsString('null', strtolower($finding->summary));
    }

    public function test_canary_with_real_analyzer_and_weighted_router_passes(): void
    {
        $check = new CanaryAnalyzerReadyCheck(
            $this->makeAnalyzerRegistry(),
            $this->makeRouterRegistry(true),
        );

        $finding = $check->check($this->makeContext($this->makeDefinition()));

        self::assertSame(PreflightStatus::Pass, $finding->status);
    }

    public function test_canary_with_unregistered_analyzer_fails(): void
    {
        $check = new CanaryAnalyzerReadyCheck(
            $this->makeAnalyzerRegistry(key: 'other'),
            $this->makeRouterRegistry(true),
        );

        $finding = $check->check($this->makeContext($this->makeDefinition(canaryAnalyzer: 'slo-prometheus')));

        self::assertSame(PreflightStatus::Fail, $finding->status);
    }
}
