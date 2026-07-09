<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Preflight\Check;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Cutover\Drift\EdgeDriftDetector;
use Vortos\Deploy\Cutover\Edge\LiveConfigReaderInterface;
use Vortos\Deploy\Cutover\State\EdgeState;
use Vortos\Deploy\Cutover\State\EdgeStateStoreInterface;
use Vortos\Deploy\Definition\DeploymentDefinition;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Plan\CurrentDeployState;
use Vortos\Deploy\Preflight\Check\EdgeDriftCheck;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightStatus;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Schema\SchemaFingerprint;

final class EdgeDriftCheckTest extends TestCase
{
    private function store(?EdgeState $state): EdgeStateStoreInterface
    {
        return new class($state) implements EdgeStateStoreInterface {
            public function __construct(private readonly ?EdgeState $state) {}

            public function load(string $env): ?EdgeState
            {
                return $this->state;
            }

            public function save(EdgeState $state): EdgeState
            {
                return $state;
            }
        };
    }

    /** @param array<string,mixed> $live */
    private function live(array $live): LiveConfigReaderInterface
    {
        return new class($live) implements LiveConfigReaderInterface {
            public function __construct(private readonly array $live) {}

            public function currentConfig(): array
            {
                return $this->live;
            }
        };
    }

    private function context(): PreflightContext
    {
        $manifest = new BuildManifest(
            buildId: 'b1',
            gitSha: str_repeat('a', 40),
            imageRepository: 'ghcr.io/acme/app',
            imageDigest: 'sha256:' . str_repeat('ab', 32),
            targetArch: Arch::Arm64,
            environment: 'production',
            schemaFingerprint: SchemaFingerprint::empty(),
            createdAt: new \DateTimeImmutable(),
        );
        $state = new CurrentDeployState(
            activeColor: ActiveColor::Green,
            currentDigest: 'sha256:' . str_repeat('ab', 32),
            appliedFingerprint: SchemaFingerprint::empty(),
        );

        return new PreflightContext(DeploymentDefinition::build(), $manifest, $state, new EnvironmentName('production'));
    }

    private function edgeState(): EdgeState
    {
        return new EdgeState('production', ActiveColor::Green, 'app-green', 8080);
    }

    /** @return array<string,mixed> */
    private function liveWithDial(string $dial): array
    {
        return ['apps' => ['http' => ['servers' => ['app' => ['routes' => [[
            'handle' => [['handler' => 'reverse_proxy', 'upstreams' => [['dial' => $dial]]]],
        ]]]]]]];
    }

    public function testSkipsWithoutState(): void
    {
        $detector = new EdgeDriftDetector($this->store(null), $this->live([]));
        $finding = (new EdgeDriftCheck($detector))->check($this->context());

        self::assertSame(PreflightStatus::Skip, $finding->status);
    }

    public function testPassesInSync(): void
    {
        $detector = new EdgeDriftDetector($this->store($this->edgeState()), $this->live($this->liveWithDial('app-green:8080')));
        $finding = (new EdgeDriftCheck($detector))->check($this->context());

        self::assertSame(PreflightStatus::Pass, $finding->status, $finding->detail);
    }

    public function testFailsOnDrift(): void
    {
        $detector = new EdgeDriftDetector($this->store($this->edgeState()), $this->live($this->liveWithDial('app-blue:8080')));
        $finding = (new EdgeDriftCheck($detector))->check($this->context());

        self::assertSame(PreflightStatus::Fail, $finding->status);
        self::assertStringContainsString('drifted', $finding->summary);
    }
}
